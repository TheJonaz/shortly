<?php
declare(strict_types=1);

// Link-in-bio page management. Free tier gets one page per user; Pro tier
// can later have multiple by relaxing the per-user-cap check below. The
// public /u/{slug} renderer lives in views/u.php.

const BIO_SLUG_RE        = '/^[a-z0-9_-]{2,32}$/';
const BIO_TITLE_MAX      = 100;
const BIO_LINK_LABEL_MAX = 80;
const BIO_LINKS_MAX      = 50;
const BIO_THEMES         = ['light', 'dark'];
// Slugs reserved on the /u/{slug} namespace to keep room for future top-level
// areas. Distinct from RESERVED_SLUGS (link redirect) — those don't apply
// here because /u/admin and /admin are different paths.
const RESERVED_BIO_SLUGS = ['admin', 'api', 'app', 'help', 'about', 'login',
                            'register', 'verify', 'p', 'u', 'me', 'support',
                            'terms', 'privacy', 'docs', 'static'];

// Returns the user's bio page (or null). Free-tier convention: at most one.
function bio_for_user(int $userId): ?array {
    $row = db_get('SELECT * FROM bio_pages WHERE user_id = ? LIMIT 1', [$userId]);
    return $row ? bio_row_to_dict($row) : null;
}

function bio_for_slug(string $slug): ?array {
    $slug = strtolower($slug);
    $row = db_get('SELECT * FROM bio_pages WHERE slug = ?', [$slug]);
    return $row ? bio_row_to_dict($row) : null;
}

function bio_row_to_dict(array $row): array {
    $links = [];
    if (!empty($row['links_json'])) {
        $decoded = json_decode((string) $row['links_json'], true);
        if (is_array($decoded)) $links = $decoded;
    }
    return [
        'id'         => (int) $row['id'],
        'user_id'    => (int) $row['user_id'],
        'slug'       => $row['slug'],
        'title'      => $row['title'],
        'theme'      => $row['theme'] ?? 'light',
        'links'      => $links,
        'created_at' => (int) $row['created_at'],
        'updated_at' => (int) $row['updated_at'],
    ];
}

// Validate + normalise a partial bio update payload. Throws
// InvalidArgumentException with a stable error code for the API to map.
function bio_validate_payload(array $body): array {
    $slug = strtolower(trim((string) ($body['slug'] ?? '')));
    if (!preg_match(BIO_SLUG_RE, $slug)) {
        throw new InvalidArgumentException('invalid_bio_slug');
    }
    if (in_array($slug, RESERVED_BIO_SLUGS, true)) {
        throw new InvalidArgumentException('bio_slug_reserved');
    }

    $title = trim((string) ($body['title'] ?? ''));
    if (strlen($title) > BIO_TITLE_MAX) {
        throw new InvalidArgumentException('bio_title_too_long');
    }

    $theme = (string) ($body['theme'] ?? 'light');
    if (!in_array($theme, BIO_THEMES, true)) {
        throw new InvalidArgumentException('invalid_bio_theme');
    }

    $rawLinks = is_array($body['links'] ?? null) ? $body['links'] : [];
    if (count($rawLinks) > BIO_LINKS_MAX) {
        throw new InvalidArgumentException('bio_links_too_many');
    }
    $links = [];
    foreach ($rawLinks as $l) {
        if (!is_array($l)) continue;
        $label = trim((string) ($l['label'] ?? ''));
        $url   = trim((string) ($l['url'] ?? ''));
        if ($label === '' || $url === '') continue;
        if (strlen($label) > BIO_LINK_LABEL_MAX) {
            throw new InvalidArgumentException('bio_link_label_too_long');
        }
        // Reuse validate_url for the URL — same scheme/length/control-char rules
        // as link targets get. Throws InvalidArgumentException on bad input.
        $links[] = ['label' => $label, 'url' => validate_url($url)];
    }

    return [
        'slug'  => $slug,
        'title' => $title === '' ? null : $title,
        'theme' => $theme,
        'links' => $links,
    ];
}

// Create or replace the user's bio page. Idempotent on the same user_id.
function bio_save(int $userId, array $body): array {
    $clean = bio_validate_payload($body);
    $now = now_ms();

    // Encode once and validate — if a label sneaks in invalid UTF-8 bytes
    // (rare, but possible from API callers) json_encode returns false and
    // we'd persist `false` cast to empty string, leaving the bio without
    // links. Surface the error instead.
    $linksJson = json_encode(
        $clean['links'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($linksJson === false) {
        throw new InvalidArgumentException('invalid_links_encoding');
    }

    $existing = bio_for_user($userId);
    // Reject slug collisions against OTHER users — own row can change slug freely.
    $clash = db_get('SELECT user_id FROM bio_pages WHERE slug = ?', [$clean['slug']]);
    if ($clash && (int) $clash['user_id'] !== $userId) {
        throw new InvalidArgumentException('bio_slug_taken');
    }

    if ($existing) {
        db_run(
            'UPDATE bio_pages SET slug = ?, title = ?, theme = ?, links_json = ?, updated_at = ?
             WHERE user_id = ?',
            [$clean['slug'], $clean['title'], $clean['theme'], $linksJson, $now, $userId]
        );
    } else {
        try {
            db_insert(
                'INSERT INTO bio_pages (user_id, slug, title, theme, links_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $clean['slug'], $clean['title'], $clean['theme'], $linksJson, $now, $now]
            );
        } catch (PDOException $e) {
            // Race against another user creating the same slug between
            // bio_for_user and INSERT.
            if ($e->getCode() === '23000') throw new InvalidArgumentException('bio_slug_taken');
            throw $e;
        }
    }

    return bio_for_user($userId);
}

function bio_delete(int $userId): bool {
    $del = db_run('DELETE FROM bio_pages WHERE user_id = ?', [$userId]);
    return $del > 0;
}
