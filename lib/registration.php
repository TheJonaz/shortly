<?php
declare(strict_types=1);

const PENDING_TTL_SECONDS = 15 * 60;
const VERIFY_MAX_ATTEMPTS = 5;

// Six-digit numeric code, zero-padded. Easy to type by phone; combined with
// the 5-attempt cap and 15-min expiry the brute-force surface is trivial.
function generate_verification_code(): string {
    return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
}

// Hash codes the same way every time. Using sha256+salt rather than bcrypt
// because (a) codes are short-lived (15 min), (b) we have rate-limiting,
// (c) bcrypt-cost-10 × every verify call is unnecessarily expensive.
// hash_equals comparison preserves constant-time properties.
function hash_verification_code(string $code): string {
    $salt = (string) (config()['ip_salt'] ?? 'shortly');  // reuse existing salt
    return hash('sha256', $code . '|verify|' . $salt);
}

// Stage a registration: store it as 'pending', send the code by email.
// If a pending row already exists for this email it's replaced — the
// previous code becomes invalid. That handles the "I closed the tab and
// want to redo" flow naturally.
function registration_start(string $name, string $email, string $password): void {
    $code      = generate_verification_code();
    $now       = now_ms();
    $expiresAt = $now + PENDING_TTL_SECONDS * 1000;
    $pwHash    = password_hash($password, PASSWORD_BCRYPT);
    $codeHash  = hash_verification_code($code);

    db_run('DELETE FROM pending_registrations WHERE email = ?', [$email]);
    db_insert(
        'INSERT INTO pending_registrations
            (email, name, password_hash, code_hash, created_at, expires_at, attempts)
         VALUES (?, ?, ?, ?, ?, ?, 0)',
        [$email, $name, $pwHash, $codeHash, $now, $expiresAt]
    );

    if (!send_verification_email($email, $name, $code)) {
        // Don't fail the whole request — the row is staged and the user can
        // hit /api/auth/resend. Log so ops sees a pattern of MTA failures.
        error_log("[shortly] mail() failed for {$email}");
    }
}

// Verify a code. Returns ['id' => …, 'email' => …, 'name' => …] on success.
// Throws RuntimeException with a code-string ('expired' / 'too_many_attempts')
// for terminal states; returns null for plain wrong-code (so the caller can
// 401 rather than 410).
function registration_verify(string $email, string $code): ?array {
    $row = db_get('SELECT * FROM pending_registrations WHERE email = ?', [$email]);
    if (!$row) return null;

    if ((int) $row['expires_at'] <= now_ms()) {
        db_run('DELETE FROM pending_registrations WHERE email = ?', [$email]);
        throw new RuntimeException('expired');
    }
    if ((int) $row['attempts'] >= VERIFY_MAX_ATTEMPTS) {
        throw new RuntimeException('too_many_attempts');
    }

    if (!hash_equals((string) $row['code_hash'], hash_verification_code($code))) {
        db_run(
            'UPDATE pending_registrations SET attempts = attempts + 1 WHERE email = ?',
            [$email]
        );
        return null;
    }

    // Promote the pending row to a real users row in one transaction so we
    // never leave a dangling pending after a successful verify.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = db_get('SELECT id, name FROM users WHERE email = ?', [$email]);
        if ($existing) {
            // Edge case: someone created the user via SSO between register
            // and verify. Don't clobber their account — just attach the name
            // if they don't have one yet, then log them in.
            $userId = (int) $existing['id'];
            if (empty($existing['name']) && !empty($row['name'])) {
                db_run('UPDATE users SET name = ? WHERE id = ?', [$row['name'], $userId]);
            }
        } else {
            try {
                $userId = db_insert(
                    'INSERT INTO users (email, password_hash, name, created_at) VALUES (?, ?, ?, ?)',
                    [$email, $row['password_hash'], $row['name'], now_ms()]
                );
            } catch (PDOException $e) {
                // Race: a parallel verify (user double-clicked Verify, or
                // an SSO insert raced with us) created the row between our
                // SELECT and INSERT. Re-fetch the winner and continue.
                if ($e->getCode() !== '23000') throw $e;
                $loser = db_get('SELECT id, name FROM users WHERE email = ?', [$email]);
                if (!$loser) {
                    // UNIQUE-violation but row still missing — should be
                    // impossible. Let the outer rollback handle it.
                    throw new RuntimeException('user_creation_race');
                }
                $userId = (int) $loser['id'];
                if (empty($loser['name']) && !empty($row['name'])) {
                    db_run('UPDATE users SET name = ? WHERE id = ?', [$row['name'], $userId]);
                }
            }
        }
        db_run('DELETE FROM pending_registrations WHERE email = ?', [$email]);
        $pdo->commit();
        return ['id' => $userId, 'email' => $email, 'name' => $row['name']];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Resend the code on the existing pending row. Returns true if a new code
// was sent, false if no pending row (or it expired). Resets the attempt
// counter so a fresh attempt isn't blocked by previous wrong guesses.
function registration_resend(string $email): bool {
    $row = db_get('SELECT name, expires_at FROM pending_registrations WHERE email = ?', [$email]);
    if (!$row) return false;
    if ((int) $row['expires_at'] <= now_ms()) {
        db_run('DELETE FROM pending_registrations WHERE email = ?', [$email]);
        return false;
    }
    $code = generate_verification_code();
    db_run(
        'UPDATE pending_registrations SET code_hash = ?, attempts = 0 WHERE email = ?',
        [hash_verification_code($code), $email]
    );
    send_verification_email($email, (string) $row['name'], $code);
    return true;
}
