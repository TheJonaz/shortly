<?php
/** @var string $token        From the reset URL (?token=) */
/** @var string $token_status One of: valid | invalid_token | expired | already_used */
$token        = $token        ?? '';
$token_status = $token_status ?? 'invalid_token';
$title = t('title_reset');
require __DIR__ . '/_header.php';
?>
  <div class="shell">
    <header class="topbar">
      <a class="wordmark" href="<?= base_path() ?>/">shortly <em>/ url shortener</em></a>
      <nav class="topnav">
        <?php require __DIR__ . '/_lang_switch.php'; ?>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
      </nav>
    </header>

    <main class="auth">
      <?php if ($token_status === 'valid'): ?>
        <h1><?= t('reset_title') ?></h1>
        <p><?= t('reset_sub') ?></p>

        <div class="auth-error" id="auth-error"></div>
        <div class="auth-info"  id="auth-info"></div>

        <form id="reset-form" autocomplete="off" data-token="<?= e($token) ?>">
          <div>
            <label for="password"><?= t('label_password') ?></label>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
          </div>
          <button class="btn primary" type="submit" id="reset-submit"><?= t('btn_reset') ?></button>
        </form>
      <?php else: ?>
        <h1><?= t('reset_title') ?></h1>
        <p class="auth-error show" style="margin-top:0;">
          <?= $token_status === 'already_used' ? t('reset_used') : t('reset_invalid') ?>
        </p>
        <p class="auth-aux" style="margin-top:18px;font-size:13px;opacity:.75;">
          <a href="<?= base_path() ?>/forgot"><?= t('btn_to_forgot') ?></a>
          &nbsp;&middot;&nbsp;
          <a href="<?= base_path() ?>/login"><?= t('btn_to_login') ?></a>
        </p>
      <?php endif; ?>
    </main>
  </div>

  <?php if ($token_status === 'valid'): ?>
  <script type="module" src="<?= base_path() ?>/assets/js/reset.js"></script>
  <?php endif; ?>

<?php $hideFooterAd = true; require __DIR__ . '/_footer.php'; ?>
