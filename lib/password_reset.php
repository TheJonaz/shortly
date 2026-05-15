<?php
declare(strict_types=1);

// Forgot-password flow.
//
// Threat model:
//   - The reset link in the email IS the credential. So: short TTL, single
//     use, sha256-hashed in the DB (a leak gives hashes, not live tokens).
//   - Don't reveal whether an email is registered — both "email exists" and
//     "email doesn't" return 200 from the public endpoint.
//   - Rate-limit by email + IP so the endpoint can't be turned into a
//     mail-bomb vector against arbitrary inboxes.

const PASSWORD_RESET_TTL_SECONDS = 60 * 60;  // 1 hour

// 32 random bytes → 64-char hex token. Hex (not base64) keeps the URL clean
// and case-insensitive — users mistype reset URLs from email surprisingly often.
function password_reset_generate_token(): string {
    return bin2hex(random_bytes(32));
}

function password_reset_hash_token(string $token): string {
    $salt = (string) (config()['ip_salt'] ?? 'shortly');  // reuse existing app salt
    return hash('sha256', $token . '|pwreset|' . $salt);
}

// Stage a reset for the given email. No-op (silently) if the email is not
// registered — caller MUST NOT branch on the return value for the response,
// or it leaks user existence. Returns true if a token was actually issued.
function password_reset_request(string $email): bool {
    $user = db_get('SELECT id, name FROM users WHERE email = ?', [$email]);
    if (!$user) return false;

    $token     = password_reset_generate_token();
    $tokenHash = password_reset_hash_token($token);
    $now       = now_ms();
    $expiresAt = $now + PASSWORD_RESET_TTL_SECONDS * 1000;

    // Invalidate any previous outstanding tokens for this user — the user
    // requesting a new one means the older link should stop working.
    db_run('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
    db_insert(
        'INSERT INTO password_resets (user_id, token_hash, created_at, expires_at)
         VALUES (?, ?, ?, ?)',
        [(int) $user['id'], $tokenHash, $now, $expiresAt]
    );

    $resetUrl = public_url() . '/reset?token=' . $token;
    if (!send_password_reset_email($email, (string) ($user['name'] ?? ''), $resetUrl)) {
        // Don't fail the request — the row is staged. The user can ask again.
        error_log("[shortly] password-reset mail() failed for {$email}");
    }
    return true;
}

// Consume a reset token, swapping in the new password hash atomically.
// Throws RuntimeException with a code-string ('invalid_token', 'expired',
// 'already_used') for caller-friendly error mapping. Returns the user row
// on success so the caller can optionally open a session.
function password_reset_consume(string $token, string $newPassword): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('invalid_token');
    }
    $row = db_get('SELECT * FROM password_resets WHERE token_hash = ?',
        [password_reset_hash_token($token)]);
    if (!$row) throw new RuntimeException('invalid_token');
    if ($row['used_at'] !== null)              throw new RuntimeException('already_used');
    if ((int) $row['expires_at'] <= now_ms()) throw new RuntimeException('expired');

    $pwHash = password_hash($newPassword, PASSWORD_BCRYPT);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE users SET password_hash = ? WHERE id = ?',
            [$pwHash, (int) $row['user_id']]);
        db_run('UPDATE password_resets SET used_at = ? WHERE id = ?',
            [now_ms(), (int) $row['id']]);
        // Wipe every active session for this user — anyone holding a stolen
        // session cookie (which is what would have driven them to reset in
        // the first place) is forced back to /login.
        db_run('DELETE FROM sessions WHERE user_id = ?', [(int) $row['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $user = db_get('SELECT id, email, name FROM users WHERE id = ?', [(int) $row['user_id']]);
    return $user ?? [];
}

// Quick check used by the GET /reset page renderer — true if the token
// would be accepted by consume(). Lets us show a clean "expired" view
// instead of letting the user type a new password into a dead form.
function password_reset_token_status(string $token): string {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return 'invalid_token';
    $row = db_get('SELECT used_at, expires_at FROM password_resets WHERE token_hash = ?',
        [password_reset_hash_token($token)]);
    if (!$row) return 'invalid_token';
    if ($row['used_at'] !== null)              return 'already_used';
    if ((int) $row['expires_at'] <= now_ms()) return 'expired';
    return 'valid';
}
