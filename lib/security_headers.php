<?php
declare(strict_types=1);

// Per-request CSP nonce. Embed on every inline script tag we emit (using
// the nonce attribute, set from this helper) so the policy can drop
// 'unsafe-inline' from script-src — XSS payloads injected later can no
// longer execute. Inline styles still need 'unsafe-inline' in style-src
// for now since we have many style attributes; tightening that is a
// follow-up.
function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        // 16 random bytes = 128 bits, base64-encoded for HTML attribute use.
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
    return $nonce;
}

// Send security-relevant response headers. Called early in index.php so every
// dynamic response (HTML page, JSON API, redirect) gets them. Static assets
// (CSS/JS/SVG) are served by Apache directly and bypass PHP — they don't
// carry these headers, but nothing in shortly's threat model relies on
// header coverage for those.
function send_security_headers(): void {
    // No iframe embedding — there's no use case and clickjacking on /login
    // would let an attacker overlay a fake credential field.
    header('X-Frame-Options: DENY');

    // Browser must respect declared Content-Type, no MIME-sniffing.
    header('X-Content-Type-Options: nosniff');

    // Don't leak the slug path to the redirect target. strict-origin sends
    // only the origin cross-site; when-cross-origin keeps full referrer for
    // same-origin requests so internal stats keep working.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Opt out of every powerful feature — none are used.
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');

    // HSTS only when the request actually arrived over HTTPS — pinning it on
    // a dev box that's HTTP-only would break the app the next time someone
    // visits it. is_request_https() also handles the proxy-terminated case.
    if (is_request_https()) {
        // 6 months to start. Bump to 31536000 + preload once stable in prod.
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }

    // CSP. Inline scripts use per-request nonces (see csp_nonce above) so
    // script-src can drop 'unsafe-inline' entirely. Inline styles still need
    // 'unsafe-inline' in style-src — many style attributes are sprinkled
    // through the views; nonceing those is a follow-up. External resources
    // whitelisted below match exactly what _header.php and _footer.php load.
    // Turnstile widget + iframe live on challenges.cloudflare.com. Only
    // whitelist them when Turnstile is actually configured — otherwise we
    // keep the policy tighter on instances that don't use the bot challenge.
    $tsConfigured = function_exists('turnstile_is_configured') && turnstile_is_configured();
    $tsScript = $tsConfigured ? ' https://challenges.cloudflare.com' : '';
    $tsFrame  = $tsConfigured ? "frame-src https://challenges.cloudflare.com" : "frame-src 'none'";

    // AdSense pulls scripts, images, beacons, and iframes from a fixed set of
    // Google ad-serving origins. Whitelist them only when actually configured
    // so non-monetised instances keep the tighter policy.
    $adsOn = function_exists('adsense_is_configured') && adsense_is_configured();
    $adsScript  = $adsOn ? ' https://pagead2.googlesyndication.com https://*.googlesyndication.com https://*.google.com https://*.doubleclick.net https://adservice.google.com' : '';
    $adsImg     = $adsOn ? ' https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com https://*.gstatic.com' : '';
    $adsConnect = $adsOn ? ' https://pagead2.googlesyndication.com https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com' : '';
    $adsFrameOrigins = $adsOn ? ' https://googleads.g.doubleclick.net https://tpc.googlesyndication.com https://www.google.com' : '';
    // Splice ad frame origins into whichever frame-src directive we settled on above.
    if ($adsOn) {
        $tsFrame = ($tsConfigured
            ? "frame-src https://challenges.cloudflare.com"
            : "frame-src") . $adsFrameOrigins;
    }

    // Per-request nonce — every inline <script> we render carries this in
    // its nonce attribute. Drops 'unsafe-inline' from script-src so any
    // XSS that lands in an attribute or text node can't execute.
    $nonce = csp_nonce();

    // Allow the consent beacon target (if configured) through connect-src so
    // navigator.sendBeacon doesn't get blocked by CSP.
    $beaconUrl = (string) (config()['consent_beacon_url'] ?? '');
    $beaconOrigin = '';
    if ($beaconUrl !== '') {
        $parts = parse_url($beaconUrl);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $beaconOrigin = ' ' . $parts['scheme'] . '://' . $parts['host'];
        }
    }

    $csp = [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}'" . $tsScript . $adsScript,
        "style-src 'self' 'unsafe-inline' https://rsms.me https://fonts.googleapis.com",
        "font-src 'self' https://rsms.me https://fonts.gstatic.com",
        "img-src 'self' data:" . $adsImg,
        "connect-src 'self'" . $beaconOrigin . $adsConnect,
        $tsFrame,
        "frame-ancestors 'none'",
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}
