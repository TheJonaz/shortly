<?php
declare(strict_types=1);

// Anonymous links live for 30 minutes. The sweep in
// auth_clean_expired_sessions deletes them past that point; redirect_slug
// already 410s on expiry so the window is enforced even before sweep runs.
const ANON_LINK_TTL_MS = 30 * 60 * 1000;

// Free-tier rolling retention: links auto-expire after this much idle time,
// but every click pushes the expiry forward. Pro tier is exempt.
const FREE_LINK_TTL_MS = 10 * 24 * 60 * 60 * 1000;

// Per-target frequency cap. The same URL shortened more than this many
// times within the window is treated as bot-driven mass creation. Real
// users essentially never re-shorten identical URLs.
const TARGET_FREQUENCY_MAX    = 30;
const TARGET_FREQUENCY_WINDOW_MS = 60 * 60 * 1000;

// Reject targets that point back at this very deployment. Without this,
// shortening <public_url>/<other-slug> creates a redirect chain (or, with a
// self-loop slug, a 302 → 302 → 302 cycle that browsers eventually break).
// Compares host case-insensitively; if a base_path is configured the target
// path must also start with it to count as self.
function assert_not_self_target(string $target): void {
    $cfg     = config();
    $selfUrl = (string) ($cfg['public_url'] ?? '');
    if ($selfUrl === '') return;
    $self = parse_url($selfUrl);
    $t    = parse_url($target);
    if (!$self || !$t || empty($self['host']) || empty($t['host'])) return;
    if (strtolower($self['host']) !== strtolower($t['host'])) return;
    $basePath = rtrim((string) ($cfg['base_path'] ?? ''), '/');
    $tPath    = $t['path'] ?? '/';
    if ($basePath === '' || strpos($tPath, $basePath . '/') === 0 || $tPath === $basePath) {
        throw new InvalidArgumentException('target_is_self');
    }
}

function links_create(array $body, ?int $userId): array {
    if (empty($body['target']) || !is_string($body['target'])) {
        throw new InvalidArgumentException('target_required');
    }
    $target = validate_url($body['target']);
    assert_not_self_target($target);

    // Domain blocklist — reject known phishing/malware hosts. The list is
    // refreshed by deploy/update-blocklist.sh from URLhaus. Missing list
    // file = no blocking (graceful degradation if cron didn't run yet).
    if (function_exists('blocklist_contains_url') && blocklist_contains_url($target) === true) {
        throw new InvalidArgumentException('url_blocked');
    }

    // Google Safe Browsing — second-opinion check that catches threats the
    // local list missed. No-ops when the API key isn't configured. Cached
    // for 24h per URL hash.
    if (function_exists('safebrowsing_is_clean') && !safebrowsing_is_clean($target)) {
        throw new InvalidArgumentException('url_blocked_threat');
    }

    // Per-target frequency cap — the same URL shortened > 30 times in an
    // hour is bot-driven mass creation regardless of who's submitting.
    // Skip when we somehow lack a target_hash column (very old DB) — the
    // count query would still work but with NULL-everywhere.
    $targetHash = hash('sha256', $target);
    $cutoff = now_ms() - TARGET_FREQUENCY_WINDOW_MS;
    $existingCount = (int) (db_get(
        'SELECT COUNT(*) AS n FROM links WHERE target_hash = ? AND created_at >= ?',
        [$targetHash, $cutoff]
    )['n'] ?? 0);
    if ($existingCount >= TARGET_FREQUENCY_MAX) {
        throw new InvalidArgumentException('target_too_frequent');
    }

    $isAnon       = $userId === null;
    $expiresAuto  = 0;
    $userTier     = null;

    if ($isAnon) {
        // Anonymous tier: random slug only, no password, hard 30-min expiry.
        // Anything else gets an explicit "account_required" so the frontend
        // can prompt for sign-up rather than silently downgrade the request.
        if (!empty($body['slug']))       throw new InvalidArgumentException('slug_requires_account');
        if (!empty($body['password']))   throw new InvalidArgumentException('password_requires_account');
        if (!empty($body['expires_at'])) throw new InvalidArgumentException('expiry_requires_account');
        $slug         = pick_fresh_slug();
        $passwordHash = null;
        $expiresAt    = now_ms() + ANON_LINK_TTL_MS;
        $expiresAuto  = 1;
    } else {
        $slug = $body['slug'] ?? null;
        if (is_string($slug) && $slug !== '') {
            if (!is_valid_slug($slug)) throw new InvalidArgumentException('invalid_slug');
            // Normalise to lowercase so the slug works identically on SQLite
            // (BINARY collation, case-sensitive lookups) and MySQL
            // (utf8mb4_general_ci, case-insensitive uniqueness). Without this,
            // 'Foo' on SQLite resolves only at /Foo, but on MySQL it'd collide
            // with anyone trying to register 'foo' later.
            $slug = strtolower($slug);
            if (in_array($slug, RESERVED_SLUGS, true)) {
                throw new InvalidArgumentException('slug_reserved');
            }
            if (db_get('SELECT 1 AS x FROM links WHERE slug = ?', [$slug])) {
                throw new InvalidArgumentException('slug_taken');
            }
        } else {
            $slug = pick_fresh_slug();
        }

        // Password protection + custom expiry are Pro-tier per the
        // membership spec. Free tier silently gets the rolling 10-day
        // auto-expiry instead. Querying tier here once and gating both
        // features in one place keeps the logic in step.
        $userRow = db_get('SELECT tier FROM users WHERE id = ?', [$userId]);
        $userTier = $userRow['tier'] ?? 'free';

        $passwordHash = null;
        if (!empty($body['password']) && is_string($body['password'])) {
            if ($userTier !== 'pro') {
                throw new InvalidArgumentException('password_requires_pro');
            }
            // bcrypt silently truncates input past 72 bytes — reject explicitly
            // so users don't think a longer password is more secure than it is.
            if (strlen($body['password']) > 72) {
                throw new InvalidArgumentException('password_too_long');
            }
            $passwordHash = password_hash($body['password'], PASSWORD_BCRYPT);
        }

        if (!empty($body['expires_at']) && $userTier !== 'pro') {
            throw new InvalidArgumentException('expiry_requires_pro');
        }
        $expiresAt = parse_expiry($body['expires_at'] ?? null);
        // If free user didn't pick an explicit expiry, set the rolling 10-day
        // auto-expiry. Pro tier gets no auto-expiry. Mark the source so the
        // click handler knows whether to roll it forward.
        if ($expiresAt === null && $userTier !== 'pro') {
            $expiresAt   = now_ms() + FREE_LINK_TTL_MS;
            $expiresAuto = 1;
        }
    }

    // Slug INSERT with race-retry. If the user picked the slug explicitly,
    // a UNIQUE collision is a real "slug_taken" error. If the slug was
    // auto-generated (anon, or signed-in without a custom slug), we just
    // re-roll a fresh random one and retry. Retry-cap at 5 to bound any
    // pathological case.
    $userPickedSlug = !empty($body['slug']);
    $id = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $id = db_insert(
                'INSERT INTO links (slug, target, target_hash, user_id, password_hash, expires_at, expires_auto, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$slug, $target, $targetHash, $userId, $passwordHash, $expiresAt, $expiresAuto, now_ms()]
            );
            break;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            if ($userPickedSlug) {
                throw new InvalidArgumentException('slug_taken');
            }
            // Auto-generated slug collided in the TOCTOU window between
            // pick_fresh_slug's check and our INSERT. Re-roll and retry.
            $slug = pick_fresh_slug();
        }
    }
    if ($id === null) {
        // 5 consecutive collisions on random slugs → space is full. Bump
        // generate_slug() length.
        throw new RuntimeException('slug_generation_failed');
    }

    // Tags: optional tag_ids array. Validate ownership and attach.
    if (!$isAnon && !empty($body['tag_ids']) && is_array($body['tag_ids'])) {
        links_set_tags($id, (int) $userId, $body['tag_ids']);
    }

    return [
        'id'           => $id,
        'slug'         => $slug,
        'target'       => $target,
        'short_url'    => public_url() . '/' . $slug,
        'expires_at'   => $expiresAt,
        'has_password' => $passwordHash !== null,
    ];
}

// Patch an existing link. Pro-only — caller must require_tier('pro').
// Each field is optional; missing keys leave the column untouched.
//   slug        => new slug string (validated, lowercased, must not collide)
//   target      => new URL (validated, capped, control-chars rejected)
//   expires_at  => parse_expiry-acceptable value, or null to clear
//   password    => new password (bcrypt-hashed), or null/'' to clear
// Returns the refreshed link row.
function links_update(int $linkId, int $userId, array $body): array {
    $link = db_get('SELECT * FROM links WHERE id = ? AND user_id = ?', [$linkId, $userId]);
    if (!$link) throw new InvalidArgumentException('not_found');

    $sets   = [];
    $params = [];

    if (array_key_exists('slug', $body)) {
        $newSlug = strtolower(trim((string) $body['slug']));
        if ($newSlug !== '' && $newSlug !== $link['slug']) {
            if (!is_valid_slug($newSlug)) throw new InvalidArgumentException('invalid_slug');
            if (in_array($newSlug, RESERVED_SLUGS, true)) {
                throw new InvalidArgumentException('slug_reserved');
            }
            $clash = db_get('SELECT id FROM links WHERE slug = ? AND id != ?', [$newSlug, $linkId]);
            if ($clash) throw new InvalidArgumentException('slug_taken');
            $sets[] = 'slug = ?';
            $params[] = $newSlug;
        }
    }

    if (array_key_exists('target', $body) && $body['target'] !== null && $body['target'] !== '') {
        $newTarget = validate_url((string) $body['target']);
        assert_not_self_target($newTarget);
        $sets[] = 'target = ?';
        $params[] = $newTarget;
    }

    if (array_key_exists('expires_at', $body)) {
        $val = $body['expires_at'];
        if ($val === null || $val === '') {
            // Pro user explicitly clearing expiry — also clear expires_auto
            // so the click-roll path doesn't accidentally re-set it.
            $sets[] = 'expires_at = NULL';
            $sets[] = 'expires_auto = 0';
        } else {
            $sets[] = 'expires_at = ?';
            $params[] = parse_expiry($val);
            $sets[] = 'expires_auto = 0';
        }
    }

    if (array_key_exists('password', $body)) {
        // Explicit semantics:
        //   null         → clear the password
        //   non-empty    → set / change to the new value
        //   empty string → reject as invalid (was previously equivalent to
        //                  clear, which surprised API callers passing
        //                  unsanitised user input)
        $pw = $body['password'];
        if ($pw === null) {
            $sets[] = 'password_hash = NULL';
        } elseif (is_string($pw) && $pw !== '') {
            if (strlen($pw) > 72) {
                throw new InvalidArgumentException('password_too_long');
            }
            $sets[] = 'password_hash = ?';
            $params[] = password_hash($pw, PASSWORD_BCRYPT);
        } else {
            throw new InvalidArgumentException('invalid_password');
        }
    }

    if ($sets) {
        $params[] = $linkId;
        db_run('UPDATE links SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }
    $row = db_get('SELECT * FROM links WHERE id = ?', [$linkId]);
    return [
        'id'           => (int) $row['id'],
        'slug'         => $row['slug'],
        'target'       => $row['target'],
        'created_at'   => (int) $row['created_at'],
        'expires_at'   => $row['expires_at'] !== null ? (int) $row['expires_at'] : null,
        'expires_auto' => (bool) ($row['expires_auto'] ?? 0),
        'has_password' => !empty($row['password_hash']),
        'short_url'    => public_url() . '/' . $row['slug'],
    ];
}

// Delete a link the user owns plus its click history. Returns true if a row
// was actually removed (so callers can 404). No FK constraints exist on the
// schema, hence the explicit cascade.
function links_delete(int $linkId, int $userId): bool {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = db_run('DELETE FROM links WHERE id = ? AND user_id = ?', [$linkId, $userId]);
        if ($del === 0) { $pdo->rollBack(); return false; }
        db_run('DELETE FROM clicks WHERE link_id = ?', [$linkId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function links_record_click(int $linkId): void {
    // MySQL `clicks.referrer` / `user_agent` are VARCHAR(2048); a longer
    // header would crash the INSERT in prod. Truncate well below that so
    // the column can never be the failure mode. Prefer mb_strcut when the
    // mbstring extension is available (preserves UTF-8 boundary); fall back
    // to substr — referers/UAs are overwhelmingly ASCII anyway.
    $trim = static function (?string $s): ?string {
        if ($s === null || $s === '') return null;
        return function_exists('mb_strcut')
            ? mb_strcut($s, 0, 512, 'UTF-8')
            : substr($s, 0, 512);
    };
    $now = now_ms();
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $deviceType = function_exists('device_type_from_ua')
        ? device_type_from_ua($ua)
        : null;
    db_insert(
        'INSERT INTO clicks (link_id, ts, referrer, user_agent, ip_hash, device_type) VALUES (?, ?, ?, ?, ?, ?)',
        [
            $linkId,
            $now,
            $trim($_SERVER['HTTP_REFERER'] ?? null),
            $trim($ua),
            ip_hash(client_ip()),
            $deviceType,
        ]
    );
    // Roll forward the auto-set expiry on free-tier links. Anonymous links
    // also have expires_auto=1 but their TTL (30 min) is shorter than the
    // free TTL — guard with the user_id IS NOT NULL check so anon links
    // aren't accidentally extended into 10-day territory.
    db_run(
        'UPDATE links
         SET expires_at = ?
         WHERE id = ? AND expires_auto = 1 AND user_id IS NOT NULL',
        [$now + FREE_LINK_TTL_MS, $linkId]
    );
}

// Replace the tag set on a link. Tag IDs are validated to belong to the
// caller — silently drops any that don't, preventing a bug or malicious
// caller from attaching another user's tags.
function links_set_tags(int $linkId, int $userId, array $tagIds): void {
    $clean = [];
    foreach ($tagIds as $tid) {
        if (is_int($tid) || (is_string($tid) && ctype_digit($tid))) $clean[] = (int) $tid;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('DELETE FROM link_tags WHERE link_id = ?', [$linkId]);
        if ($clean) {
            $placeholders = implode(',', array_fill(0, count($clean), '?'));
            $owned = db_all(
                "SELECT id FROM tags WHERE user_id = ? AND id IN ($placeholders)",
                array_merge([$userId], $clean)
            );
            foreach ($owned as $t) {
                db_run(
                    'INSERT INTO link_tags (link_id, tag_id) VALUES (?, ?)',
                    [$linkId, (int) $t['id']]
                );
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Returns [{id, name, color}, ...] for a single link.
function links_tags_for_link(int $linkId): array {
    return db_all(
        'SELECT t.id, t.name, t.color
         FROM link_tags lt JOIN tags t ON t.id = lt.tag_id
         WHERE lt.link_id = ?
         ORDER BY t.name',
        [$linkId]
    );
}

function links_for_user(int $userId): array {
    // Single LEFT JOIN + GROUP BY beats two correlated subqueries per row.
    // COUNT(DISTINCT c.ip_hash) gives unique-click count without a second pass.
    $rows = db_all(
        'SELECT l.id, l.slug, l.target, l.user_id, l.password_hash,
                l.expires_at, l.expires_auto, l.created_at,
                COUNT(c.id)              AS click_count,
                COUNT(DISTINCT c.ip_hash) AS unique_clicks,
                MAX(c.ts)                AS last_click
         FROM links l
         LEFT JOIN clicks c ON c.link_id = l.id
         WHERE l.user_id = ?
         GROUP BY l.id, l.slug, l.target, l.user_id, l.password_hash,
                  l.expires_at, l.expires_auto, l.created_at
         ORDER BY l.created_at DESC',
        [$userId]
    );
    if (!$rows) return [];

    // Batch-fetch tags for all listed links in one query so we don't N+1.
    $linkIds = array_map(fn($r) => (int) $r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($linkIds), '?'));
    $tagRows = db_all(
        "SELECT lt.link_id, t.id, t.name, t.color
         FROM link_tags lt JOIN tags t ON t.id = lt.tag_id
         WHERE lt.link_id IN ($placeholders)
         ORDER BY t.name",
        $linkIds
    );
    $tagsByLink = [];
    foreach ($tagRows as $tr) {
        $tagsByLink[(int) $tr['link_id']][] = [
            'id' => (int) $tr['id'], 'name' => $tr['name'], 'color' => $tr['color'],
        ];
    }

    $out = [];
    foreach ($rows as $l) {
        $id = (int) $l['id'];
        $out[] = [
            'id'             => $id,
            'slug'           => $l['slug'],
            'target'         => $l['target'],
            'created_at'     => (int) $l['created_at'],
            'expires_at'     => $l['expires_at'] !== null ? (int) $l['expires_at'] : null,
            'expires_auto'   => (bool) $l['expires_auto'],
            'has_password'   => !empty($l['password_hash']),
            'click_count'    => (int) ($l['click_count'] ?? 0),
            'unique_clicks'  => (int) ($l['unique_clicks'] ?? 0),
            'last_click'     => $l['last_click'] !== null ? (int) $l['last_click'] : null,
            'short_url'      => public_url() . '/' . $l['slug'],
            'tags'           => $tagsByLink[$id] ?? [],
        ];
    }
    return $out;
}

function links_stats(int $linkId, int $userId): ?array {
    $link = db_get('SELECT * FROM links WHERE id = ? AND user_id = ?', [$linkId, $userId]);
    if (!$link) return null;
    // Day-bucket query is dialect-specific. Pick the right one up front —
    // running the SQLite version on MySQL throws because datetime() doesn't
    // exist there, which previously bricked the whole stats endpoint in prod.
    if ((config()['db']['driver'] ?? '') === 'sqlite') {
        $byDay = db_all(
            "SELECT substr(datetime(ts/1000, 'unixepoch'), 1, 10) AS day,
                    COUNT(*) AS n
             FROM clicks WHERE link_id = ?
             GROUP BY day ORDER BY day DESC LIMIT 30",
            [$linkId]
        );
    } else {
        $byDay = db_all(
            "SELECT DATE(FROM_UNIXTIME(ts/1000)) AS day, COUNT(*) AS n
             FROM clicks WHERE link_id = ?
             GROUP BY day ORDER BY day DESC LIMIT 30",
            [$linkId]
        );
    }
    $recent = db_all(
        'SELECT ts, referrer, user_agent FROM clicks WHERE link_id = ? ORDER BY ts DESC LIMIT 200',
        [$linkId]
    );
    $totals = db_get(
        'SELECT COUNT(*) AS total, COUNT(DISTINCT ip_hash) AS uniq FROM clicks WHERE link_id = ?',
        [$linkId]
    ) ?? ['total' => 0, 'uniq' => 0];
    // Device-type breakdown for Pro analytics. NULL device_type rows
    // (legacy clicks pre-Phase-4) are folded into 'unknown' so the chart
    // doesn't lie about ratios.
    $deviceRows = db_all(
        "SELECT COALESCE(device_type, 'unknown') AS device, COUNT(*) AS n
         FROM clicks WHERE link_id = ?
         GROUP BY device",
        [$linkId]
    );
    return [
        'link' => [
            'id'           => (int) $link['id'],
            'slug'         => $link['slug'],
            'target'       => $link['target'],
            'created_at'   => (int) $link['created_at'],
            'expires_at'   => $link['expires_at'] !== null ? (int) $link['expires_at'] : null,
            'expires_auto' => (bool) ($link['expires_auto'] ?? 0),
            'has_password' => !empty($link['password_hash']),
            'short_url'    => public_url() . '/' . $link['slug'],
            'tags'         => links_tags_for_link($linkId),
        ],
        'totals' => [
            'clicks' => (int) $totals['total'],
            'unique' => (int) $totals['uniq'],
        ],
        'by_device' => array_map(fn($r) => ['device' => $r['device'], 'n' => (int) $r['n']], $deviceRows),
        'by_day' => array_map(fn($r) => ['day' => $r['day'], 'n' => (int) $r['n']], $byDay),
        'recent' => array_map(fn($r) => [
            'ts'         => (int) $r['ts'],
            'referrer'   => $r['referrer'],
            'user_agent' => $r['user_agent'],
        ], $recent),
    ];
}
