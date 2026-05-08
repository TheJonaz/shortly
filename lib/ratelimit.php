<?php
declare(strict_types=1);

// Fixed-window rate limiter backed by the rate_limits table.
// Returns true if the request is allowed; false if the limit is exceeded.
// Race-tolerant: under concurrent inserts the worst case is a slightly
// generous limit, never a deadlock.
function rate_limit_check(string $key, int $max, int $windowSec): bool {
    $now = time();
    $cutoff = $now - $windowSec;

    // Fast path: bump the counter inside the current window.
    $bumped = db_run(
        'UPDATE rate_limits SET count = count + 1 WHERE key_name = ? AND window_start > ?',
        [$key, $cutoff]
    );
    if ($bumped > 0) {
        $row = db_get('SELECT count FROM rate_limits WHERE key_name = ?', [$key]);
        return ((int) ($row['count'] ?? 0)) <= $max;
    }

    // Either no row, or the window expired. Reset.
    try {
        db_run('DELETE FROM rate_limits WHERE key_name = ?', [$key]);
        db_run(
            'INSERT INTO rate_limits (key_name, count, window_start) VALUES (?, 1, ?)',
            [$key, $now]
        );
    } catch (PDOException $e) {
        // Race: another request inserted between our DELETE and INSERT.
        db_run(
            'UPDATE rate_limits SET count = count + 1 WHERE key_name = ?',
            [$key]
        );
    }
    return true;
}

// Convenience: enforce a limit and 429 out of the request if exceeded.
function rate_limit_or_429(string $key, int $max, int $windowSec): void {
    if (!rate_limit_check($key, $max, $windowSec)) {
        header('Retry-After: ' . $windowSec);
        json_error('rate_limited', 429);
    }
}

// Read-only count for the current window. Use when you want to gate before
// doing work but only increment on a specific outcome (e.g. failed login).
function rate_limit_count(string $key, int $windowSec): int {
    $cutoff = time() - $windowSec;
    $row = db_get(
        'SELECT count FROM rate_limits WHERE key_name = ? AND window_start > ?',
        [$key, $cutoff]
    );
    return (int) ($row['count'] ?? 0);
}

// Bump the counter for $key without checking. Same race-tolerant
// upsert pattern as rate_limit_check.
function rate_limit_increment(string $key, int $windowSec): void {
    $now = time();
    $bumped = db_run(
        'UPDATE rate_limits SET count = count + 1 WHERE key_name = ? AND window_start > ?',
        [$key, $now - $windowSec]
    );
    if ($bumped > 0) return;
    try {
        db_run('DELETE FROM rate_limits WHERE key_name = ?', [$key]);
        db_run(
            'INSERT INTO rate_limits (key_name, count, window_start) VALUES (?, 1, ?)',
            [$key, $now]
        );
    } catch (PDOException $e) {
        db_run('UPDATE rate_limits SET count = count + 1 WHERE key_name = ?', [$key]);
    }
}
