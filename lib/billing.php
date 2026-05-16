<?php
declare(strict_types=1);

// Membership billing — orchestrates Stripe Checkout + Portal flows and
// translates webhook events into users.tier + subscriptions table updates.
//
// Authority model:
//   - users.tier is the source of truth for access checks (require_tier)
//   - subscriptions row is the audit log; populated by webhooks
//   - the two are always updated together inside billing_apply_subscription()

const BILLING_PLAN_MONTHLY = 'monthly';
const BILLING_PLAN_YEARLY  = 'yearly';

// Stripe statuses that grant Pro access. Anything else (canceled, past_due,
// unpaid, incomplete, incomplete_expired, paused) downgrades to free.
function billing_status_grants_pro(string $status): bool {
    return $status === 'active' || $status === 'trialing';
}

// Returns true only when ALL Stripe config is filled in. Lets the UI hide
// the upgrade CTA on instances where billing isn't set up, and lets the
// API return a clear 503 instead of a misleading "invalid plan".
function billing_is_configured(): bool {
    $cfg = config();
    foreach (['stripe_secret_key', 'stripe_webhook_secret',
              'stripe_price_monthly', 'stripe_price_yearly'] as $k) {
        if (empty($cfg[$k])) return false;
    }
    return true;
}

// Translate plan label → Stripe price id.
function billing_price_for_plan(string $plan): ?string {
    $cfg = config();
    if ($plan === BILLING_PLAN_MONTHLY) return $cfg['stripe_price_monthly'] ?? null;
    if ($plan === BILLING_PLAN_YEARLY)  return $cfg['stripe_price_yearly']  ?? null;
    return null;
}

// Reverse map: Stripe price id → our plan label. Used in webhooks where we
// only have the price object.
function billing_plan_for_price(?string $priceId): ?string {
    if (!$priceId) return null;
    $cfg = config();
    if ($priceId === ($cfg['stripe_price_monthly'] ?? null)) return BILLING_PLAN_MONTHLY;
    if ($priceId === ($cfg['stripe_price_yearly']  ?? null)) return BILLING_PLAN_YEARLY;
    return null;
}

// Start a checkout. Returns the URL for the JS to redirect to. The user must
// be signed in (anonymous can't subscribe to anything yet).
// $currency selects which entry of the Price's currency_options to charge in
// (and gates Klarna availability — see stripe_create_checkout_session).
function billing_start_checkout(array $user, string $plan, string $currency): string {
    if (!billing_is_configured()) throw new RuntimeException('billing_unavailable');
    $priceId = billing_price_for_plan($plan);
    if (!$priceId) throw new InvalidArgumentException('invalid_plan');
    if (!in_array($currency, CURRENCIES, true)) throw new InvalidArgumentException('invalid_currency');

    $base = public_url();
    $successUrl = $base . '/app?upgraded=1';
    $cancelUrl  = $base . '/app?canceled=1';

    $existing = db_get('SELECT stripe_customer_id FROM users WHERE id = ?', [$user['id']]);
    $customerId = $existing['stripe_customer_id'] ?? null;

    $session = stripe_create_checkout_session(
        $priceId,
        $currency,
        (string) $user['email'],
        (int) $user['id'],
        $successUrl,
        $cancelUrl,
        $customerId ?: null
    );
    if (empty($session['url'])) throw new RuntimeException('stripe_no_url');
    return (string) $session['url'];
}

// PayPal counterpart: start a subscription and return the approval URL.
// PayPal uses pre-defined Plans (created in PayPal Dashboard or via API)
// instead of Stripe's Price objects; plan ids live in config under
// paypal_plan_{monthly,yearly}.
function billing_start_paypal(array $user, string $plan): string {
    if (!paypal_is_configured()) throw new RuntimeException('paypal_unavailable');
    $planId = paypal_plan_id_for($plan);
    if (!$planId) throw new InvalidArgumentException('invalid_plan');

    $base = public_url();
    $sub = paypal_create_subscription(
        $planId,
        (string) $user['email'],
        (int) $user['id'],
        $base . '/app?upgraded=1',
        $base . '/app?canceled=1'
    );
    $url = paypal_approval_url($sub);
    if (!$url) throw new RuntimeException('paypal_no_approval_url');

    // Persist the subscription id immediately. The webhook will arrive
    // later (after the user approves on paypal.com) and fill in status/
    // plan/period; this row lets us recover the user-subscription mapping
    // even if a webhook is lost.
    if (!empty($sub['id'])) {
        db_run('UPDATE users SET paypal_subscription_id = ? WHERE id = ?',
            [(string) $sub['id'], (int) $user['id']]);
    }
    return $url;
}

// Apply a PayPal subscription state to the local subscriptions row + tier.
// Mirrors billing_apply_subscription() (the Stripe version). The fetched
// subscription object is the source of truth — webhooks tell us "something
// changed", we re-fetch to get the canonical state.
function billing_apply_paypal_subscription(int $userId, array $sub): void {
    $now    = now_ms();
    $status = (string) ($sub['status'] ?? 'UNKNOWN');
    $subId  = (string) ($sub['id'] ?? '');
    if ($subId === '') return;

    $planId = (string) ($sub['plan_id'] ?? '');
    $plan   = paypal_plan_for_id($planId);

    // PayPal returns next_billing_time as ISO-8601. Convert to ms epoch
    // to share the column type with the Stripe path.
    $periodEnd = null;
    $next = $sub['billing_info']['next_billing_time'] ?? null;
    if ($next) {
        $ts = strtotime((string) $next);
        if ($ts) $periodEnd = $ts * 1000;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = db_get('SELECT user_id FROM subscriptions WHERE user_id = ?', [$userId]);
        if ($existing) {
            db_run(
                'UPDATE subscriptions SET stripe_subscription_id = ?, provider = ?,
                        status = ?, plan = ?, current_period_end = ?,
                        cancel_at_period_end = ?, updated_at = ?
                 WHERE user_id = ?',
                [$subId, 'paypal', strtolower($status), $plan, $periodEnd,
                 in_array($status, ['CANCELLED','SUSPENDED','EXPIRED'], true) ? 1 : 0,
                 $now, $userId]
            );
        } else {
            db_run(
                'INSERT INTO subscriptions (user_id, stripe_subscription_id, provider, status, plan,
                        current_period_end, cancel_at_period_end, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $subId, 'paypal', strtolower($status), $plan, $periodEnd, 0, $now, $now]
            );
        }
        $newTier = paypal_status_grants_pro($status) ? 'pro' : 'free';
        db_run('UPDATE users SET tier = ? WHERE id = ?', [$newTier, $userId]);
        if ($newTier === 'pro') {
            db_run(
                'UPDATE links SET expires_at = NULL, expires_auto = 0
                 WHERE user_id = ? AND expires_auto = 1',
                [$userId]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Dispatch a webhook event from PayPal. Signature must already be verified.
function billing_handle_paypal_webhook(array $event): void {
    $type     = (string) ($event['event_type'] ?? '');
    $resource = $event['resource'] ?? [];
    if (!is_array($resource)) return;

    // Only billing-subscription events change tier state. Payment-sale
    // events ("PAYMENT.SALE.COMPLETED") are informational here — we'd
    // already have flipped tier to Pro on the ACTIVATED event.
    if (!str_starts_with($type, 'BILLING.SUBSCRIPTION.')) return;

    // `custom_id` was the user.id we stuffed into the create payload.
    $userId = isset($resource['custom_id']) ? (int) $resource['custom_id'] : 0;
    if (!$userId) {
        // Some BILLING.SUBSCRIPTION events strip custom_id; fall back to
        // a lookup against users.paypal_subscription_id.
        $subId = (string) ($resource['id'] ?? '');
        if ($subId === '') return;
        $row = db_get('SELECT id FROM users WHERE paypal_subscription_id = ?', [$subId]);
        if (!$row) throw new RuntimeException('paypal_user_not_linked:' . $subId);
        $userId = (int) $row['id'];
    }

    // Re-fetch authoritative state — webhook payload may be stale.
    $subId = (string) ($resource['id'] ?? '');
    try {
        $fresh = paypal_get_subscription($subId);
    } catch (Throwable $e) {
        error_log('[shortly:paypal] webhook refetch failed for ' . $subId . ': ' . $e->getMessage());
        $fresh = $resource;  // fall back to the payload we got
    }
    billing_apply_paypal_subscription($userId, $fresh);
}

// Start a billing portal session for an existing subscriber.
function billing_start_portal(array $user): string {
    if (!billing_is_configured()) throw new RuntimeException('billing_unavailable');
    $row = db_get('SELECT stripe_customer_id FROM users WHERE id = ?', [$user['id']]);
    $customerId = $row['stripe_customer_id'] ?? null;
    if (!$customerId) throw new InvalidArgumentException('no_subscription');

    $session = stripe_create_portal_session(
        $customerId,
        public_url() . '/app'
    );
    if (empty($session['url'])) throw new RuntimeException('stripe_no_url');
    return (string) $session['url'];
}

// Upsert a subscription state from a Stripe subscription object + sync the
// user's tier. Idempotent — safe to call repeatedly with the same payload.
function billing_apply_subscription(int $userId, array $sub): void {
    $now    = now_ms();
    $status = (string) ($sub['status'] ?? 'unknown');
    $subId  = (string) ($sub['id'] ?? '');
    if ($subId === '') return;

    // The "primary" price for this subscription. Stripe lets multiple items
    // exist on one sub; we only ever sell single-item plans so the first
    // item's price is enough.
    $items = $sub['items']['data'] ?? [];
    $priceId = $items[0]['price']['id'] ?? null;
    $plan = billing_plan_for_price($priceId);

    // Stripe API 2025+ moved current_period_start/end from the subscription
    // root onto the individual subscription items. Fall back to the
    // sub-level field for older API versions / past data — read items first.
    $periodEnd = null;
    if (!empty($items[0]['current_period_end'])) {
        $periodEnd = ((int) $items[0]['current_period_end']) * 1000;
    } elseif (!empty($sub['current_period_end'])) {
        $periodEnd = ((int) $sub['current_period_end']) * 1000;
    }
    $cancelAtPeriodEnd = !empty($sub['cancel_at_period_end']) ? 1 : 0;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = db_get('SELECT user_id FROM subscriptions WHERE user_id = ?', [$userId]);
        if ($existing) {
            db_run(
                'UPDATE subscriptions SET stripe_subscription_id = ?, status = ?, plan = ?,
                        current_period_end = ?, cancel_at_period_end = ?, updated_at = ?
                 WHERE user_id = ?',
                [$subId, $status, $plan, $periodEnd, $cancelAtPeriodEnd, $now, $userId]
            );
        } else {
            db_run(
                'INSERT INTO subscriptions (user_id, stripe_subscription_id, status, plan,
                        current_period_end, cancel_at_period_end, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $subId, $status, $plan, $periodEnd, $cancelAtPeriodEnd, $now, $now]
            );
        }
        $newTier = billing_status_grants_pro($status) ? 'pro' : 'free';
        db_run('UPDATE users SET tier = ? WHERE id = ?', [$newTier, $userId]);
        if ($newTier === 'pro') {
            // Pro tier has no auto-expiry. Strip the rolling-expiry flag
            // (and the auto-set expiry itself) from this user's links so
            // post-upgrade clicks don't keep extending free-era links.
            // Pro users who later downgrade keep their now-permanent links;
            // they'll need to delete or set explicit expiry if undesired.
            db_run(
                'UPDATE links SET expires_at = NULL, expires_auto = 0
                 WHERE user_id = ? AND expires_auto = 1',
                [$userId]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Process a webhook event. Caller has already verified the signature and
// JSON-decoded the payload. Unknown event types are silently ignored —
// Stripe sends a lot of events we don't care about.
function billing_handle_webhook(array $event): void {
    $type   = (string) ($event['type'] ?? '');
    $object = $event['data']['object'] ?? [];
    if (!is_array($object)) return;

    if ($type === 'checkout.session.completed') {
        // Tie the Stripe customer to our user via client_reference_id (the
        // user.id we set when creating the Checkout Session). This makes
        // future customer.subscription.* events resolvable to a user.
        $userId = isset($object['client_reference_id'])
            ? (int) $object['client_reference_id']
            : 0;
        if (!$userId) return;
        $customerId = (string) ($object['customer'] ?? '');
        if ($customerId !== '') {
            db_run('UPDATE users SET stripe_customer_id = ? WHERE id = ?', [$customerId, $userId]);
        }
        // Best-effort tier flip via subscription fetch. If the outbound
        // call fails, the customer.subscription.created/updated events
        // arriving alongside this one will apply the state — we don't
        // depend on the fetch for correctness.
        $subId = (string) ($object['subscription'] ?? '');
        if ($subId !== '') {
            try {
                $sub = stripe_get_subscription($subId);
                billing_apply_subscription($userId, $sub);
            } catch (Throwable $e) {
                error_log('[shortly:billing] failed to fetch subscription ' . $subId . ': ' . $e->getMessage());
            }
        }
        return;
    }

    if ($type === 'customer.subscription.created'
     || $type === 'customer.subscription.updated'
     || $type === 'customer.subscription.deleted') {
        $customerId = (string) ($object['customer'] ?? '');
        if ($customerId === '') return;
        $userRow = db_get('SELECT id FROM users WHERE stripe_customer_id = ?', [$customerId]);
        if (!$userRow) {
            // .deleted: user is genuinely gone (admin removed) — accept
            // the event and move on so Stripe doesn't retry forever.
            // .created/.updated: customer probably isn't linked yet —
            // checkout.session.completed may not have run before us, or
            // failed to. Throw so api_stripe_webhook returns 500 and Stripe
            // retries with backoff. By the next attempt the customer_id
            // should be set on our users row.
            if ($type === 'customer.subscription.deleted') return;
            throw new RuntimeException('customer_not_linked_yet:' . $customerId);
        }
        $userId = (int) $userRow['id'];

        if ($type === 'customer.subscription.deleted') {
            // Force status to canceled regardless of what's in the payload —
            // Stripe sometimes sends `status: canceled` already, but be safe.
            $object['status'] = 'canceled';
        }
        billing_apply_subscription($userId, $object);
        return;
    }
    // Other events (invoice.paid, payment_intent.*, etc.) ignored — the
    // subscription.updated event covers status changes we care about.
}
