<?php
$title = t('title_forgot');
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
      <h1><?= t('forgot_title') ?></h1>
      <p><?= t('forgot_sub') ?></p>

      <div class="auth-error" id="auth-error"></div>
      <div class="auth-info"  id="auth-info"></div>

      <form id="forgot-form" autocomplete="on">
        <div>
          <label for="email"><?= t('label_email') ?></label>
          <input type="email" id="email" name="email" required autocomplete="email" autofocus>
        </div>
        <button class="btn primary" type="submit" id="forgot-submit"><?= t('btn_forgot') ?></button>
      </form>

      <p class="auth-aux" style="margin-top:18px;font-size:13px;opacity:.75;">
        <a href="<?= base_path() ?>/login"><?= t('btn_signin') ?></a>
      </p>
    </main>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/forgot.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
