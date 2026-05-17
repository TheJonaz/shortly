<?php
declare(strict_types=1);

function config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/../config.php';
    return $cfg;
}

function public_url(): string {
    return rtrim(config()['public_url'], '/');
}

// URL-path prefix for the app, e.g. '' (root) or '/shortly'. Use this when
// emitting internal paths in HTML and Location headers.
function base_path(): string {
    static $bp = null;
    if ($bp === null) {
        $raw = (string) (config()['base_path'] ?? '');
        $bp  = $raw === '' || $raw === '/' ? '' : '/' . trim($raw, '/');
    }
    return $bp;
}

function now_ms(): int {
    return (int) (microtime(true) * 1000);
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $code, int $status = 400): void {
    json_response(['error' => $code], $status);
}

// Read at most $maxBytes from the request body. Anything larger short-circuits
// to 413 — without this cap, php://input is bounded only by post_max_size
// (8 MB default), which lets a single anonymous client soak up RAM per request.
function read_json_body(int $maxBytes = 65536): array {
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > $maxBytes) json_error('payload_too_large', 413);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function client_ip(): ?string {
    // Only honour proxy headers when explicitly trusted — otherwise any client
    // can spoof X-Forwarded-For and poison the click log / rate-limit keys.
    if (!empty(config()['trust_proxy'])) {
        // CF-Connecting-IP first: Cloudflare always overwrites it with the
        // real client IP. X-Forwarded-For can be appended by intermediate
        // proxies (so the LEFTMOST entry is the original) and Real-IP is
        // an nginx-set fallback.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null;
}

function ip_hash(?string $ip): ?string {
    if (!$ip) return null;
    $salt = config()['ip_salt'] ?? 'shortly';
    return substr(hash('sha256', $ip . $salt), 0, 16);
}

function generate_slug(int $len = 7): string {
    $alphabet = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $out = '';
    $n = strlen($alphabet);
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $n - 1)];
    }
    return $out;
}

const RESERVED_SLUGS = [
    'api', 'app', 'login', 'register', 'logout', 'dashboard',
    'p', 'static', 'public', 'assets', 'health', 'admin',
    'css', 'js', 'favicon.svg', 'favicon.ico', 'robots.txt',
    'index.php', '.htaccess',
];

function is_valid_slug(string $slug): bool {
    return (bool) preg_match('/^[A-Za-z0-9_-]{2,32}$/', $slug);
}

function pick_fresh_slug(): string {
    for ($i = 0; $i < 8; $i++) {
        // Lowercased to stay paritetsskt med MySQL's case-insensitive lookup
        // (see links_create for context). The full 56-char alphabet still
        // produces enough entropy after lowercasing — 33^7 ≈ 4.3B.
        $s = strtolower(generate_slug());
        if (in_array($s, RESERVED_SLUGS, true)) continue;
        $exists = db_get('SELECT 1 AS x FROM links WHERE slug = ?', [$s]);
        if (!$exists) {
            // Warn early — at >5 retries the alphabet is starting to fill.
            if ($i >= 5) error_log("[shortly] slug collision: succeeded after $i retries");
            return $s;
        }
    }
    error_log('[shortly] slug_generation_failed after 8 attempts — bump generate_slug() length');
    throw new RuntimeException('slug_generation_failed');
}

function parse_expiry($value): ?int {
    if ($value === null || $value === '' || $value === false) return null;
    if (is_numeric($value)) {
        $ts = (int) $value;
        // Treat plausible second-precision values (anything below year 2286 in s)
        // as seconds; ms timestamps are always ≥ ~1e12. This makes the API
        // forgiving whether the caller sends s or ms.
        if ($ts > 0 && $ts < 10_000_000_000) $ts *= 1000;
    } else {
        $ts = strtotime((string) $value);
        if ($ts === false) throw new InvalidArgumentException('invalid_expiry');
        $ts *= 1000;
    }
    if ($ts < now_ms() - 60_000) throw new InvalidArgumentException('expiry_in_past');
    // Cap at 10 years out — anything further is almost certainly user error
    // (bad timezone math, accidental year 9999) and just clutters the table.
    if ($ts > now_ms() + 10 * 365 * 24 * 3600 * 1000) {
        throw new InvalidArgumentException('expiry_too_far');
    }
    return $ts;
}

function validate_url(string $url): string {
    // Cap below the DB column width (links.target is VARCHAR(2048) on MySQL).
    if (strlen($url) > 2048) {
        throw new InvalidArgumentException('url_too_long');
    }
    // Defense in depth — modern header() blocks CRLF injection, but reject
    // any control char early so weird inputs never reach Location:.
    if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
        throw new InvalidArgumentException('invalid_url');
    }
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
        throw new InvalidArgumentException('invalid_url');
    }
    if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
        throw new InvalidArgumentException('invalid_protocol');
    }
    // Reject embedded credentials (https://attacker:x@victim.example/) — a
    // common phishing trick where the browser displays only the path. The
    // owner can still link to the same resource without the userinfo.
    if (!empty($parsed['user']) || !empty($parsed['pass'])) {
        throw new InvalidArgumentException('invalid_url');
    }
    // Reject raw-IP hosts. Phishing campaigns commonly point at attacker-
    // controlled IPs since they dodge domain-based blocklists. Real users
    // who need to short-link a LAN/dev IP are an edge case we accept losing.
    // parse_url returns IPv6 hosts wrapped in [brackets] — strip them
    // before validating so [2001:db8::1] gets caught too.
    if (!empty($parsed['host'])) {
        $rawHost = trim($parsed['host'], '[]');
        if (filter_var($rawHost, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('url_ip_host_not_allowed');
        }

        // Reject hostnames that resolve to internal networks: bare hostnames
        // (no dot — `printer`, `intranet`), explicit loopback, and the IETF
        // reserved suffixes used for non-public namespaces. Without this,
        // shortlinks could point at LAN devices, dev boxes, or
        // service-discovery names that only resolve inside someone's network.
        $host = strtolower($rawHost);
        if ($host === 'localhost' || strpos($host, '.') === false) {
            throw new InvalidArgumentException('url_internal_host_not_allowed');
        }
        $reservedSuffixes = ['.localhost', '.local', '.internal', '.intranet', '.lan', '.home', '.home.arpa', '.corp', '.private'];
        foreach ($reservedSuffixes as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                throw new InvalidArgumentException('url_internal_host_not_allowed');
            }
        }
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('invalid_url');
    }
    return $url;
}

function render_view(string $name, array $vars = []): void {
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../views/' . $name . '.php';
}
