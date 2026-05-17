<?php
declare(strict_types=1);

// Bump this every time db_migrate() gains a new CREATE TABLE / ALTER /
// CREATE INDEX. SQLite tracks the value in `PRAGMA user_version` and we
// skip the entire migrate body on subsequent requests when it matches —
// turning ~40 idempotent DDL statements (and a fistful of try/catch
// exception unwinds for already-applied ALTERs) into one PRAGMA read on
// the hot path. Forgetting to bump it just means a new column waits
// until next deploy of the DB host — safe, not silent corruption.
const SHORTLY_SCHEMA_VERSION = 1;

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // util.php's config() already cached the config — reuse it instead of
    // re-require:ing the file.
    $db = config()['db'];

    if ($db['driver'] === 'sqlite') {
        $path = $db['path'] ?? __DIR__ . '/../data/shortly.db';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        // Without this, a concurrent writer mid-transaction makes the
        // next request 500 with "database is locked". 5s window lets
        // SQLite retry the lock acquisition transparently — well above
        // typical write latency for our row sizes.
        $pdo->exec('PRAGMA busy_timeout = 5000');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'], $db['port'] ?? 3306, $db['database'], $db['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    db_migrate($pdo, $db['driver']);
    return $pdo;
}

function db_migrate(PDO $pdo, string $driver): void {
    // Skip the whole migrate body if SQLite already advertises the
    // current schema version. We re-check the version *after* running
    // (not via a cached static) so that a manual DB swap or a fresh
    // file is still picked up on the next request.
    if ($driver === 'sqlite') {
        $cur = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($cur === SHORTLY_SCHEMA_VERSION) return;
    }

    $autoinc = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $bigint  = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
    $text    = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(2048)';
    $email   = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
    $hash    = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
    $slug    = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
    $tok     = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
    $name    = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(100)';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            $autoinc,
            email         $email NOT NULL UNIQUE,
            password_hash $hash NOT NULL,
            created_at    $bigint NOT NULL
        )
    ");
    // Add name column if missing — idempotent ALTER swallowed if it exists.
    try { $pdo->exec("ALTER TABLE users ADD COLUMN name $name NULL"); } catch (PDOException $e) {}
    // Tier column for the membership system. New rows default to 'free';
    // existing rows inherit the default. Anonymous visitors don't have a
    // users row at all — their effective tier is computed in tier_of().
    // Use TEXT with app-level validation rather than ENUM so SQLite + MySQL
    // share the same migration; the constraint is enforced by tier_of()
    // and require_tier() callers.
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tier TEXT NOT NULL DEFAULT 'free'"); } catch (PDOException $e) {}
    // expires_auto = 1 means the expiry was set automatically (10-day
    // free-tier retention) and should ROLL forward on every click. = 0
    // means the user explicitly chose the expiry — leave it alone.
    $bool = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT UNSIGNED';
    try { $pdo->exec("ALTER TABLE links ADD COLUMN expires_auto $bool NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
    // Suspended state — set when a link has been quarantined for abuse.
    // Redirect/unlock both 451 with this populated. NULL = not suspended.
    $abuseReason = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(80)';
    try { $pdo->exec("ALTER TABLE links ADD COLUMN suspended_at $bigint NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE links ADD COLUMN suspended_reason $abuseReason NULL"); } catch (PDOException $e) {}
    // sha256 of the target URL — used for per-target frequency caps and as
    // a join key into the safebrowsing_cache table.
    $hashCol = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
    try { $pdo->exec("ALTER TABLE links ADD COLUMN target_hash $hashCol NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_links_target_created ON links(target_hash, created_at)"); } catch (PDOException $e) {}
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            token       $tok PRIMARY KEY,
            user_id     $bigint NOT NULL,
            created_at  $bigint NOT NULL,
            expires_at  $bigint NOT NULL
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS links (
            id            $autoinc,
            slug          $slug NOT NULL UNIQUE,
            target        $text NOT NULL,
            user_id       $bigint NULL,
            password_hash $hash NULL,
            expires_at    $bigint NULL,
            created_at    $bigint NOT NULL
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clicks (
            id          $autoinc,
            link_id     $bigint NOT NULL,
            ts          $bigint NOT NULL,
            referrer    $text NULL,
            user_agent  $text NULL,
            ip_hash     " . ($driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)') . " NULL
        )
    ");
    // Device type for Pro-tier device-breakdown analytics. Parsed from UA at
    // record time so historical clicks (where device_type is NULL) just don't
    // show up in the breakdown — no backfill needed.
    $deviceType = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(20)';
    try { $pdo->exec("ALTER TABLE clicks ADD COLUMN device_type $deviceType NULL"); } catch (PDOException $e) {}
    // ISO 3166-1 alpha-2 country code resolved at click time via lib/geo.php.
    // 2-letter strings; historical rows stay NULL (no backfill — raw IPs are
    // discarded after hashing so we can't recover them).
    $country = $driver === 'sqlite' ? 'TEXT' : 'CHAR(2)';
    try { $pdo->exec("ALTER TABLE clicks ADD COLUMN country $country NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clicks_country ON clicks(country)"); } catch (PDOException $e) {}
    $rlKey = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(190)';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            key_name      $rlKey PRIMARY KEY,
            count         $bigint NOT NULL,
            window_start  $bigint NOT NULL
        )
    ");
    // Pending email-verification registrations. One row per email; replaces
    // any earlier pending row when the user re-submits /api/auth/register.
    $attempts = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_registrations (
            email         $email PRIMARY KEY,
            name          $name NOT NULL,
            password_hash $hash NOT NULL,
            code_hash     $hash NOT NULL,
            created_at    $bigint NOT NULL,
            expires_at    $bigint NOT NULL,
            attempts      $attempts NOT NULL DEFAULT 0
        )
    ");
    // Tags + link_tags for free-tier organisation. Per-user tag namespace —
    // 'work' for two users are distinct rows. Color is a 7-char hex.
    $tagName  = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(50)';
    $tagColor = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(16)';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tags (
            id          $autoinc,
            user_id     $bigint NOT NULL,
            name        $tagName NOT NULL,
            color       $tagColor NULL,
            created_at  $bigint NOT NULL
        )
    ");
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tags_user_name ON tags(user_id, name)"); } catch (PDOException $e) {}
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS link_tags (
            link_id $bigint NOT NULL,
            tag_id  $bigint NOT NULL,
            PRIMARY KEY (link_id, tag_id)
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_link_tags_tag ON link_tags(tag_id)"); } catch (PDOException $e) {}
    // Link-in-bio pages. user_id is a regular column (not PK) so Pro can
    // later have multiple pages per user without a schema rewrite. Slug is
    // namespaced to /u/{slug} — distinct from the link-redirect /{slug}
    // namespace so no collision possible.
    $bioTitle = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(100)';
    $bioTheme = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(20)';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bio_pages (
            id          $autoinc,
            user_id     $bigint NOT NULL,
            slug        $slug NOT NULL UNIQUE,
            title       $bioTitle NULL,
            theme       $bioTheme NOT NULL DEFAULT 'light',
            links_json  $text NULL,
            created_at  $bigint NOT NULL,
            updated_at  $bigint NOT NULL
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bio_user ON bio_pages(user_id)"); } catch (PDOException $e) {}
    // API keys. key_hash stores sha256(plaintext); the plaintext is shown
    // once at creation time and never persisted. revoked_at != NULL means
    // the key is dead — kept around for audit rather than hard-deleted.
    $keyLabel = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(100)';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS api_keys (
            id           $autoinc,
            user_id      $bigint NOT NULL,
            key_hash     $hash NOT NULL UNIQUE,
            key_prefix   $tok NOT NULL,
            label        $keyLabel NULL,
            created_at   $bigint NOT NULL,
            last_used_at $bigint NULL,
            revoked_at   $bigint NULL
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_keys_user ON api_keys(user_id)"); } catch (PDOException $e) {}
    // Abuse reports against shortened links. Auto-suspend triggers when
    // 3 distinct reporter IP-hashes report the same link.
    $abuseReason2 = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(40)';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS abuse_reports (
            id                $autoinc,
            link_id           $bigint NOT NULL,
            reason            $abuseReason2 NOT NULL,
            detail            $text NULL,
            reporter_ip_hash  " . ($driver === 'sqlite' ? 'TEXT' : 'VARCHAR(64)') . " NULL,
            created_at        $bigint NOT NULL
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_abuse_link ON abuse_reports(link_id)"); } catch (PDOException $e) {}
    // Google Safe Browsing verdict cache. Each verdict is a single check
    // against api.google.com — caching for 24h keeps us under the 10k/day
    // free-tier quota even at high create rates.
    $verdict = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(20)';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS safebrowsing_cache (
            target_hash  $hashCol PRIMARY KEY,
            verdict      $verdict NOT NULL,
            checked_at   $bigint NOT NULL
        )
    ");
    // Stripe customer id on users — populated on first successful checkout
    // and reused for billing portal sessions thereafter.
    $stripeId = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(80)';
    try { $pdo->exec("ALTER TABLE users ADD COLUMN stripe_customer_id $stripeId NULL"); } catch (PDOException $e) {}
    // PayPal subscription id on users — same role as stripe_customer_id but
    // PayPal doesn't have a separate "customer" concept; the subscription id
    // IS the durable handle we use for portal/cancel calls.
    try { $pdo->exec("ALTER TABLE users ADD COLUMN paypal_subscription_id $stripeId NULL"); } catch (PDOException $e) {}
    // One subscription row per user (MVP). users.tier is the source of
    // access truth — this table is the audit log that backs the management UI.
    $subStatus = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(40)';
    $subPlan   = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(40)';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subscriptions (
            user_id                 $bigint PRIMARY KEY,
            stripe_subscription_id  $stripeId NOT NULL UNIQUE,
            status                  $subStatus NOT NULL,
            plan                    $subPlan NULL,
            current_period_end      $bigint NULL,
            cancel_at_period_end    $bool NOT NULL DEFAULT 0,
            created_at              $bigint NOT NULL,
            updated_at              $bigint NOT NULL
        )
    ");
    // Provider column — 'stripe' for existing rows, 'paypal' for ones
    // created by lib/paypal.php. The stripe_subscription_id column also
    // doubles as the paypal subscription id when provider='paypal'; the
    // column name is historical and lifting it costs more than carrying it.
    try { $pdo->exec("ALTER TABLE subscriptions ADD COLUMN provider TEXT NOT NULL DEFAULT 'stripe'"); } catch (PDOException $e) {}

    // Password reset tokens. token_hash is sha256(token + salt) — we never
    // store the raw token, only its hash, so a DB leak doesn't grant resets.
    // One row per request; used_at != NULL marks it consumed so the link in
    // the email is one-shot.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id          $autoinc,
            user_id     $bigint NOT NULL,
            token_hash  $hash NOT NULL UNIQUE,
            created_at  $bigint NOT NULL,
            expires_at  $bigint NOT NULL,
            used_at     $bigint NULL
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id)"); } catch (PDOException $e) {}

    // Indexes (CREATE INDEX IF NOT EXISTS works on both engines from MySQL 8 / MariaDB 10.3+).
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_links_user ON links(user_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clicks_link ON clicks(link_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clicks_ts ON clicks(ts)"); } catch (PDOException $e) {}

    // Stamp the version last — if a CREATE/ALTER above threw an unexpected
    // exception we want the next request to retry, not skip past it.
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA user_version = ' . (int) SHORTLY_SCHEMA_VERSION);
    }
}

// ---------- shorthand helpers ----------
function db_get(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}
function db_all(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
function db_run(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
function db_insert(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) db()->lastInsertId();
}
