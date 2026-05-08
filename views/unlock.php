<?php
/** @var string $slug */
$title     = t('title_unlock');
$bodyClass = 'status-page';
require __DIR__ . '/_header.php';
?>
  <main class="status">
    <p class="status-code"><?= t('unlock_code') ?></p>
    <h1><?= t('unlock_title') ?></h1>
    <p class="muted"><?= t('unlock_sub') ?></p>

    <form id="unlock-form" data-slug="<?= e($slug) ?>" style="margin-top: 32px; display: grid; gap: 12px; max-width: 320px; margin-left: auto; margin-right: auto;" autocomplete="off">
      <input type="password" id="password" placeholder="<?= t('placeholder_pw') ?>" autofocus required style="background: var(--field); border: 1px solid var(--rule); border-radius: 8px; padding: 14px 16px; font-family: var(--mono); font-size: 15px; color: var(--ink); outline: none; text-align: center;">
      <button class="btn primary" type="submit" id="unlock-btn"><?= t('btn_unlock') ?></button>
    </form>

    <div class="auth-error" id="error" style="margin-top: 16px; max-width: 320px; margin-left: auto; margin-right: auto;"></div>
  </main>

  <?php $extra_class = 'status-theme-toggle'; require __DIR__ . '/_theme_toggle.php'; ?>
  <script type="module" nonce="<?= e(csp_nonce()) ?>">
    import { bindThemeToggle } from '<?= base_path() ?>/assets/js/common.js';
    bindThemeToggle(document.getElementById('theme-toggle'));
  </script>
  <script type="module" src="<?= base_path() ?>/assets/js/password.js"></script>
  <?php require __DIR__ . '/_consent.php'; ?>
</body>
</html>
