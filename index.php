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
require __DIR__ . '/lib/password_reset.php';
require __DIR__ . '/lib/geo.php';
require __DIR__ . '/lib/tier.php';
require __DIR__ . '/lib/tags.php';
require __DIR__ . '/lib/apikeys.php';
require __DIR__ . '/lib/bio.php';
require __DIR__ . '/lib/stripe.php';
require __DIR__ . '/lib/paypal.php';
require __DIR__ . '/lib/billing.php';
require __DIR__ . '/lib/devices.php';
require __DIR__ . '/lib/blocklist.php';
require __DIR__ . '/lib/turnstile.php';
require __DIR__ . '/lib/abuse.php';
require __DIR__ . '/lib/safebrowsing.php';

// Send before any other header() / echo. setcookie() and json_response()
// further down still work — header() just queues until output starts.
send_security_headers();

// Handle language / currency switch (?lang=sv or ?currency=sek).
// Both follow the same cookie-then-redirect pattern.
$prefSwitch = null;
if (isset($_GET['lang']) && in_array($_GET['lang'], ['sv', 'en'], true)) {
    $prefSwitch = ['lang', $_GET['lang']];
} elseif (isset($_GET['currency']) && in_array($_GET['currency'], CURRENCIES, true)) {
    $prefSwitch = ['currency', $_GET['currency']];
}
if ($prefSwitch !== null) {
    setcookie($prefSwitch[0], $prefSwitch[1], [
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
set_currency(detect_currency());

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
    if ($path === '/forgot' && $method === 'GET') { render_view('forgot'); return; }
    if ($path === '/reset' && $method === 'GET') {
        // Pre-validate the token server-side so we can render a clean
        // "expired/invalid" page instead of letting the user type into a
        // form that will only 410 on submit.
        $tok = (string) ($_GET['token'] ?? '');
        $status = $tok === '' ? 'invalid_token' : password_reset_token_status($tok);
        render_view('reset', ['token' => $tok, 'token_status' => $status]);
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
    if ($path === '/upgrade' && $method === 'GET') { handle_upgrade_redirect(); return; }
    // Page-level admin guard: anon → /login; signed-in non-admin → 403 view.
    // (API equivalents enforce the same check in require_admin().)
    $adminPageGate = function (): ?array {
        $u = auth_current_user();
        if (!$u) { header('Location: ' . public_url() . '/login'); exit; }
        if (!is_admin($u)) {
            http_response_code(403);
            render_view('status', ['code' => 403, 'title' => 'Forbidden', 'sub' => 'Admin access only.']);
            return null;
        }
        return $u;
    };
    if ($path === '/admin' && $method === 'GET') {
        if (!$adminPageGate()) return;
        render_view('admin_users', ['q' => (string) ($_GET['q'] ?? ''), 'page' => max(1, (int) ($_GET['page'] ?? 1))]);
        return;
    }
    if ($path === '/admin/users' && $method === 'GET') {
        if (!$adminPageGate()) return;
        render_view('admin_users', ['q' => (string) ($_GET['q'] ?? ''), 'page' => max(1, (int) ($_GET['page'] ?? 1))]);
        return;
    }
    if ($path === '/admin/plans' && $method === 'GET') {
        if (!$adminPageGate()) return;
        render_view('admin_plans');
        return;
    }
    if ($path === '/admin/stats' && $method === 'GET') {
        if (!$adminPageGate()) return;
        render_view('admin_stats');
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
    if ($path === '/api/auth/forgot'   && $method === 'POST') { api_forgot(); return; }
    if ($path === '/api/auth/reset'    && $method === 'POST') { api_reset();  return; }

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

    if ($path === '/api/billing/checkout'        && $method === 'POST') { api_billing_checkout();        return; }
    if ($path === '/api/billing/paypal/checkout' && $method === 'POST') { api_billing_paypal_checkout(); return; }
    if ($path === '/api/billing/portal'          && $method === 'POST') { api_billing_portal();          return; }
    if ($path === '/api/billing/cancel'          && $method === 'POST') { api_billing_cancel();          return; }
    if ($path === '/api/billing/status'          && $method === 'GET')  { api_billing_status();          return; }
    if ($path === '/api/webhooks/stripe'         && $method === 'POST') { api_stripe_webhook();          return; }
    if ($path === '/api/webhooks/paypal'         && $method === 'POST') { api_paypal_webhook();          return; }

    if (preg_match('#^/api/unlock/([A-Za-z0-9_-]{2,32})$#', $path, $m) && $method === 'POST') { api_unlock($m[1]); return; }
    if ($path === '/api/abuse'         && $method === 'POST') { api_abuse_report();   return; }
    if ($path === '/api/internal/stats' && $method === 'GET') { api_internal_stats(); return; }

    if ($path === '/api/admin/abuse-queue' && $method === 'GET') { api_admin_abuse_queue(); return; }
    if (preg_match('#^/api/admin/links/([A-Za-z0-9_-]{2,32})/suspend$#', $path, $m)) {
        if ($method === 'POST')   { api_admin_suspend($m[1]);   return; }
        if ($method === 'DELETE') { api_admin_unsuspend($m[1]); return; }
    }

    // Admin panel APIs — session-auth gated by require_admin() inside each
    // handler, NOT the Bearer-secret pattern used by abuse-queue above.
    if ($path === '/api/admin/users' && $method === 'GET')   { api_admin_users_list();      return; }
    if (preg_match('#^/api/admin/users/(\d+)/tier$#', $path, $m) && $method === 'POST') {
        api_admin_user_tier((int) $m[1]); return;
    }
    if (preg_match('#^/api/admin/users/(\d+)/sessions$#', $path, $m) && $method === 'DELETE') {
        api_admin_user_clear_sessions((int) $m[1]); return;
    }
    if ($path === '/api/admin/plans' && $method === 'GET')  { api_admin_plans_list();    return; }
    if ($path === '/api/admin/plans' && $method === 'POST') { api_admin_plans_create();  return; }
    if (preg_match('#^/api/admin/plans/(price_[A-Za-z0-9]+)/archive$#', $path, $m) && $method === 'POST') {
        api_admin_plans_archive($m[1]); return;
    }
    if (preg_match('#^/api/admin/plans/(price_[A-Za-z0-9]+)/unarchive$#', $path, $m) && $method === 'POST') {
        api_admin_plans_unarchive($m[1]); return;
    }
    if ($path === '/api/admin/stats' && $method === 'GET') { api_admin_stats(); return; }

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

function api_forgot(): void {
    // Same per-IP cap as register so the endpoint can't be turned into an
    // email mailbomb. Per-email cap inside password_reset_request() (via
    // the DELETE … WHERE used_at IS NULL) makes the second-in-a-minute
    // request cheap on the inbox side.
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('forgot:ip:' . $ip, 5, 3600);

    $b = read_json_body();
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('invalid_email', 400);

    // Per-email cap on top of the per-IP one. Catches the "attacker scrapes
    // a leaked email list and POSTs forgot for each" case from many IPs.
    rate_limit_or_429('forgot:email:' . $email, 3, 3600);

    // Always 200, regardless of whether the email exists — leaking
    // existence to anonymous POSTers is the whole reason a separate forgot
    // endpoint exists in the first place.
    password_reset_request($email);
    json_response(['ok' => true]);
}

function api_reset(): void {
    // Lighter rate limit than forgot — this just verifies a token we
    // already issued, no outbound mail. The 64-char token's brute-force
    // surface is already negligible.
    $ip = client_ip() ?? 'unknown';
    rate_limit_or_429('reset:ip:' . $ip, 20, 600);

    $b = read_json_body();
    $token    = (string) ($b['token'] ?? '');
    $password = (string) ($b['password'] ?? '');
    if (strlen($password) < 8) json_error('weak_password', 400);

    try {
        password_reset_consume($token, $password);
    } catch (RuntimeException $e) {
        // 'invalid_token' / 'expired' / 'already_used' — all single-string
        // codes so the client can map them to localized messages.
        json_error($e->getMessage(), 410);
    }
    json_response(['ok' => true]);
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

// Single entry point used by the landing pricing CTAs. Bounces anonymous
// visitors through /register (preserving the plan), starts Stripe Checkout
// for signed-in free users, and skips Pro users back to /app.
function handle_upgrade_redirect(): void {
    $plan = (string) ($_GET['plan'] ?? '');
    if (!in_array($plan, ['monthly', 'yearly'], true)) {
        header('Location: ' . public_url() . '/');
        exit;
    }
    $currency = (string) ($_GET['currency'] ?? detect_currency());
    if (!in_array($currency, CURRENCIES, true)) $currency = detect_currency();

    $u = auth_current_user();
    if (!$u) {
        $qs = http_build_query(['next' => 'upgrade', 'plan' => $plan, 'currency' => $currency]);
        header('Location: ' . public_url() . '/register?' . $qs);
        exit;
    }
    if (tier_of($u) === 'pro') {
        header('Location: ' . public_url() . '/app');
        exit;
    }
    try {
        $url = billing_start_checkout($u, $plan, $currency);
    } catch (Throwable $e) {
        error_log('[shortly:upgrade] start failed: ' . $e->getMessage());
        header('Location: ' . public_url() . '/app?upgrade_error=1');
        exit;
    }
    header('Location: ' . $url);
    exit;
}

function api_billing_checkout(): void {
    $u = auth_require();
    // Don't let API keys spawn checkout sessions — billing is account-level.
    if (($u['auth'] ?? null) === 'apikey') json_error('session_required', 401);
    $b = read_json_body();
    $plan = (string) ($b['plan'] ?? '');
    // Currency comes from the JSON body (set by the UI selector) so the
    // checkout call doesn't depend on a cookie surviving the round-trip; fall
    // back to the cookie-derived default if the client omitted it.
    $currency = (string) ($b['currency'] ?? detect_currency());
    try {
        $url = billing_start_checkout($u, $plan, $currency);
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

function api_billing_paypal_checkout(): void {
    $u = auth_require();
    if (($u['auth'] ?? null) === 'apikey') json_error('session_required', 401);
    $b = read_json_body();
    $plan = (string) ($b['plan'] ?? '');
    try {
        $url = billing_start_paypal($u, $plan);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 400);
    } catch (RuntimeException $e) {
        error_log('[shortly:billing] paypal start failed: ' . $e->getMessage());
        json_error('paypal_unavailable', 503);
    }
    json_response(['url' => $url]);
}

// Provider-aware cancel. PayPal users don't have a hosted portal — they
// cancel through us (which calls PayPal's API). Stripe users get the
// Customer Portal instead (richer features there).
function api_billing_cancel(): void {
    $u = auth_require();
    if (($u['auth'] ?? null) === 'apikey') json_error('session_required', 401);
    $row = db_get('SELECT provider, stripe_subscription_id FROM subscriptions WHERE user_id = ?', [$u['id']]);
    if (!$row) json_error('no_subscription', 404);

    if ($row['provider'] === 'paypal') {
        try {
            paypal_cancel_subscription((string) $row['stripe_subscription_id']);
            // Don't flip tier here — let the BILLING.SUBSCRIPTION.CANCELLED
            // webhook do it. That keeps the source-of-truth path consistent.
        } catch (Throwable $e) {
            error_log('[shortly:billing] paypal cancel failed: ' . $e->getMessage());
            json_error('paypal_error', 502);
        }
        json_response(['ok' => true]);
        return;
    }
    // Stripe path: caller should use the portal instead, but keep this as a
    // last-ditch cancel for emergencies.
    json_error('use_portal', 400);
}

function api_billing_status(): void {
    $u = auth_require();
    $row = db_get(
        'SELECT provider, status, plan, current_period_end, cancel_at_period_end
         FROM subscriptions WHERE user_id = ?',
        [$u['id']]
    );
    json_response([
        'tier'              => tier_of($u),
        'billing_available' => billing_is_configured(),
        'paypal_available'  => paypal_is_configured(),
        'subscription'      => $row ? [
            'provider'            => $row['provider'] ?? 'stripe',
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

function api_paypal_webhook(): void {
    $raw = file_get_contents('php://input', false, null, 0, 524289);
    if ($raw === false) $raw = '';
    if (strlen($raw) > 524288) json_error('payload_too_large', 413);

    // Collect PayPal-* headers in a case-insensitive map. apache_request_headers()
    // isn't always available under php-fpm, so fall back to $_SERVER.
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_PAYPAL_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $headers[$name] = (string) $v;
        }
    }
    if (!paypal_verify_webhook($raw, $headers)) {
        json_error('invalid_signature', 400);
    }
    $event = json_decode($raw, true);
    if (!is_array($event)) json_error('invalid_payload', 400);

    try {
        billing_handle_paypal_webhook($event);
    } catch (Throwable $e) {
        // 500 so PayPal retries — handlers are idempotent.
        error_log('[shortly:webhook:paypal] ' . $e->getMessage() . ' (event=' . ($event['id'] ?? '?') . ')');
        json_error('handler_error', 500);
    }
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

// ─── Admin panel handlers ──────────────────────────────────────────────
// Session-auth gated by require_admin() (which itself rejects API-key
// auth — admin actions can't be triggered by a leaked key).

function api_admin_users_list(): void {
    require_admin();
    $q    = strtolower(trim((string) ($_GET['q'] ?? '')));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per  = 50;
    $off  = ($page - 1) * $per;

    if ($q !== '') {
        $like = '%' . $q . '%';
        $total = (int) db_get('SELECT COUNT(*) c FROM users WHERE LOWER(email) LIKE ?', [$like])['c'];
        $rows  = db_all(
            'SELECT id, email, name, tier, created_at FROM users
             WHERE LOWER(email) LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?',
            [$like, $per, $off]
        );
    } else {
        $total = (int) db_get('SELECT COUNT(*) c FROM users')['c'];
        $rows  = db_all(
            'SELECT id, email, name, tier, created_at FROM users
             ORDER BY id DESC LIMIT ? OFFSET ?',
            [$per, $off]
        );
    }
    json_response([
        'users'    => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per,
    ]);
}

function api_admin_user_tier(int $userId): void {
    require_admin();
    $b = read_json_body();
    $tier = (string) ($b['tier'] ?? '');
    if (!in_array($tier, ['free', 'pro'], true)) json_error('invalid_tier', 400);

    $u = db_get('SELECT id FROM users WHERE id = ?', [$userId]);
    if (!$u) json_error('not_found', 404);

    // The Stripe webhook is the source of truth for paid Pro — manually
    // setting tier here only overrides the in-app gate. If the user has a
    // Stripe subscription this flag will get reset on the next webhook
    // event. Comp-ing pro for a free account works; "downgrading" a real
    // paying customer here is misleading (their card will keep charging).
    db_run('UPDATE users SET tier = ? WHERE id = ?', [$tier, $userId]);
    json_response(['ok' => true]);
}

function api_admin_user_clear_sessions(int $userId): void {
    require_admin();
    $u = db_get('SELECT id FROM users WHERE id = ?', [$userId]);
    if (!$u) json_error('not_found', 404);
    db_run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    json_response(['ok' => true]);
}

function api_admin_plans_list(): void {
    require_admin();
    $productId = (string) (config()['stripe_product_id'] ?? '');
    $activeOnly = ($_GET['archived'] ?? '') !== '1';
    try {
        $prices = stripe_list_prices($productId ?: null, $activeOnly);
    } catch (Throwable $e) {
        error_log('[shortly:admin] stripe list failed: ' . $e->getMessage());
        json_error('stripe_unavailable', 503);
    }
    json_response($prices);
}

function api_admin_plans_create(): void {
    require_admin();
    $productId = (string) (config()['stripe_product_id'] ?? '');
    if ($productId === '') json_error('product_not_configured', 400);

    $b = read_json_body();
    $currency   = strtolower((string) ($b['currency'] ?? ''));
    $amount     = (int) ($b['unit_amount'] ?? 0);
    $interval   = (string) ($b['interval'] ?? '');
    $lookupKey  = (string) ($b['lookup_key'] ?? '');
    $nickname   = (string) ($b['nickname'] ?? '');
    $extras     = (array)  ($b['currency_options'] ?? []);

    if (!in_array($currency, ['sek', 'eur', 'usd'], true)) json_error('invalid_currency', 400);
    if ($amount <= 0)                                       json_error('invalid_amount',  400);
    if (!in_array($interval, ['month', 'year'], true))     json_error('invalid_interval', 400);

    // Sanitize currency_options: lower-case keys, intval amounts, only
    // permit known currencies. Drop the base currency if it appears in
    // the extras map — Stripe rejects that.
    $clean = [];
    foreach ($extras as $code => $val) {
        $code = strtolower((string) $code);
        if (!in_array($code, ['sek', 'eur', 'usd'], true)) continue;
        if ($code === $currency) continue;
        $val = (int) $val;
        if ($val > 0) $clean[$code] = $val;
    }

    try {
        $price = stripe_create_price(
            $productId, $currency, $amount, $interval,
            $clean,
            $lookupKey !== '' ? $lookupKey : null,
            $nickname  !== '' ? $nickname  : null
        );
    } catch (Throwable $e) {
        error_log('[shortly:admin] stripe create price failed: ' . $e->getMessage());
        json_error('stripe_error: ' . $e->getMessage(), 502);
    }
    json_response($price);
}

function api_admin_plans_archive(string $priceId): void {
    require_admin();
    try { $r = stripe_archive_price($priceId); }
    catch (Throwable $e) {
        error_log('[shortly:admin] stripe archive failed: ' . $e->getMessage());
        json_error('stripe_error: ' . $e->getMessage(), 502);
    }
    json_response($r);
}

function api_admin_plans_unarchive(string $priceId): void {
    require_admin();
    try { $r = stripe_unarchive_price($priceId); }
    catch (Throwable $e) {
        error_log('[shortly:admin] stripe unarchive failed: ' . $e->getMessage());
        json_error('stripe_error: ' . $e->getMessage(), 502);
    }
    json_response($r);
}

function api_admin_stats(): void {
    require_admin();
    $now = now_ms();
    $win7  = $now - 7  * 86400 * 1000;
    $win30 = $now - 30 * 86400 * 1000;

    $total_all = (int) (db_get('SELECT COUNT(*) c FROM clicks')['c'] ?? 0);
    $total_30  = (int) (db_get('SELECT COUNT(*) c FROM clicks WHERE ts >= ?', [$win30])['c'] ?? 0);
    $total_7   = (int) (db_get('SELECT COUNT(*) c FROM clicks WHERE ts >= ?', [$win7])['c']  ?? 0);

    // Unique = distinct salted-hash. Hashes from before the salt was set
    // are NULL, so COUNT(DISTINCT) skips them silently. Not perfect — a
    // returning visitor on a new IP shows as two uniques — but matches
    // how every privacy-respecting analytics tool counts.
    $uniq_all = (int) (db_get('SELECT COUNT(DISTINCT ip_hash) c FROM clicks')['c'] ?? 0);
    $uniq_30  = (int) (db_get('SELECT COUNT(DISTINCT ip_hash) c FROM clicks WHERE ts >= ?', [$win30])['c'] ?? 0);
    $uniq_7   = (int) (db_get('SELECT COUNT(DISTINCT ip_hash) c FROM clicks WHERE ts >= ?', [$win7])['c']  ?? 0);

    // Country rollup — last 30 days, top 20. Rows with NULL country
    // (private IPs, lookup failed, pre-2026-05-16 history) are reported
    // as a single "unknown" bucket so the picture stays honest.
    $rows = db_all(
        'SELECT COALESCE(country, "??") AS country, COUNT(*) AS count
         FROM clicks WHERE ts >= ?
         GROUP BY country
         ORDER BY count DESC
         LIMIT 20',
        [$win30]
    );

    json_response([
        'totals'    => ['all_time' => $total_all, 'days_30' => $total_30, 'days_7' => $total_7],
        'unique'    => ['all_time' => $uniq_all,  'days_30' => $uniq_30,  'days_7' => $uniq_7 ],
        'countries' => $rows,
    ]);
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
    $shouldRecord = rate_limit_check('click:ip:' . $ip, 600, 60);

    // Send the redirect first, then close the response to the user before
    // doing the click insert (and its third-party geo lookup). The visitor
    // sees the redirect in single-digit ms; the ~hundreds-of-ms GeoIP call
    // happens after they're already gone. Falls back to in-order when
    // fastcgi_finish_request() isn't available (non-FPM SAPIs).
    header('Location: ' . $link['target'], true, 302);
    if ($shouldRecord && function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        links_record_click((int) $link['id']);
        exit;
    }
    if ($shouldRecord) links_record_click((int) $link['id']);
    exit;
}
