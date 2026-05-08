<?php
declare(strict_types=1);

// Google Safe Browsing v4 lookup (free tier: 10k req/day).
//
// Configuration:
//   'safebrowsing_api_key' => 'AIzaSy…'   // get from console.cloud.google.com
// Empty key = feature disabled (links_create skips the check).
//
// Verdicts cached in safebrowsing_cache for 24h to stay well under quota
// even at high create rates. Cache is keyed on the URL's sha256.
//
// Failure mode is fail-OPEN: if the Google API is down or misconfigured
// we treat the URL as clean rather than blocking legitimate users on
// transient infrastructure issues. The local URLhaus blocklist gives us
// an offline second line of defense.

const SAFEBROWSING_API_URL  = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';
const SAFEBROWSING_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

function safebrowsing_is_configured(): bool {
    return !empty(config()['safebrowsing_api_key']);
}

// Public entry. Returns true when the URL appears clean OR when we can't
// determine (no key, API failure). Returns false only on positive match
// against a Google threat list.
function safebrowsing_is_clean(string $url): bool {
    if (!safebrowsing_is_configured()) return true;

    $hash = hash('sha256', $url);
    $cached = db_get(
        'SELECT verdict, checked_at FROM safebrowsing_cache WHERE target_hash = ?',
        [$hash]
    );
    if ($cached
        && (int) $cached['checked_at'] > now_ms() - SAFEBROWSING_CACHE_TTL_MS) {
        return $cached['verdict'] === 'clean';
    }

    $clean = safebrowsing_api_check($url, (string) config()['safebrowsing_api_key']);
    safebrowsing_cache_put($hash, $clean);
    return $clean;
}

function safebrowsing_cache_put(string $hash, bool $clean): void {
    $now = now_ms();
    $verdict = $clean ? 'clean' : 'blocked';
    // UPSERT pattern that works on both SQLite and MySQL without driver-
    // specific syntax. Race-tolerant: another request may insert between
    // our DELETE and INSERT — INSERT then races as 23000 which we ignore.
    db_run('DELETE FROM safebrowsing_cache WHERE target_hash = ?', [$hash]);
    try {
        db_insert(
            'INSERT INTO safebrowsing_cache (target_hash, verdict, checked_at) VALUES (?, ?, ?)',
            [$hash, $verdict, $now]
        );
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e;
    }
}

function safebrowsing_api_check(string $url, string $apiKey): bool {
    $body = json_encode([
        'client' => ['clientId' => 'shortly', 'clientVersion' => '1.0'],
        'threatInfo' => [
            'threatTypes'      => ['MALWARE', 'SOCIAL_ENGINEERING',
                                   'UNWANTED_SOFTWARE',
                                   'POTENTIALLY_HARMFUL_APPLICATION'],
            'platformTypes'    => ['ANY_PLATFORM'],
            'threatEntryTypes' => ['URL'],
            'threatEntries'    => [['url' => $url]],
        ],
    ]);
    if ($body === false) return true;  // shouldn't happen — URL is ASCII-ish

    $ch = curl_init(SAFEBROWSING_API_URL . '?key=' . urlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code !== 200) {
        // Fail-open. Log so we notice persistent quota / outage issues.
        error_log('[shortly:safebrowsing] api error code=' . $code);
        return true;
    }
    $data = json_decode((string) $resp, true);
    if (!is_array($data)) return true;
    // Empty body == clean. Any matches[] entry == threat.
    return empty($data['matches']);
}
