<?php
declare(strict_types=1);

// Best-effort IP → country lookup for the admin stats widget.
//
// Privacy note: this is the *only* place in shortly where a raw IP leaves
// the host. The result (a 2-letter country code) is stored on clicks.country
// — the raw IP itself is still hashed before persistence (see ip_hash()).
// To turn the lookup off entirely, set 'geo_lookup_disabled' => true in
// config.php; clicks.country will stay NULL for new rows.
//
// Two providers, tried in order:
//   1. HTTP_CF_IPCOUNTRY header — free + zero latency when behind Cloudflare.
//   2. ipinfo.io /<ip>/country — ~1k free lookups/day per source IP, no
//      key needed for plain country. Hard 800 ms timeout so a slow upstream
//      can't stall the click-record path even if fastcgi_finish_request
//      isn't available.
//
// Fail-silent: returns null on any error so the click still gets logged.

function geo_country_for_ip(?string $ip): ?string {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
    if (!empty(config()['geo_lookup_disabled'])) return null;

    // Skip private / loopback / reserved ranges — they'd just 404 at the
    // provider and cost a roundtrip. FILTER_FLAG_NO_PRIV/NO_RES rejects
    // 10/8, 172.16/12, 192.168/16, 127/8, fe80::/10, fc00::/7, etc.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RES)) return null;

    // Per-request memoisation. Click logging happens once per request, but
    // if anything else ever calls into this for the same IP we don't pay
    // for it twice.
    static $cache = [];
    if (array_key_exists($ip, $cache)) return $cache[$ip];

    // 1. CDN-provided header (Cloudflare / some other proxies).
    $hdr = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
    if (is_string($hdr) && preg_match('/^[A-Za-z]{2}$/', $hdr) && strtoupper($hdr) !== 'XX') {
        return $cache[$ip] = strtoupper($hdr);
    }

    // 2. ipinfo.io free endpoint.
    $ch = curl_init('https://ipinfo.io/' . urlencode($ip) . '/country');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 400,
        CURLOPT_TIMEOUT_MS        => 800,
        CURLOPT_SSL_VERIFYPEER    => true,
        CURLOPT_SSL_VERIFYHOST    => 2,
        CURLOPT_USERAGENT         => 'shortly-geo/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $code !== 200) return $cache[$ip] = null;
    $cc = strtoupper(trim($body));
    if (!preg_match('/^[A-Z]{2}$/', $cc)) return $cache[$ip] = null;
    return $cache[$ip] = $cc;
}
