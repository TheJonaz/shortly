<?php
/** @var string $mode  'login' | 'register' */
$mode       = $mode ?? 'login';
$isRegister = $mode === 'register';
$title      = t($isRegister ? 'title_register' : 'title_signin');
require __DIR__ . '/_header.php';
$switchUrl  = base_path() . ($isRegister ? '/login' : '/register');
// Forward `next` + `plan` (set by landing pricing CTAs) so the
// upgrade chain survives a register↔login bounce.
$forward = array_intersect_key($_GET, array_flip(['next', 'plan', 'currency']));
if ($forward) $switchUrl .= '?' . http_build_query($forward);
$verifyHref = base_path() . '/verify' . ($forward ? '?' . http_build_query($forward) : '');
?>
  <div class="shell">
    <header class="topbar">
      <a class="wordmark" href="<?= base_path() ?>/">shortly <em>/ url shortener</em></a>
      <nav class="topnav">
        <?php require __DIR__ . '/_lang_switch.php'; ?>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
      </nav>
    </header>

    <main class="auth" data-initial-mode="<?= e($mode) ?>">
      <h1 id="auth-title"><?= $isRegister ? t('register_title') : t('signin_title') ?></h1>
      <p id="auth-subtitle">
        <?= sprintf(t($isRegister ? 'register_sub' : 'signin_sub'), e($switchUrl)) ?>
      </p>

      <div class="auth-error" id="auth-error"></div>

      <form id="auth-form" autocomplete="on">
        <?php if ($isRegister): ?>
          <div>
            <label for="name"><?= t('label_name') ?></label>
            <input type="text" id="name" name="name" required maxlength="100" autocomplete="name" autofocus>
          </div>
        <?php endif; ?>
        <div>
          <label for="email"><?= t('label_email') ?></label>
          <input type="email" id="email" name="email" required autocomplete="email"<?= $isRegister ? '' : ' autofocus' ?>>
        </div>
        <?php if ($isRegister): ?>
          <div>
            <label for="email_confirm"><?= t('label_email_confirm') ?></label>
            <input type="email" id="email_confirm" name="email_confirm" required autocomplete="email">
          </div>
        <?php endif; ?>
        <div>
          <label for="password"><?= t('label_password') ?></label>
          <input type="password" id="password" name="password" required minlength="8" autocomplete="<?= $isRegister ? 'new-password' : 'current-password' ?>">
        </div>
        <?php if ($isRegister && turnstile_is_configured()): ?>
          <div class="cf-turnstile" data-sitekey="<?= e(turnstile_site_key()) ?>" data-callback="onTurnstileToken" style="margin-top:8px;"></div>
        <?php endif; ?>
        <button class="btn primary" type="submit" id="auth-submit"><?= $isRegister ? t('btn_register') : t('btn_signin') ?></button>
      </form>

      <?php if ($isRegister && turnstile_is_configured()): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="<?= e(csp_nonce()) ?>"></script>
        <script nonce="<?= e(csp_nonce()) ?>">
          window.onTurnstileToken = function (token) { window.__turnstileToken = token; };
        </script>
      <?php endif; ?>

      <p class="auth-aux" style="margin-top:18px;font-size:13px;opacity:.75;">
        <?php if (!$isRegister): ?>
        <a href="<?= base_path() ?>/forgot"><?= t('forgot_link') ?></a>
        &nbsp;&middot;&nbsp;
        <?php endif; ?>
        <a href="<?= e($verifyHref) ?>"><?= t('aux_verify_link') ?></a>
      </p>
    </main>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/login.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
