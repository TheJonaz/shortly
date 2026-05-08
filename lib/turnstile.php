<?php
declare(strict_types=1);

// Cloudflare Turnstile bot-mitigation. Free, GDPR-friendly, privacy-aware
// alternative to reCAPTCHA — produces a one-shot token in the browser, we
// verify it server-side against Cloudflare's siteverify endpoint.
//
// Configuration (config.php):
//   'turnstile_site_key' => '0x4AAA…'  // public key, embedded in HTML
//   'turnstile_secret'   => '0x4AAA…'  // server secret, never sent to client
//
// Both blank → feature disabled (used only on dev). One blank, the other
// set → misconfiguration; we treat as disabled but warn so partial setup
// doesn't accidentally let bots through.

const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

function turnstile_is_configured(): bool {
    $cfg = config();
    $site = (string) ($cfg['turnstile_site_key'] ?? '');
    $secret = (string) ($cfg['turnstile_secret'] ?? '');
    if ($site === '' && $secret === '') return false;
    if ($site === '' || $secret === '') {
        error_log('[shortly:turnstile] partial config — both site_key and secret required');
        return false;
    }
    return true;
}

function turnstile_site_key(): string {
    return (string) (config()['turnstile_site_key'] ?? '');
}

// Verify the client-supplied token. Returns true on success, false on any
// failure (invalid, replayed, expired, network error). On a verifiable
// rejection, logs the error_codes Cloudflare returned for ops debugging.
function turnstile_verify(string $token, ?string $clientIp = null): bool {
    if ($token === '') return false;
    $secret = (string) (config()['turnstile_secret'] ?? '');
    if ($secret === '') return false;

    $params = ['secret' => $secret, 'response' => $token];
    if ($clientIp !== null && $clientIp !== '') {
        $params['remoteip'] = $clientIp;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => TURNSTILE_VERIFY_URL,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        error_log('[shortly:turnstile] verify transport failed code=' . $code);
        return false;
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) return false;

    if (empty($data['success'])) {
        $codes = is_array($data['error-codes'] ?? null)
            ? implode(',', $data['error-codes'])
            : 'unknown';
        // Don't log the token itself — it's short-lived but still client
        // input. error-codes are vendor-defined enums, safe to log.
        error_log('[shortly:turnstile] verify rejected: ' . $codes);
        return false;
    }
    return true;
}
