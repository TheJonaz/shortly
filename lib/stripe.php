<?php
declare(strict_types=1);

// Minimal Stripe client. We don't pull stripe-php — the half-dozen calls we
// make are all simple form-encoded POST/GET, and signature verification is
// 20 lines of HMAC.
//
// All functions throw RuntimeException on transport / API errors so callers
// can return 5xx without thinking about specific failure modes. Validation
// errors (bad params we sent) come back as the same exception type — Stripe
// is stricter than us so prefer to validate inputs before calling.

const STRIPE_API_BASE   = 'https://api.stripe.com/v1';
const STRIPE_API_VERSION = '2024-04-10';
const STRIPE_SIG_TOLERANCE_S = 300;  // 5 min — Stripe default

// Verify a Stripe-Signature header against the raw request body. Returns
// true on match. Constant-time compare; rejects out-of-tolerance timestamps
// to prevent replay of captured webhooks.
function stripe_verify_signature(string $payload, string $sigHeader, string $secret): bool {
    if ($secret === '' || $sigHeader === '') return false;
    $items = explode(',', $sigHeader);
    $timestamp = null;
    $sigs = [];
    foreach ($items as $item) {
        $parts = explode('=', $item, 2);
        if (count($parts) !== 2) continue;
        if ($parts[0] === 't')        $timestamp = (int) $parts[1];
        elseif ($parts[0] === 'v1')   $sigs[] = $parts[1];
    }
    if (!$timestamp || !$sigs) return false;
    if (abs(time() - $timestamp) > STRIPE_SIG_TOLERANCE_S) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

function stripe_request(string $method, string $path, array $params = []): array {
    $secret = (string) (config()['stripe_secret_key'] ?? '');
    if ($secret === '') throw new RuntimeException('stripe_not_configured');

    $url = STRIPE_API_BASE . $path;
    $ch  = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $secret . ':',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Stripe-Version: ' . STRIPE_API_VERSION,
        ],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    } elseif ($method === 'GET') {
        $opts[CURLOPT_URL] = $url . ($params ? '?' . http_build_query($params) : '');
    } else {
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }
    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log("[shortly:stripe] transport: $err");
        throw new RuntimeException('stripe_network_error');
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) throw new RuntimeException('stripe_invalid_response');
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? 'unknown';
        $type = $data['error']['type'] ?? 'unknown';
        error_log("[shortly:stripe] $code $type: $msg");
        throw new RuntimeException('stripe_api_error: ' . $msg);
    }
    return $data;
}

// Higher-level helpers — keep call sites in lib/billing.php tidy.

function stripe_create_checkout_session(string $priceId, string $currency, string $customerEmail, int $userId, string $successUrl, string $cancelUrl, ?string $existingCustomerId): array {
    // `currency` tells Stripe which entry of the Price's currency_options to
    // charge in. Klarna is only offered when the session currency matches a
    // Klarna-supported region (sek/eur); usd sessions fall back to cards.
    $params = [
        'mode'                          => 'subscription',
        'currency'                      => $currency,
        'line_items[0][price]'          => $priceId,
        'line_items[0][quantity]'       => 1,
        'success_url'                   => $successUrl,
        'cancel_url'                    => $cancelUrl,
        'client_reference_id'           => (string) $userId,
        'allow_promotion_codes'         => 'true',
    ];
    if ($existingCustomerId) {
        $params['customer'] = $existingCustomerId;
    } else {
        // Stripe creates a customer automatically in subscription mode —
        // customer_creation is a payment-mode-only parameter and adding it
        // here returns 400. The new customer's id arrives in the
        // checkout.session.completed webhook, where we persist it to
        // users.stripe_customer_id for the billing portal flow.
        $params['customer_email'] = $customerEmail;
    }
    return stripe_request('POST', '/checkout/sessions', $params);
}

function stripe_create_portal_session(string $customerId, string $returnUrl): array {
    return stripe_request('POST', '/billing_portal/sessions', [
        'customer'   => $customerId,
        'return_url' => $returnUrl,
    ]);
}

function stripe_get_subscription(string $subscriptionId): array {
    return stripe_request('GET', '/subscriptions/' . urlencode($subscriptionId));
}

// ─── Admin: Product + Price management ─────────────────────────────────

// List Prices, optionally filtered by Product. Stripe returns up to 100
// per page; we expand recurring + product so the admin UI doesn't have
// to chase IDs. Caller passes false to include archived prices.
function stripe_list_prices(?string $productId = null, bool $activeOnly = true): array {
    $params = ['limit' => 100, 'expand[]' => 'data.product'];
    if ($productId)  $params['product'] = $productId;
    if ($activeOnly) $params['active']  = 'true';
    return stripe_request('GET', '/prices', $params);
}

// Create a new Price. `currencyOptions` maps secondary-currency codes to
// integer minor units (e.g. ['eur' => 499, 'usd' => 500]) and becomes
// the Price's currency_options[] payload. Set lookup_key (must be unique
// account-wide) so callers can resolve "the current monthly" without
// hard-coding an ID.
function stripe_create_price(
    string $productId,
    string $currency,
    int $unitAmount,
    string $interval,
    array $currencyOptions = [],
    ?string $lookupKey = null,
    ?string $nickname = null
): array {
    $params = [
        'product'              => $productId,
        'currency'             => $currency,
        'unit_amount'          => $unitAmount,
        'recurring[interval]'  => $interval,
        'transfer_lookup_key'  => 'true',
    ];
    if ($lookupKey) $params['lookup_key'] = $lookupKey;
    if ($nickname)  $params['nickname']   = $nickname;
    foreach ($currencyOptions as $code => $amount) {
        $params["currency_options[{$code}][unit_amount]"] = (int) $amount;
    }
    return stripe_request('POST', '/prices', $params);
}

// Archive a Price (Stripe doesn't allow hard-delete on a Price). Existing
// subscriptions on archived prices continue to bill — Stripe only refuses
// NEW Checkouts against them.
function stripe_archive_price(string $priceId): array {
    return stripe_request('POST', '/prices/' . urlencode($priceId), ['active' => 'false']);
}

function stripe_unarchive_price(string $priceId): array {
    return stripe_request('POST', '/prices/' . urlencode($priceId), ['active' => 'true']);
}

// Update mutable fields on a Price. Stripe forbids changing amount,
// currency or recurring on an existing Price — to "raise the price"
// the canonical pattern is: archive old, create new with same
// lookup_key (transfer_lookup_key handles the swap atomically).
function stripe_update_price(string $priceId, array $fields): array {
    $params = [];
    foreach ($fields as $k => $v) {
        if ($v === null) continue;
        $params[$k] = $v;
    }
    return stripe_request('POST', '/prices/' . urlencode($priceId), $params);
}

function stripe_list_products(): array {
    return stripe_request('GET', '/products', ['limit' => 100, 'active' => 'true']);
}
