<?php
declare(strict_types=1);

// Front controller. .htaccess sends every non-file request here.

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "shortly is not configured.\n\nCopy config.example.php to config.php and edit it.";
    exit;
}

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/lang.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/links.php';
require __DIR__ . '/lib/ratelimit.php';
require __DIR__ . '/lib/security_headers.php';
require __DIR__ . '/lib/email.php';
require __DIR__ . '/lib/registration.php';
require __DIR__ . '/lib/tier.php';
require __DIR__ . '/lib/tags.php';
require __DIR__ . '/lib/apikeys.php';
require __DIR__ . '/lib/bio.php';
require __DIR__ . '/lib/stripe.php';
require __DIR__ . '/lib/billing.php';
require __DIR__ . '/lib/devices.php';
require __DIR__ . '/lib/blocklist.php';
require __DIR__ . '/lib/turnstile.php';
require __DIR__ . '/lib/abuse.php';
require __DIR__ . '/lib/safebrowsing.php';

// Send before any other header() / echo. setcookie() and json_response()
// further down still work — header() just queues until output starts.
send_security_headers();

// Handle language switch (?lang=sv or ?lang=en)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['sv', 'en'], true)) {
    setcookie('lang', $_GET['lang'], [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => false,
        'secure'   => is_request_https(),
    ]);
    // Strip query string and reject anything that isn't a single-slash absolute
    // path. Without this, a request line like `GET //evil.com/ HTTP/1.1` makes
    // REQUEST_URI start with `//` — a protocol-relative open redirect.
    $redirect = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    if (!is_string($redirect) || !preg_match('#^/[^/\\\\]#', $redirect)) {
        $redirect = base_path() ?: '/';
    }
    header('Location: ' . $redirect);
    exit;
}
set_lang(detect_lang());

auth_clean_expired_sessions();

// ---------- routing ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Strip configured base_path (e.g. /shortly) so internal route matching is
// always relative to the app root.
$base = base_path();
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base)) ?: '/';
}
$path = '/' . trim($uri, '/');

try {
    route($method, $path);
} catch (Throwable $e) {
    error_log('[shortly] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (str_starts_with($path, '/api/')) {
        json_error('server_error', 500);
    }
    http_response_code(500);
    render_view('status', ['code' => 500, 'title' => 'Something went wrong.', 'sub' => 'The server hiccuped. Try again in a moment.']);
}

// =================================================================
function route(string $method, string $path): void {
    // ---- pages ----
    if ($path === '/' && $method === 'GET') { render_view('landing'); return; }
    if ($path === '/login' && $method === 'GET') { render_view('auth', ['mode' => 'login']); return; }
    if ($path === '/register' && $method === 'GET') { render_view('auth', ['mode' => 'register']); return; }
    if ($path === '/verify' && $method === 'GET') {
        render_view('verify', ['email' => (string) ($_GET['email'] ?? '')]);
        return;
    }
    if ($path === '/app' && $method === 'GET') {
        if (!auth_current_user()) {
            header('Location: ' . public_url() . '/login');
            exit;
        }
        render_view('dashboard');
        return;
    }
    if ($path === '/app/bio' && $method === 'GET') {
        if (!auth_current_user()) {
            header('Location: ' . public_url() . '/login');
            exit;
        }
        render_view('bio_editor');
        return;
    }
    if ($path === '/app/keys' && $method === 'GET') {
        if (!auth_current_user()) {
            header('Location: ' . public_url() . '/login');
            exit;
        }
        render_view('keys');
        return;
    }
    if (preg_match('#^/u/([A-Za-z0-9_-]{2,32})$#', $path, $m) && $method === 'GET') {
        $bio = bio_for_slug($m[1]);
        if (!$bio) {
            http_response_code(404);
            render_view('status', [
                'code' => 404,
                'title' => "That bio page doesn't exist.",
                'sub' => 'No record of <code>/u/' . e($m[1]) . '</code>.',
            ]);
            return;
        }
        render_view('bio_public', ['bio' => $bio]);
        return;
    }
    if (preg_match('#^/p/([A-Za-z0-9_-]{2,32})$#', $path, $m) && $method === 'GET') {
        render_view('unlock', ['slug' => $m[1]]);
        return;
    }
    if ($path === '/report' && $method === 'GET') {
        render_view('report', ['slug' => (string) ($_GET['slug'] ?? '')]);
        return;
    }

    // ---- api ----
    // Explicit returns after json_response() are belt-and-braces: it
    // already exit()s, but if anyone changes that helper a missing return
    // would let execution fall through into the next route check.
    if ($path === '/api/health' && $method === 'GET') { json_response(['ok' => true]); return; }
    if ($path === '/api/me' && $method === 'GET') {
        // Strip the session token before returning — it's the same value as
        // the HttpOnly cookie, and leaking it via JSON would let any XSS read
        // it from JS and hijack the session.
        $u = auth_current_user();
        if ($u) unset($u['token']);
        // tier_of() collapses both anonymous (null user) and signed-in into
        // a single field — clients can branch on this without nullchecking.
        json_response([
            'user'      => $u,
            'tier'      => tier_of($u),
            'publicUrl' => public_url(),
        ]);
        return;
    }

    if ($path === '/api/auth/register' && $method === 'POST') { api_register(); return; }
    if ($path === '/api/auth/verify'   && $method === 'POST') { api_verify(); return; }
    if ($path === '/api/auth/resend'   && $method === 'POST') { api_resend(); return; }
    if ($path === '/api/auth/login'    && $method === 'POST') { api_login(); return; }
    if ($path === '/api/auth/logout'   && $method === 'POST') { api_logout(); return; }

    if ($path === '/api/links'   && $method === 'POST') { api_create_link(); return; }
    if ($path === '/api/shorten' && $method === 'POST') { api_create_link(); return; }
    if ($path === '/api/links' && $method === 'GET')  { api_list_links(); return; }
    if (preg_match('#^/api/links/(\d+)$#', $path, $m) && $method === 'DELETE') { api_delete_link((int) $m[1]); return; }
    if (preg_match('#^/api/links/(\d+)$#', $path, $m) && $method === 'PATCH')  { api_update_link((int) $m[1]); return; }
    if (preg_match('#^/api/links/(\d+)/stats$#', $path, $m) && $method === 'GET') { api_link_stats((int) $m[1]); return; }
    if (preg_match('#^/api/links/(\d+)/clicks\.csv$#', $path, $m) && $method === 'GET') { api_export_clicks_csv((int) $m[1]); return; }
    if (preg_match('#^/api/links/(\d+)/tags$#', $path, $m) && $method === 'PUT') { api_set_link_tags((int) $m[1]); return; }

    if ($path === '/api/tags' && $method === 'GET')  { api_list_tags(); return; }
    if ($path === '/api/tags' && $method === 'POST') { api_create_tag(); return; }
    if (preg_match('#^/api/tags/(\d+)$#', $path, $m) && $method === 'DELETE') { api_delete_tag((int) $m[1]); return; }

    if ($path === '/api/bio' && $method === 'GET')    { api_get_bio(); return; }
    if ($path === '/api/bio' && $method === 'PUT')    { api_save_bio(); return; }
    if ($path === '/api/bio' && $method === 'DELETE') { api_delete_bio(); return; }

    if ($path === '/api/keys' && $method === 'GET')  { api_list_keys(); return; }
    if ($path === '/api/keys' && $method === 'POST') { api_create_key(); return; }
    if (preg_match('#^/api/keys/(\d+)$#', $path, $m) && $method === 'DELETE') { api_revoke_key((int) $m[1]); return; }

    if ($path === '/api/billing/checkout' && $method === 'POST') { api_billing_checkout(); return; }
    if ($path === '/api/billing/portal'   && $method === 'POST') { api_billing_portal();   return; }
    if ($path === '/api/billing/status'   && $method === 'GET')  { api_billing_status();   return; }
    if ($path === '/api/webhooks/stripe'  && $method === 'POST') { api_stripe_webhook();   return; }

    if (preg_match('#^/api/unlock/([A-Za-z0-9_-]{2,32})$#', $path, $m) && $method === 'POST') { api_unlock($m[1]); return; }
    if ($path === '/api/abuse'         && $method === 'POST') { api_abuse_report();   return; }
    if ($path === '/api/internal/stats' && $method === 'GET') { api_internal_stats(); return; }

    if ($path === '/api/admin/abuse-queue' && $method === 'GET') { api_admin_abuse_queue(); return; }
    if (preg_match('#^/api/admin/links/([A-Za-z0-9_-]{2,32})/suspend$#', $path, $m)) {
        if ($method === 'POST')   { api_admin_suspend($m[1]);   return; }
        if ($method === 'DELETE') { api_admin_unsuspend($m[1]); return; }
    }

    // ---- redirect ----
    if (preg_match('#^/([A-Za-z0-9_-]{2,32})$#', $path, $m) && $method === 'GET') { redirect_slug($m[1]); return; }

    // ---- not found ----
    if (str_starts_with($path, '/api/')) json_error('not_found', 404);
    http_response_code(404);
    render_view('status', ['code' => 404, 'title' => "That page doesn't exist.", 'sub' => 'No record of <code>' . e($path) . '</code>.']);
}

// ---------- handlers ----------
function api_register(): void {
    // Stage a pending registration and send a verification code by email.
    // No session is created here — the user must POST /api/auth/verify with
    // the code first. Rate-limit per IP guards against bot signups + email
    // spam (each register triggers an outbound email).
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('register:ip:' . $ip, 5, 3600);

    $b = read_json_body();

    // Bot challenge — verify before any DB work / outbound email. Each token
    // is single-use, so we verify in-place rather than caching.
    if (turnstile_is_configured()) {
        $token = (string) ($b['turnstile_token'] ?? '');
        if (!turnstile_verify($token, $ip === 'unknown' ? null : $ip)) {
            json_error('captcha_required', 400);
        }
    }

    $name         = trim((string) ($b['name'] ?? ''));
    $email        = strtolower(trim((string) ($b['email'] ?? '')));
    $emailConfirm = strtolower(trim((string) ($b['email_confirm'] ?? '')));
    $pw           = (string) ($b['password'] ?? '');

    if ($name === '' || $email === '' || $pw === '')              json_error('all_fields_required', 400);
    if (strlen($name) > 100)                                      json_error('name_too_long', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) json_error('invalid_email', 400);
    if ($emailConfirm !== '' && $email !== $emailConfirm)         json_error('email_mismatch', 400);
    if (strlen($pw) < 8 || strlen($pw) > 72)                      json_error('password_min_8', 400);
    if (db_get('SELECT 1 AS x FROM users WHERE email = ?', [$email])) json_error('email_taken', 409);

    registration_start($name, $email, $pw);

    json_response(['pending' => true, 'email' => $email]);
}

function api_verify(): void {
    // Per-IP cap on verify attempts. Per-pending-row attempts is also tracked
    // inside registration_verify (5 wrong codes → 410 too_many_attempts) so
    // this just guards against spreading attempts across many IPs/emails.
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('verify:ip:' . $ip, 30, 300);

    $b = read_json_body();
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $code  = trim((string) ($b['code'] ?? ''));

    if ($email === '' || $code === '')             json_error('email_and_code_required', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('invalid_email', 400);
    if (!preg_match('/^\d{6}$/', $code))            json_error('invalid_code', 400);

    try {
        $user = registration_verify($email, $code);
    } catch (RuntimeException $e) {
        // expired / too_many_attempts — both terminal: user must re-register.
        json_error($e->getMessage(), 410);
    }
    if (!$user) json_error('invalid_code', 401);

    $sess = auth_create_session((int) $user['id']);
    auth_set_cookie($sess['token'], $sess['expires']);
    json_response(['user' => $user]);
}

function api_resend(): void {
    // Tighter limit than register since this triggers outbound email per call
    // — 3 resends per 10 min per IP is plenty for legitimate flows.
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('resend:ip:' . $ip, 3, 600);

    $b = read_json_body();
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('invalid_email', 400);

    // Don't leak whether a pending row exists — always respond ok=true. The
    // response is informational only; the user already knows whether they
    // should be expecting an email.
    registration_resend($email);
    json_response(['ok' => true]);
}

function api_login(): void {
    // Bcrypt hash that no real password matches. Used for login attempts
    // against unknown emails so password_verify still runs and the response
    // latency doesn't leak whether the account exists.
    $dummyHash = '$2y$10$abcdefghijklmnopqrstuuOJ.ScRZBgFD7iJpQYXg.HZk0UB9k0GG';

    $b = read_json_body();
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $pw    = (string) ($b['password'] ?? '');
    $ip    = client_ip() ?? 'unknown';
    $ipKey = 'login:ip:' . $ip;
    $emKey = 'login:em:' . substr(hash('sha256', $email), 0, 32);

    // Gate on counters BEFORE touching password_verify (~10ms each — without
    // this an exhausted attacker could keep CPU-spinning the bcrypt path).
    // Only failures bump the counter, so 20 successful logins from a shared
    // office IP no longer lock everyone else out.
    if (rate_limit_count($ipKey, 300) >= 20 || rate_limit_count($emKey, 300) >= 10) {
        header('Retry-After: 300');
        json_error('rate_limited', 429);
    }

    $fail = static function () use ($ipKey, $emKey): void {
        rate_limit_increment($ipKey, 300);
        rate_limit_increment($emKey, 300);
        json_error('invalid_credentials', 401);
    };

    if ($email === '' || $pw === '') $fail();

    $user = db_get('SELECT id, email, password_hash FROM users WHERE email = ?', [$email]);
    // Run password_verify even when the user doesn't exist — equalises timing
    // so attackers can't enumerate emails by response latency.
    $hash = ($user && is_string($user['password_hash']) && $user['password_hash'] !== '')
        ? $user['password_hash']
        : $dummyHash;
    $ok = password_verify($pw, $hash);
    // SSO-only accounts have a sentinel hash (`!sso:…`) that can never verify
    // — but be explicit so a future bcrypt-format change can't silently flip
    // the gate. Reject anything not starting with bcrypt's `$2`.
    if (!$user || !$ok || !str_starts_with((string) $user['password_hash'], '$2')) {
        $fail();
    }

    $sess = auth_create_session((int) $user['id']);
    auth_set_cookie($sess['token'], $sess['expires']);
    json_response(['user' => ['id' => (int) $user['id'], 'email' => $user['email']]]);
}

function api_logout(): void {
    $u = auth_current_user();
    auth_destroy_session($u['token'] ?? null);
    auth_clear_cookie();
    json_response(['ok' => true]);
}

function api_create_link(): void {
    $u = auth_current_user();
    $body = read_json_body();
    // Anonymous tier cap per the membership spec: 5 links/hour/IP.
    // Authenticated users are exempt at this layer (per-tier limits land
    // in later phases).
    if (!$u) {
        $ip = client_ip() ?? 'unknown';
        rate_limit_or_429('create:ip:' . $ip, 5, 3600);
        // Bot challenge for anon — only when Turnstile is configured. Each
        // token is single-use, so we verify in-place rather than caching.
        if (turnstile_is_configured()) {
            $token = (string) ($body['turnstile_token'] ?? '');
            if (!turnstile_verify($token, $ip === 'unknown' ? null : $ip)) {
                json_error('captcha_required', 400);
            }
        }
    }
    try {
        $res = links_create($body, $u['id'] ?? null);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    }
    json_response($res);
}

function api_abuse_report(): void {
    // Rate-limit per IP — community reports shouldn't need many submissions.
    // Tighter than other endpoints to discourage report-bombing.
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('abuse:ip:' . $ip, 5, 3600);

    $b = read_json_body();
    $slug   = (string) ($b['slug'] ?? '');
    $reason = (string) ($b['reason'] ?? '');
    $detail = isset($b['detail']) && is_string($b['detail']) ? trim($b['detail']) : null;

    if (!is_valid_slug($slug)) json_error('invalid_slug', 400);

    try {
        $result = abuse_report($slug, $reason, $detail !== '' ? $detail : null, $ip === 'unknown' ? null : $ip);
    } catch (InvalidArgumentException $e) {
        $code = $e->getMessage();
        json_error($code, $code === 'link_not_found' ? 404 : 400);
    }
    json_response($result);
}

function api_list_links(): void {
    $u = auth_require();
    json_response(['links' => links_for_user((int) $u['id'])]);
}

function api_delete_link(int $id): void {
    $u = auth_require();
    if (!links_delete($id, (int) $u['id'])) json_error('not_found', 404);
    json_response(['ok' => true]);
}

function api_link_stats(int $id): void {
    $u = auth_require();
    $stats = links_stats($id, (int) $u['id']);
    if (!$stats) json_error('not_found', 404);
    json_response($stats);
}

function api_update_link(int $id): void {
    $u = auth_require();
    require_tier('pro', $u);
    $b = read_json_body();
    try {
        $link = links_update($id, (int) $u['id'], $b);
    } catch (InvalidArgumentException $e) {
        $code = $e->getMessage();
        // Owner-mismatch / not-found → 404, everything else 400.
        json_error($code, $code === 'not_found' ? 404 : 400);
    }
    json_response($link);
}

function api_export_clicks_csv(int $linkId): void {
    $u = auth_require();
    require_tier('pro', $u);
    // Confirm ownership before exporting — also gives us 404 vs 403 split.
    $link = db_get('SELECT slug FROM links WHERE id = ? AND user_id = ?', [$linkId, $u['id']]);
    if (!$link) json_error('not_found', 404);

    // Stream row-by-row instead of loading the full result set in memory.
    // For a Pro link with millions of clicks (theoretical), db_all would
    // OOM the PHP process. PDOStatement::fetch keeps memory bounded.
    $stmt = db()->prepare(
        'SELECT ts, referrer, user_agent, ip_hash, device_type
         FROM clicks WHERE link_id = ? ORDER BY ts ASC'
    );
    $stmt->execute([$linkId]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clicks-' . $link['slug'] . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['timestamp_iso', 'timestamp_ms', 'referrer', 'user_agent', 'ip_hash', 'device_type']);
    while ($r = $stmt->fetch()) {
        $ts = (int) $r['ts'];
        fputcsv($out, [
            date('c', intdiv($ts, 1000)),
            $ts,
            $r['referrer'] ?? '',
            $r['user_agent'] ?? '',
            $r['ip_hash'] ?? '',
            $r['device_type'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

function api_list_tags(): void {
    $u = auth_require();
    json_response(['tags' => tags_for_user((int) $u['id'])]);
}

function api_create_tag(): void {
    $u = auth_require();
    $b = read_json_body();
    try {
        $tag = tags_create(
            (int) $u['id'],
            (string) ($b['name'] ?? ''),
            isset($b['color']) ? (string) $b['color'] : null
        );
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    }
    json_response(['tag' => $tag]);
}

function api_delete_tag(int $tagId): void {
    $u = auth_require();
    if (!tags_delete((int) $u['id'], $tagId)) json_error('not_found', 404);
    json_response(['ok' => true]);
}

function api_set_link_tags(int $linkId): void {
    $u = auth_require();
    // Confirm ownership before mutating tag relationships.
    $link = db_get('SELECT id FROM links WHERE id = ? AND user_id = ?', [$linkId, $u['id']]);
    if (!$link) json_error('not_found', 404);
    $b = read_json_body();
    $tagIds = is_array($b['tag_ids'] ?? null) ? $b['tag_ids'] : [];
    links_set_tags($linkId, (int) $u['id'], $tagIds);
    json_response(['tags' => links_tags_for_link($linkId)]);
}

function api_get_bio(): void {
    $u = auth_require();
    json_response(['bio' => bio_for_user((int) $u['id'])]);
}

function api_save_bio(): void {
    $u = auth_require();
    try {
        $bio = bio_save((int) $u['id'], read_json_body());
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    }
    json_response(['bio' => $bio]);
}

function api_delete_bio(): void {
    $u = auth_require();
    bio_delete((int) $u['id']);
    json_response(['ok' => true]);
}

function api_list_keys(): void {
    $u = auth_require();
    json_response(['keys' => apikey_list((int) $u['id'])]);
}

function api_create_key(): void {
    $u = auth_require();
    // Don't let API-key auth create more API keys — only session/SSO can.
    // Otherwise a stolen key could spawn endless replacements.
    if (($u['auth'] ?? null) === 'apikey') json_error('session_required', 401);
    $b = read_json_body();
    try {
        $key = apikey_create((int) $u['id'], isset($b['label']) ? (string) $b['label'] : null);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    }
    json_response(['key' => $key]);
}

function api_revoke_key(int $keyId): void {
    $u = auth_require();
    if (($u['auth'] ?? null) === 'apikey') json_error('session_required', 401);
    if (!apikey_revoke((int) $u['id'], $keyId)) json_error('not_found', 404);
    json_response(['ok' => true]);
}

function api_billing_checkout(): void {
    $u = auth_require();
    // Don't let API keys spawn checkout sessions — billing is account-level.
    if (($u['auth'] ?? null) === 'apikey') json_error('session_required', 401);
    $b = read_json_body();
    $plan = (string) ($b['plan'] ?? '');
    try {
        $url = billing_start_checkout($u, $plan);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    } catch (RuntimeException $e) {
        error_log('[shortly:billing] checkout failed: ' . $e->getMessage());
        json_error('billing_unavailable', 503);
    }
    json_response(['url' => $url]);
}

function api_billing_portal(): void {
    $u = auth_require();
    if (($u['auth'] ?? null) === 'apikey') json_error('session_required', 401);
    try {
        $url = billing_start_portal($u);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    } catch (RuntimeException $e) {
        error_log('[shortly:billing] portal failed: ' . $e->getMessage());
        json_error('billing_unavailable', 503);
    }
    json_response(['url' => $url]);
}

function api_billing_status(): void {
    $u = auth_require();
    $row = db_get(
        'SELECT status, plan, current_period_end, cancel_at_period_end
         FROM subscriptions WHERE user_id = ?',
        [$u['id']]
    );
    json_response([
        'tier'              => tier_of($u),
        'billing_available' => billing_is_configured(),
        'subscription'      => $row ? [
            'status'              => $row['status'],
            'plan'                => $row['plan'],
            'current_period_end'  => $row['current_period_end'] !== null
                                        ? (int) $row['current_period_end'] : null,
            'cancel_at_period_end'=> (bool) $row['cancel_at_period_end'],
        ] : null,
    ]);
}

function api_stripe_webhook(): void {
    // Read raw body bypassing read_json_body's 64-KB cap. Webhooks rarely
    // hit even 16 KB but we leave headroom. Stripe verifies signature on
    // the EXACT bytes they sent, so no preprocessing.
    $raw = file_get_contents('php://input', false, null, 0, 524289);
    if ($raw === false) $raw = '';
    if (strlen($raw) > 524288) json_error('payload_too_large', 413);

    $secret = (string) (config()['stripe_webhook_secret'] ?? '');
    $sig = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    if (!stripe_verify_signature($raw, $sig, $secret)) {
        // Don't reveal which check failed — generic 400.
        json_error('invalid_signature', 400);
    }
    $event = json_decode($raw, true);
    if (!is_array($event)) json_error('invalid_payload', 400);

    try {
        billing_handle_webhook($event);
    } catch (Throwable $e) {
        // Log + 500 so Stripe retries. Webhook handlers are idempotent so
        // a retry after a fix is safe.
        error_log('[shortly:webhook] ' . $e->getMessage() . ' (event=' . ($event['id'] ?? '?') . ')');
        json_error('handler_error', 500);
    }
    // Stripe just needs a 2xx — body content is ignored.
    json_response(['ok' => true]);
}

function api_unlock(string $slug): void {
    // Throttle per slug+IP — protects against password brute force on a single
    // protected link while still letting different visitors unlock in parallel.
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('unlock:' . $slug . ':' . $ip, 10, 300);

    $link = db_get('SELECT * FROM links WHERE slug = ?', [$slug]);
    if (!$link || empty($link['password_hash'])) json_error('not_found', 404);
    if (!empty($link['suspended_at'])) json_error('link_suspended', 451);
    if (!empty($link['expires_at']) && (int) $link['expires_at'] < now_ms()) json_error('expired', 410);

    $b = read_json_body();
    $pw = (string) ($b['password'] ?? '');
    // Always run password_verify, even when $pw is empty. The previous
    // short-circuit `$pw === ''` returned faster on empty input than on
    // wrong-but-non-empty input — a tiny timing leak that distinguishes
    // "user submitted nothing" from "user submitted wrong guess".
    // password_verify('') against a real bcrypt hash returns false in
    // ~10ms, matching the timing of any wrong guess.
    if (!password_verify($pw, $link['password_hash'])) json_error('invalid_password', 401);

    links_record_click((int) $link['id']);
    json_response(['target' => $link['target']]);
}

// Shared auth gate for /api/admin/* — requires `Authorization: Bearer
// <internal_secret>`. Same auth scheme as /api/internal/stats so ops can
// reuse the same secret. Rate-limit per-IP for defense in depth in case
// the secret ever leaks.
function api_admin_check_auth(): void {
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('admin:ip:' . $ip, 60, 60);

    $secret = (string) (config()['internal_secret'] ?? '');
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $given = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $given = trim($m[1]);
    }
    if ($secret === '' || !hash_equals($secret, $given)) {
        json_error('forbidden', 403);
    }
}

function api_admin_abuse_queue(): void {
    api_admin_check_auth();
    // GROUP_CONCAT works on both SQLite and MySQL (5.7+/MariaDB). DISTINCT
    // inside it is also portable.
    $rows = db_all(
        "SELECT l.id, l.slug, l.target, l.created_at, l.suspended_at,
                l.suspended_reason,
                COUNT(a.id)                       AS report_count,
                COUNT(DISTINCT a.reporter_ip_hash) AS distinct_reporters,
                MAX(a.created_at)                 AS last_report,
                GROUP_CONCAT(DISTINCT a.reason)    AS reasons
         FROM links l
         INNER JOIN abuse_reports a ON a.link_id = l.id
         GROUP BY l.id, l.slug, l.target, l.created_at, l.suspended_at, l.suspended_reason
         ORDER BY (l.suspended_at IS NULL) DESC, last_report DESC
         LIMIT 200"
    );
    $out = array_map(fn($r) => [
        'id'                 => (int) $r['id'],
        'slug'               => $r['slug'],
        'target'             => $r['target'],
        'created_at'         => (int) $r['created_at'],
        'suspended'          => $r['suspended_at'] !== null,
        'suspended_at'       => $r['suspended_at'] !== null ? (int) $r['suspended_at'] : null,
        'suspended_reason'   => $r['suspended_reason'],
        'report_count'       => (int) $r['report_count'],
        'distinct_reporters' => (int) $r['distinct_reporters'],
        'last_report'        => (int) $r['last_report'],
        'reasons'            => array_values(array_filter(explode(',', (string) $r['reasons']))),
    ], $rows);
    json_response(['queue' => $out]);
}

function api_admin_suspend(string $slug): void {
    api_admin_check_auth();
    $b = read_json_body();
    $reason = trim((string) ($b['reason'] ?? 'manual'));
    if ($reason === '') $reason = 'manual';
    if (strlen($reason) > 80) json_error('reason_too_long', 400);

    $changed = db_run(
        'UPDATE links SET suspended_at = ?, suspended_reason = ? WHERE slug = ?',
        [now_ms(), 'manual:' . $reason, strtolower($slug)]
    );
    if ($changed === 0) json_error('not_found', 404);
    json_response(['ok' => true, 'slug' => $slug]);
}

function api_admin_unsuspend(string $slug): void {
    api_admin_check_auth();
    $changed = db_run(
        'UPDATE links SET suspended_at = NULL, suspended_reason = NULL WHERE slug = ?',
        [strtolower($slug)]
    );
    if ($changed === 0) json_error('not_found', 404);
    json_response(['ok' => true, 'slug' => $slug]);
}

function api_internal_stats(): void {
    // Belt-and-braces: the Bearer secret already protects this endpoint, but
    // a per-IP cap means a leaked-once secret (the 2026-05-01 config-drift
    // incident is in scope) can't be replayed at high volume.
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('internal:ip:' . $ip, 60, 60);

    $secret = config()['internal_secret'] ?? '';
    // Read from Authorization: Bearer … so the secret never lands in access
    // logs or referrers (as a query string would).
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $given = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $given = trim($m[1]);
    }
    if ($secret === '' || !hash_equals($secret, $given)) {
        json_error('forbidden', 403);
    }
    $q = fn(string $sql, array $p = []) => (int) (db_get($sql, $p)['n'] ?? 0);
    $drv = config()['db']['driver'];
    if ($drv === 'sqlite') {
        $now   = (int) (microtime(true) * 1000);
        $ms30d = $now - 30 * 24 * 3600 * 1000;
        $ms7d  = $now -  7 * 24 * 3600 * 1000;
        $ms1d  = $now -      24 * 3600 * 1000;
        $linksTotal  = $q('SELECT COUNT(*) AS n FROM links');
        $links30d    = $q('SELECT COUNT(*) AS n FROM links WHERE created_at >= ?', [$ms30d]);
        $links7d     = $q('SELECT COUNT(*) AS n FROM links WHERE created_at >= ?', [$ms7d]);
        $linksToday  = $q('SELECT COUNT(*) AS n FROM links WHERE created_at >= ?', [$ms1d]);
        $clicksTotal = $q('SELECT COUNT(*) AS n FROM clicks');
        $clicks30d   = $q('SELECT COUNT(*) AS n FROM clicks WHERE ts >= ?', [$ms30d]);
    } else {
        $linksTotal  = $q('SELECT COUNT(*) AS n FROM links');
        $links30d    = $q('SELECT COUNT(*) AS n FROM links WHERE created_at >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))*1000');
        $links7d     = $q('SELECT COUNT(*) AS n FROM links WHERE created_at >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))*1000');
        $linksToday  = $q('SELECT COUNT(*) AS n FROM links WHERE created_at >= UNIX_TIMESTAMP(CURDATE())*1000');
        $clicksTotal = $q('SELECT COUNT(*) AS n FROM clicks');
        $clicks30d   = $q('SELECT COUNT(*) AS n FROM clicks WHERE ts >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))*1000');
    }
    json_response([
        'ok'     => true,
        'links'  => ['total' => $linksTotal, 'last_30d' => $links30d, 'last_7d' => $links7d, 'today' => $linksToday],
        'clicks' => ['total' => $clicksTotal, 'last_30d' => $clicks30d],
    ]);
}

function redirect_slug(string $slug): void {
    // Tell crawlers never to index short-links — reduces SEO-spam incentive
    // and keeps shortly's domain off Google when bad actors point at it.
    header('X-Robots-Tag: noindex, nofollow');

    if (in_array(strtolower($slug), RESERVED_SLUGS, true)) {
        http_response_code(404);
        render_view('status', ['code' => 404, 'title' => "That page doesn't exist.", 'sub' => 'Reserved path.']);
        return;
    }
    $link = db_get('SELECT * FROM links WHERE slug = ?', [$slug]);
    if (!$link) {
        http_response_code(404);
        render_view('status', ['code' => 404, 'title' => "That short link doesn't exist.", 'sub' => 'No record of <code>/' . e($slug) . '</code>.']);
        return;
    }
    // Suspended → 451 Unavailable For Legal Reasons. Shown BEFORE expiry/
    // password gates so a suspended link doesn't accidentally leak its
    // protected destination.
    if (!empty($link['suspended_at'])) {
        http_response_code(451);
        $reason = (string) ($link['suspended_reason'] ?? '');
        render_view('status', [
            'code' => 451,
            'title' => 'This link has been suspended.',
            'sub'   => 'It was reported for abuse' . ($reason !== '' ? ' (' . e($reason) . ')' : '') . '.',
        ]);
        return;
    }
    if (!empty($link['expires_at']) && (int) $link['expires_at'] < now_ms()) {
        http_response_code(410);
        render_view('status', ['code' => 410, 'title' => 'This link has expired.', 'sub' => "The owner set an expiry date that's now passed."]);
        return;
    }
    if (!empty($link['password_hash'])) {
        header('Location: ' . public_url() . '/p/' . $link['slug']);
        exit;
    }
    // Cap clicks recorded per IP (across all slugs) to keep a single bot from
    // bloating the clicks table. Don't 429 the redirect — that would break the
    // UX for legit users sharing an IP. Just silently skip the insert past
    // the limit; the redirect itself still happens.
    $ip = client_ip() ?? 'unknown';
    if (rate_limit_check('click:ip:' . $ip, 600, 60)) {
        links_record_click((int) $link['id']);
    }
    header('Location: ' . $link['target'], true, 302);
    exit;
}
