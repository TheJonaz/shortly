<?php
/** GDPR consent banner. Shown on first visit until the user picks accept/decline.
 *  Self-contained: bring the styles + script along so it works on minimal
 *  views (unlock, status) that don't include the full footer. */
?>
<aside id="consent-banner" class="consent-banner" hidden aria-label="<?= e(t('consent_aria')) ?>">
  <p class="consent-text"><?= t('consent_text') ?></p>
  <div class="consent-actions">
    <button type="button" class="consent-btn" data-consent="decline"><?= t('consent_decline') ?></button>
    <button type="button" class="consent-btn primary" data-consent="accept"><?= t('consent_accept') ?></button>
  </div>
</aside>
<style>
  .consent-banner {
    position: fixed; left: 16px; right: 16px; bottom: 16px;
    max-width: 720px; margin: 0 auto;
    background: var(--field, #fff);
    color: var(--ink, #181613);
    border: 1px solid var(--rule, #ddd);
    border-radius: 12px;
    box-shadow: 0 12px 32px rgba(0,0,0,.18);
    padding: 16px 18px;
    display: flex; gap: 16px; align-items: center; flex-wrap: wrap;
    font-size: 14px; line-height: 1.45;
    z-index: 9999;
  }
  .consent-banner[hidden] { display: none; }
  .consent-text { margin: 0; flex: 1 1 280px; }
  .consent-actions { display: flex; gap: 8px; flex-shrink: 0; }
  .consent-btn {
    padding: 8px 14px; border-radius: 8px; border: 1px solid var(--rule, #ddd);
    background: transparent; color: inherit; cursor: pointer;
    font: inherit; font-size: 13px; font-weight: 500;
  }
  .consent-btn:hover { background: rgba(0,0,0,.04); }
  .consent-btn.primary {
    background: var(--accent, #181613);
    color: var(--accent-ink, #fff);
    border-color: var(--accent, #181613);
  }
  .consent-btn.primary:hover { filter: brightness(1.08); }
  #consent-reset { color: inherit; opacity: .7; text-decoration: none; }
  #consent-reset:hover { opacity: 1; text-decoration: underline; }
</style>
<?php $beacon = (string) (config()['consent_beacon_url'] ?? ''); ?>
<?php if ($beacon !== ''): ?>
<script nonce="<?= e(csp_nonce()) ?>">window.SHORTLY_BEACON_URL = <?= json_encode($beacon, JSON_UNESCAPED_SLASHES) ?>;</script>
<?php endif; ?>
<script type="module" src="<?= base_path() ?>/assets/js/consent.js"></script>
