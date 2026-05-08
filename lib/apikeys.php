<?php
declare(strict_types=1);

// API key management. Keys are 32 hex chars prefixed with `sk_` so they're
// recognisable in source dumps and logs. Only the sha256 hash is persisted
// — the plaintext is shown to the user once at creation time.
//
// Auth flow:
//   1. Client sends `Authorization: Bearer sk_<32 hex>`
//   2. apikey_authenticate() hashes the presented value and looks it up
//   3. On match: bumps last_used_at (sampled), enforces tier-based hourly
//      rate-limit, returns the user dict that auth_current_user would.
//   4. Mismatch / revoked / wrong format → null

const APIKEY_PREFIX     = 'sk_';
const APIKEY_BODY_LEN   = 32;          // hex chars after the prefix
const APIKEY_LABEL_MAX  = 100;
const APIKEY_PER_USER_MAX = 20;

// Hourly request caps per key. Pro pays for the higher cap; free is generous
// enough for personal scripts.
const APIKEY_RATE_FREE = 100;
const APIKEY_RATE_PRO  = 1000;

function apikey_hash(string $plaintext): string {
    return hash('sha256', $plaintext);
}

function apikey_generate(): string {
    return APIKEY_PREFIX . bin2hex(random_bytes(APIKEY_BODY_LEN / 2));
}

function apikey_is_valid_format(string $s): bool {
    if (!str_starts_with($s, APIKEY_PREFIX)) return false;
    $body = substr($s, strlen(APIKEY_PREFIX));
    return (bool) preg_match('/^[a-f0-9]{' . APIKEY_BODY_LEN . '}$/', $body);
}

// Returns the just-created plaintext key alongside the metadata. The
// plaintext appears here ONCE — callers must surface it to the user
// immediately. Subsequent reads only see the prefix + hash.
function apikey_create(int $userId, ?string $label): array {
    $label = $label === null ? null : trim($label);
    if ($label !== null && strlen($label) > APIKEY_LABEL_MAX) {
        throw new InvalidArgumentException('label_too_long');
    }
    $count = (int) (db_get(
        'SELECT COUNT(*) AS n FROM api_keys WHERE user_id = ? AND revoked_at IS NULL',
        [$userId]
    )['n'] ?? 0);
    if ($count >= APIKEY_PER_USER_MAX) {
        throw new InvalidArgumentException('apikey_limit_reached');
    }

    $plain  = apikey_generate();
    $hash   = apikey_hash($plain);
    $prefix = substr($plain, 0, 8);  // "sk_" + 5 chars — enough to identify
    $now    = now_ms();
    $id = db_insert(
        'INSERT INTO api_keys (user_id, key_hash, key_prefix, label, created_at)
         VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $prefix, $label, $now]
    );
    return [
        'id'         => $id,
        'key'        => $plain,        // shown once, never again
        'prefix'     => $prefix,
        'label'      => $label,
        'created_at' => $now,
    ];
}

function apikey_list(int $userId): array {
    $rows = db_all(
        'SELECT id, key_prefix, label, created_at, last_used_at, revoked_at
         FROM api_keys WHERE user_id = ?
         ORDER BY revoked_at IS NULL DESC, created_at DESC',
        [$userId]
    );
    return array_map(fn($r) => [
        'id'           => (int) $r['id'],
        'prefix'       => $r['key_prefix'],
        'label'        => $r['label'],
        'created_at'   => (int) $r['created_at'],
        'last_used_at' => $r['last_used_at'] !== null ? (int) $r['last_used_at'] : null,
        'revoked'      => $r['revoked_at'] !== null,
        'revoked_at'   => $r['revoked_at'] !== null ? (int) $r['revoked_at'] : null,
    ], $rows);
}

// Revoke (don't hard-delete) so the audit trail survives.
function apikey_revoke(int $userId, int $keyId): bool {
    $changed = db_run(
        'UPDATE api_keys SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
        [now_ms(), $keyId, $userId]
    );
    return $changed > 0;
}

// Authenticate via Bearer header. Returns the user dict (compatible with
// auth_current_user's shape — id, email, name, tier) or null. Side-effects:
// bumps last_used_at and enforces the hourly rate limit. The rate-limit
// 429 short-circuits the request.
function apikey_authenticate(string $bearer): ?array {
    if (!apikey_is_valid_format($bearer)) return null;
    $row = db_get(
        'SELECT k.id, k.key_hash, k.user_id, u.email, u.name, u.tier
         FROM api_keys k JOIN users u ON u.id = k.user_id
         WHERE k.key_hash = ? AND k.revoked_at IS NULL',
        [apikey_hash($bearer)]
    );
    if (!$row) return null;

    // Sampled bump: 1-in-20 chance per request. Avoids a DB write on every
    // single API call from a busy script while still surfacing recent activity
    // in the management UI within minutes for normal usage patterns.
    if (random_int(1, 20) === 1) {
        db_run('UPDATE api_keys SET last_used_at = ? WHERE id = ?', [now_ms(), (int) $row['id']]);
    }

    // Tier-based rate limit. Use the key's own id so two keys for the same
    // user have separate buckets — easier to reason about + lets a misbehaving
    // script's key get capped without affecting the user's other tools.
    $tier = $row['tier'] ?? 'free';
    $cap  = $tier === 'pro' ? APIKEY_RATE_PRO : APIKEY_RATE_FREE;
    rate_limit_or_429('apikey:' . $row['id'], $cap, 3600);

    return [
        'id'    => (int) $row['user_id'],
        'email' => $row['email'],
        'name'  => $row['name'] ?? null,
        'tier'  => $tier,
        'token' => null,  // not a session token
        'auth'  => 'apikey',
    ];
}
