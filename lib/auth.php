<?php
declare(strict_types=1);

function auth_cookie_name(): string {
    return config()['session_cookie'] ?? 'shortly_sess';
}

function auth_create_session(int $userId): array {
    $token   = bin2hex(random_bytes(32));
    $now     = now_ms();
    $expires = $now + (config()['session_ttl'] ?? 30 * 24 * 60 * 60) * 1000;
    db_insert(
        'INSERT INTO sessions (token, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)',
        [$token, $userId, $now, $expires]
    );
    return ['token' => $token, 'expires' => $expires];
}

function auth_destroy_session(?string $token): void {
    // PDO is injection-safe regardless, but reject obviously invalid tokens
    // up front so a 1 MB cookie value doesn't trigger a DB roundtrip.
    if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) return;
    db_run('DELETE FROM sessions WHERE token = ?', [$token]);
}

function auth_cookie_path(): string {
    return base_path() ?: '/';
}

function auth_cookie_opts(int $expiresSec): array {
    $opts = [
        'expires'  => $expiresSec,
        'path'     => auth_cookie_path(),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_request_https(),
    ];
    $domain = config()['cookie_domain'] ?? null;
    if (is_string($domain) && $domain !== '') {
        $opts['domain'] = $domain;
    }
    return $opts;
}

// True if the request reached us over HTTPS — either directly (HTTPS=on) or
// via a trusted reverse proxy that terminated TLS (X-Forwarded-Proto: https).
// The proxy branch matters on shared hosting where TLS is terminated upstream:
// without it, cookies lose their Secure flag and get re-sent over plaintext
// if the user ever visits the http:// version of the site.
function is_request_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty(config()['trust_proxy'])) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') return true;
    }
    return false;
}

function auth_set_cookie(string $token, int $expires): void {
    setcookie(auth_cookie_name(), $token, auth_cookie_opts(intdiv($expires, 1000)));
}

function auth_clear_cookie(): void {
    setcookie(auth_cookie_name(), '', auth_cookie_opts(time() - 3600));
}

function auth_token_from_cookie(): ?string {
    return $_COOKIE[auth_cookie_name()] ?? null;
}

function auth_current_user(): ?array {
    static $cached = false, $user = null;
    if ($cached) return $user;
    $cached = true;

    // Try API key (Bearer header) before session cookie. apikey_authenticate
    // enforces the per-key rate limit internally — exceeding it 429s the
    // whole request. Internal-stats endpoint also uses Bearer; that's gated
    // by hash_equals against config secret BEFORE this function runs in its
    // handler, so it doesn't hit this path.
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== '' && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        $bearer = $m[1];
        if (function_exists('apikey_is_valid_format') && apikey_is_valid_format($bearer)) {
            $u = apikey_authenticate($bearer);
            if ($u) return $user = $u;
        }
    }

    $token = auth_token_from_cookie();
    // Session tokens are bin2hex(random_bytes(32)) → 64 hex chars. Reject
    // anything else without a DB roundtrip.
    if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) return $user = auth_thern_sso();

    $row = db_get(
        'SELECT s.token, s.user_id, s.expires_at, u.email, u.name, u.tier
         FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE s.token = ? AND s.expires_at > ?',
        [$token, now_ms()]
    );
    if (!$row) return $user = auth_thern_sso();
    return $user = [
        'id'    => (int) $row['user_id'],
        'email' => $row['email'],
        'name'  => $row['name'] ?? null,
        'tier'  => $row['tier'] ?? 'free',
        'token' => $row['token'],
    ];
}

// Optional cross-domain SSO: when `sso_whoami_url` is configured, ask the
// upstream over HTTPS who the bearer of the SSO cookie is logged in as. The
// cookie is forwarded server-to-server; the upstream returns JSON describing
// the member, and we auto-create a local user for them on first sight.
//
// This is OFF by default. Leave `sso_whoami_url` empty (or unset) in
// config.php to disable. When enabled, the upstream must return JSON of
// the form `{"ok": true, "member": {"id": <int>, "email": "<addr>"}}` for
// signed-in members and either `ok: false` or HTTP non-200 otherwise.
function auth_thern_sso(): ?array {
    $cfg = config();
    $url = (string) ($cfg['sso_whoami_url'] ?? '');
    if ($url === '') return null;

    $cookieName = (string) ($cfg['sso_cookie_name'] ?? 'thern_sess');
    $cookie = $_COOKIE[$cookieName] ?? '';
    if ($cookie === '' || !preg_match('/^[A-Za-z0-9,\-]{16,128}$/', $cookie)) return null;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Cookie: ' . $cookieName . '=' . $cookie,
            'Accept: application/json',
        ],
        CURLOPT_USERAGENT      => 'shortly-sso/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !is_string($body)) return null;
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['ok']) || empty($data['member'])) return null;

    $memberId    = (int) ($data['member']['id'] ?? 0);
    $memberEmail = strtolower(trim((string) ($data['member']['email'] ?? '')));
    if (!$memberId || !$memberEmail || !filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) return null;

    // Find or auto-create a shortly user for this thern.io member.
    // SSO-only accounts get a sentinel password_hash so password_verify() can
    // never accidentally succeed against them, even if the comparison ever
    // regresses (defense in depth — verify() rejects non-bcrypt strings today).
    $user = db_get('SELECT id, email, tier FROM users WHERE email = ?', [$memberEmail]);
    if (!$user) {
        try {
            $id   = db_insert(
                'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
                [$memberEmail, '!sso:' . bin2hex(random_bytes(8)), now_ms()]
            );
            $user = ['id' => $id, 'email' => $memberEmail, 'tier' => 'free'];
        } catch (PDOException $e) {
            // Race: a parallel SSO request inserted the same email between
            // our SELECT and INSERT. Re-fetch the row the winner just wrote
            // (SQLSTATE 23000 = UNIQUE-violation, same on SQLite + MySQL).
            if ($e->getCode() !== '23000') throw $e;
            $user = db_get('SELECT id, email, tier FROM users WHERE email = ?', [$memberEmail]);
            if (!$user) return null;  // shouldn't happen, but bail safely
        }
    }

    // Reuse a still-valid session if one exists — otherwise every request
    // without a local cookie (different tab, race, lost cookie) inserts a new
    // session row and the table grows unbounded.
    $existing = db_get(
        'SELECT token, expires_at FROM sessions
         WHERE user_id = ? AND expires_at > ?
         ORDER BY expires_at DESC LIMIT 1',
        [(int) $user['id'], now_ms()]
    );
    if ($existing) {
        $token = $existing['token'];
        $expires = (int) $existing['expires_at'];
    } else {
        $sess = auth_create_session((int) $user['id']);
        $token = $sess['token'];
        $expires = $sess['expires'];
    }
    auth_set_cookie($token, $expires);

    return [
        'id'    => (int) $user['id'],
        'email' => $user['email'],
        'tier'  => $user['tier'] ?? 'free',
        'token' => $token,
    ];
}

function auth_require(): array {
    $u = auth_current_user();
    if (!$u) json_error('unauthorized', 401);
    return $u;
}

function auth_clean_expired_sessions(): void {
    // Probabilistic throttle: ~1 in 1000 requests runs the sweep. Old impl
    // (time() % 3600 < 5) caused thundering-herd deletes for every request
    // inside the same 5-second window each hour.
    if (random_int(1, 1000) === 1) {
        $now = now_ms();
        db_run('DELETE FROM sessions               WHERE expires_at <= ?', [$now]);
        // rate_limits rows are tiny but accumulate one per unique IP/slug —
        // sweep anything older than a day to keep the table bounded.
        db_run('DELETE FROM rate_limits            WHERE window_start < ?', [time() - 86400]);
        // Pending registrations: 15-min TTL, sweep stale rows.
        db_run('DELETE FROM pending_registrations  WHERE expires_at <= ?', [$now]);
        // Anonymous tier links auto-delete after their 30-min TTL. Cascade
        // their click rows first (no FK constraints on the schema) — order
        // matters to avoid orphans if the second DELETE ever fails.
        db_run(
            'DELETE FROM clicks WHERE link_id IN
                (SELECT id FROM links WHERE user_id IS NULL AND expires_at <= ?)',
            [$now]
        );
        db_run('DELETE FROM links WHERE user_id IS NULL AND expires_at <= ?', [$now]);
    }
}
