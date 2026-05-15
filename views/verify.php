<?php
/** @var string $email  Pre-filled from ?email= or empty */
$email = $email ?? '';
$title = t('title_verify');
require __DIR__ . '/_header.php';
// Forward upgrade-intent params to the "register" aux link too, so a user
// who bounces back to register doesn't lose their plan choice.
$forward = array_intersect_key($_GET, array_flip(['next', 'plan', 'currency']));
$registerHref = base_path() . '/register' . ($forward ? '?' . http_build_query($forward) : '');
?>
  <div class="shell">
    <header class="topbar">
      <a class="wordmark" href="<?= base_path() ?>/">shortly <em>/ url shortener</em></a>
      <nav class="topnav">
        <?php require __DIR__ . '/_lang_switch.php'; ?>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
      </nav>
    </header>

    <main class="auth" data-prefill-email="<?= e($email) ?>">
      <h1><?= t('verify_title') ?></h1>
      <p id="verify-sub">
        <?= $email !== ''
              ? sprintf(t('verify_sub'), e($email))
              : t('verify_sub_blank') ?>
      </p>

      <div class="auth-error" id="auth-error"></div>
      <div class="auth-info"  id="auth-info"></div>

      <form id="verify-form" autocomplete="off">
        <div<?= $email !== '' ? ' hidden' : '' ?>>
          <label for="email"><?= t('label_email') ?></label>
          <input type="email" id="email" name="email" required autocomplete="email" value="<?= e($email) ?>">
        </div>
        <div>
          <label for="code"><?= t('label_code') ?></label>
          <input type="text" id="code" name="code" required
                 inputmode="numeric" pattern="\d{6}" maxlength="6"
                 autocomplete="one-time-code" autofocus
                 style="letter-spacing:.4em;text-align:center;font-family:var(--mono,monospace);font-size:18px;">
        </div>
        <button class="btn primary" type="submit" id="verify-submit"><?= t('btn_verify') ?></button>
      </form>

      <p class="auth-aux" style="margin-top:18px;font-size:13px;opacity:.75;">
        <a href="#" id="resend-link"><?= t('verify_resend') ?></a>
        &nbsp;&middot;&nbsp;
        <a href="<?= e($registerHref) ?>"><?= t('btn_register') ?></a>
      </p>
    </main>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/verify.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
