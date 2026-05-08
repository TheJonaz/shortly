<?php
declare(strict_types=1);

// Membership tier helpers.
//
// Three tiers, ranked anon < free < pro:
//   - 'anon': not signed in (no users row exists)
//   - 'free': signed-in user with tier='free' in the users table (the default)
//   - 'pro':  signed-in user with tier='pro'  (set via Stripe webhook later)
//
// Anonymous users have no users row, so tier comes from "is $user null?"
// rather than a column. tier_of() collapses both into one source of truth.

const TIER_RANK = [
    'anon' => 0,
    'free' => 1,
    'pro'  => 2,
];

// Resolve the effective tier for a user dict (the kind auth_current_user
// returns) or null. Defaults to 'free' if the row exists but the column
// is missing — that handles the brief window where an old session row
// references a user from before the migration ran.
function tier_of(?array $user): string {
    if (!$user) return 'anon';
    $t = $user['tier'] ?? 'free';
    return isset(TIER_RANK[$t]) ? $t : 'free';
}

// Gate a request behind a minimum tier. On insufficient tier:
//   - anon caller (not signed in) → 401 unauthorized
//   - signed-in but rank too low  → 402 upgrade_required
// Pass means we just return; the caller continues.
function require_tier(string $minTier, ?array $user): void {
    $userTier = tier_of($user);
    $need = TIER_RANK[$minTier] ?? PHP_INT_MAX;
    $have = TIER_RANK[$userTier] ?? -1;
    if ($have >= $need) return;
    if ($userTier === 'anon') {
        json_error('unauthorized', 401);
    }
    json_error('upgrade_required', 402);
}
