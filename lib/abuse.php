<?php
declare(strict_types=1);

// Community abuse reporting. A visitor who lands on a phish/malware short
// link can fill the /report form. After ABUSE_AUTO_SUSPEND_THRESHOLD
// distinct reporters (by ip_hash) report the same link, the link is
// auto-suspended — its redirect/unlock returns 451.
//
// Manual review can lift the suspension via direct DB edit or the future
// admin UI; auto-suspend never unsuspends on its own.

const ABUSE_REASONS = ['phishing', 'malware', 'illegal', 'spam', 'other'];
const ABUSE_DETAIL_MAX = 1000;
const ABUSE_AUTO_SUSPEND_THRESHOLD = 3;

function abuse_report(string $slug, string $reason, ?string $detail, ?string $reporterIp): array {
    if (!in_array($reason, ABUSE_REASONS, true)) {
        throw new InvalidArgumentException('invalid_reason');
    }
    if ($detail !== null && strlen($detail) > ABUSE_DETAIL_MAX) {
        throw new InvalidArgumentException('detail_too_long');
    }
    $slug = strtolower($slug);
    $link = db_get('SELECT id, suspended_at FROM links WHERE slug = ?', [$slug]);
    if (!$link) throw new InvalidArgumentException('link_not_found');

    $linkId = (int) $link['id'];
    $reporterHash = $reporterIp !== null ? ip_hash($reporterIp) : null;

    $now = now_ms();
    db_insert(
        'INSERT INTO abuse_reports (link_id, reason, detail, reporter_ip_hash, created_at)
         VALUES (?, ?, ?, ?, ?)',
        [$linkId, $reason, $detail, $reporterHash, $now]
    );

    // Auto-suspend if enough distinct reporters have flagged this slug.
    // Already-suspended links don't re-suspend (idempotent).
    $suspended = false;
    if (empty($link['suspended_at'])) {
        $count = (int) (db_get(
            'SELECT COUNT(DISTINCT reporter_ip_hash) AS n
             FROM abuse_reports
             WHERE link_id = ? AND reporter_ip_hash IS NOT NULL',
            [$linkId]
        )['n'] ?? 0);
        if ($count >= ABUSE_AUTO_SUSPEND_THRESHOLD) {
            db_run(
                'UPDATE links SET suspended_at = ?, suspended_reason = ? WHERE id = ?',
                [$now, 'auto:' . $reason, $linkId]
            );
            $suspended = true;
        }
    }

    return [
        'reported'  => true,
        'suspended' => $suspended || !empty($link['suspended_at']),
    ];
}
