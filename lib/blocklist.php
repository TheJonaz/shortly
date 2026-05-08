<?php
declare(strict_types=1);

// Domain blocklist — a flat file at data/blocklist.txt with one host per
// line, lowercase. Populated by deploy/update-blocklist.sh from URLhaus +
// optional curated additions. Missing or empty file = no blocking.
//
// Lookup matches the host AND every parent domain so an entry like
// `evil.com` blocks `phish.sub.evil.com` too. Cached per-request via a
// static so we don't re-read the file on every check inside one request.

const BLOCKLIST_PATH = __DIR__ . '/../data/blocklist.txt';

function blocklist_load(): array {
    static $list = null;
    if ($list !== null) return $list;
    if (!is_readable(BLOCKLIST_PATH)) return $list = [];
    $lines = @file(BLOCKLIST_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return $list = [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        // URLhaus hostfile lines look like "0.0.0.0 evil.example.com" —
        // grab the rightmost token. Also accept plain hostnames.
        $parts = preg_split('/\s+/', $line);
        $host = strtolower(end($parts));
        if ($host === '0.0.0.0' || $host === 'localhost') continue;
        $out[$host] = true;
    }
    return $list = $out;
}

// Returns true if the given host (or any parent domain) is blocklisted.
function blocklist_contains_host(string $host): bool {
    $list = blocklist_load();
    if (!$list) return false;
    $h = strtolower($host);
    while ($h !== '' && str_contains($h, '.')) {
        if (isset($list[$h])) return true;
        $h = (string) substr($h, strpos($h, '.') + 1);
    }
    return false;
}

// Convenience: check a full URL. Returns null if URL doesn't parse, else
// true/false. (Callers should already have validated the URL beforehand.)
function blocklist_contains_url(string $url): ?bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || !is_string($host)) return null;
    return blocklist_contains_host($host);
}
