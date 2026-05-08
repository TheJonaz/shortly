<?php
declare(strict_types=1);

// Tag CRUD for the free-tier organisation feature. Per-user namespace —
// uniqueness is enforced via (user_id, name) index in db_migrate().

const TAG_NAME_MAX = 50;
const TAG_PER_USER_MAX = 100;
const TAG_COLOR_RE = '/^#[0-9a-fA-F]{6}$/';

function tags_for_user(int $userId): array {
    $rows = db_all(
        'SELECT id, name, color, created_at FROM tags WHERE user_id = ? ORDER BY name',
        [$userId]
    );
    return array_map(fn($t) => [
        'id'         => (int) $t['id'],
        'name'       => $t['name'],
        'color'      => $t['color'],
        'created_at' => (int) $t['created_at'],
    ], $rows);
}

function tags_create(int $userId, string $name, ?string $color): array {
    $name = trim($name);
    if ($name === '' || strlen($name) > TAG_NAME_MAX) {
        throw new InvalidArgumentException('invalid_tag_name');
    }
    if ($color !== null && $color !== '' && !preg_match(TAG_COLOR_RE, $color)) {
        throw new InvalidArgumentException('invalid_tag_color');
    }
    // Cap tags per user to avoid unbounded growth.
    $count = (int) (db_get('SELECT COUNT(*) AS n FROM tags WHERE user_id = ?', [$userId])['n'] ?? 0);
    if ($count >= TAG_PER_USER_MAX) {
        throw new InvalidArgumentException('tag_limit_reached');
    }
    try {
        $id = db_insert(
            'INSERT INTO tags (user_id, name, color, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $name, $color ?: null, now_ms()]
        );
    } catch (PDOException $e) {
        // Same-name dup hits the (user_id, name) UNIQUE index.
        if ($e->getCode() === '23000') throw new InvalidArgumentException('tag_taken');
        throw $e;
    }
    return [
        'id' => $id, 'name' => $name, 'color' => $color ?: null, 'created_at' => now_ms(),
    ];
}

// Returns true if a tag was deleted, false if it didn't exist or wasn't owned.
// Cascades the link_tags rows so a tag-detach doesn't leave orphans.
function tags_delete(int $userId, int $tagId): bool {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = db_run('DELETE FROM tags WHERE id = ? AND user_id = ?', [$tagId, $userId]);
        if ($del === 0) { $pdo->rollBack(); return false; }
        db_run('DELETE FROM link_tags WHERE tag_id = ?', [$tagId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
