<?php
declare(strict_types=1);

// Minimal PayPal Subscriptions API client.
//
// Stripe Checkout cannot host PayPal subscriptions (Stripe's PayPal
// integration is one-time-payments only), so this lib runs the second,
// parallel billing path. Schema and webhook plumbing in lib/billing.php
// dispatches between providers based on `subscriptions.provider`.
//
// Reference:
//   https://developer.paypal.com/docs/api/subscriptions/v1/
//
// All functions throw RuntimeException on HTTP / API errors so callers can
// 5xx without branching on specific failure modes — same convention as
// lib/stripe.php.

function paypal_is_configured(): bool {
    $cfg = config();
    foreach (['paypal_client_id', 'paypal_secret', 'paypal_plan_monthly', 'paypal_plan_yearly'] as $k) {
        if (empty($cfg[$k])) return false;
    }
    return true;
}

function paypal_base_url(): string {
    $mode = (string) (config()['paypal_mode'] ?? 'sandbox');
    return $mode === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

// Cache the OAuth token in an APCu-or-static slot. PayPal tokens are valid
// for ~9 hours; we refresh at 8h to be safe. No persistent store needed —
// a missed cache hit just costs one extra HTTP call.
function paypal_access_token(): string {
    static $cached = null;
    static $cachedUntil = 0;
    if ($cached !== null && time() < $cachedUntil) return $cached;

    $cfg = config();
    $clientId = (string) ($cfg['paypal_client_id'] ?? '');
    $secret   = (string) ($cfg['paypal_secret']    ?? '');
    if ($clientId === '' || $secret === '') throw new RuntimeException('paypal_not_configured');

    $ch = curl_init(paypal_base_url() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => $clientId . ':' . $secret,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log("[shortly:paypal] token transport: $err");
        throw new RuntimeException('paypal_network_error');
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data) || $code >= 400 || empty($data['access_token'])) {
        error_log("[shortly:paypal] token $code: " . substr((string) $body, 0, 200));
        throw new RuntimeException('paypal_token_error');
    }
    $cached     = (string) $data['access_token'];
    $cachedUntil = time() + min(28800, (int) ($data['expires_in'] ?? 28800) - 300);
    return $cached;
}

// Low-level request helper. $extraHeaders may include the PayPal-Request-Id
// header for idempotency on POST /subscriptions etc.
function paypal_request(string $method, string $path, array $body = [], array $extraHeaders = []): array {
    $token = paypal_access_token();
    $url = paypal_base_url() . $path;
    $ch = curl_init();
    $headers = array_merge([
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($method !== 'GET' && !empty($body)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        error_log("[shortly:paypal] $method $path transport: $err");
        throw new RuntimeException('paypal_network_error');
    }
    // 204 No Content (e.g. successful cancel) returns empty body.
    if ($res === '' && $code >= 200 && $code < 300) return [];

    $data = json_decode((string) $res, true);
    if ($code >= 400) {
        $msg = is_array($data) && !empty($data['message']) ? $data['message'] : 'unknown';
        error_log("[shortly:paypal] $method $path $code: $msg");
        throw new RuntimeException('paypal_api_error: ' . $msg);
    }
    return is_array($data) ? $data : [];
}

// Create a subscription and return the redirect URL the user should be
// sent to for approval. `custom_id` carries the user.id back through the
// webhook so we can resolve the local user without a separate lookup.
function paypal_create_subscription(string $planId, string $userEmail, int $userId, string $returnUrl, string $cancelUrl): array {
    $body = [
        'plan_id' => $planId,
        'custom_id' => (string) $userId,
        'subscriber' => [
            'email_address' => $userEmail,
        ],
        'application_context' => [
            'brand_name'      => (string) (config()['app_name'] ?? 'Shortly'),
            'user_action'     => 'SUBSCRIBE_NOW',
            'return_url'      => $returnUrl,
            'cancel_url'      => $cancelUrl,
            'shipping_preference' => 'NO_SHIPPING',
        ],
    ];
    // Idempotency: same user clicking twice within a few seconds shouldn't
    // create two PayPal subscriptions. Use the userId+plan as the key —
    // collision across users is impossible because the id is in the body.
    $reqId = 'shortly-' . $userId . '-' . $planId . '-' . dechex(time() / 60);
    $res = paypal_request('POST', '/v1/billing/subscriptions', $body, ['PayPal-Request-Id: ' . $reqId]);
    return $res;
}

function paypal_get_subscription(string $subscriptionId): array {
    return paypal_request('GET', '/v1/billing/subscriptions/' . urlencode($subscriptionId));
}

// Cancel a PayPal subscription. PayPal requires a reason string in the
// body — we pass a generic one; PayPal logs it for the merchant.
function paypal_cancel_subscription(string $subscriptionId, string $reason = 'User-initiated cancellation'): void {
    paypal_request('POST', '/v1/billing/subscriptions/' . urlencode($subscriptionId) . '/cancel',
        ['reason' => $reason]);
}

// Extract the redirect-to-PayPal URL from a subscription-create response.
// PayPal returns several links; we want rel="approve".
function paypal_approval_url(array $sub): ?string {
    foreach ((array) ($sub['links'] ?? []) as $link) {
        if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
            return (string) $link['href'];
        }
    }
    return null;
}

// Verify a webhook payload using PayPal's server-side verification
// endpoint. Doing it server-to-server is the supported / recommended
// path — replicating their cert chain locally is fragile and PayPal
// rotates certs without much notice.
function paypal_verify_webhook(string $rawBody, array $headers): bool {
    $webhookId = (string) (config()['paypal_webhook_id'] ?? '');
    if ($webhookId === '') return false;

    $payload = [
        'auth_algo'         => (string) ($headers['paypal-auth-algo']         ?? ''),
        'cert_url'          => (string) ($headers['paypal-cert-url']          ?? ''),
        'transmission_id'   => (string) ($headers['paypal-transmission-id']   ?? ''),
        'transmission_sig'  => (string) ($headers['paypal-transmission-sig']  ?? ''),
        'transmission_time' => (string) ($headers['paypal-transmission-time'] ?? ''),
        'webhook_id'        => $webhookId,
        'webhook_event'     => json_decode($rawBody, true) ?: new stdClass(),
    ];
    foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $k) {
        if ($payload[$k] === '') return false;
    }
    try {
        $res = paypal_request('POST', '/v1/notifications/verify-webhook-signature', $payload);
    } catch (Throwable $e) {
        error_log('[shortly:paypal] verify failed: ' . $e->getMessage());
        return false;
    }
    return ($res['verification_status'] ?? '') === 'SUCCESS';
}

// PayPal subscription statuses that grant Pro access. APPROVAL_PENDING
// and APPROVED (post-redirect but pre-payment) don't yet — the user
// becomes Pro only once the first payment clears (ACTIVE).
function paypal_status_grants_pro(string $status): bool {
    return $status === 'ACTIVE';
}

function paypal_plan_for_id(string $planId): ?string {
    $cfg = config();
    if ($planId === ($cfg['paypal_plan_monthly'] ?? null)) return 'monthly';
    if ($planId === ($cfg['paypal_plan_yearly']  ?? null)) return 'yearly';
    return null;
}

function paypal_plan_id_for(string $plan): ?string {
    $cfg = config();
    if ($plan === 'monthly') return $cfg['paypal_plan_monthly'] ?? null;
    if ($plan === 'yearly')  return $cfg['paypal_plan_yearly']  ?? null;
    return null;
}
