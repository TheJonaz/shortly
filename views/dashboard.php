<?php
$title = t('title_dashboard');
// Resolve auth BEFORE _header.php — auth_thern_sso() may setcookie() on the
// SSO branch, which silently fails once HTML output has started.
$user = auth_current_user();
require __DIR__ . '/_header.php';
?>
  <div class="shell">
    <header class="topbar">
      <a class="wordmark" href="<?= base_path() ?>/">shortly <em>/ url shortener</em></a>
      <nav class="topnav">
        <a href="<?= base_path() ?>/"><?= t('nav_new_link') ?></a>
        <a href="<?= base_path() ?>/app/bio"><?= t('nav_bio') ?></a>
        <a href="<?= base_path() ?>/app/keys"><?= t('nav_keys') ?></a>
        <?php if (is_admin($user)): ?>
          <a href="<?= base_path() ?>/admin/users" title="Admin panel">Admin</a>
        <?php endif; ?>
        <a href="#" id="logout-btn"><?= t('nav_signout') ?></a>
        <?php require __DIR__ . '/_lang_switch.php'; ?>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
      </nav>
    </header>

    <header class="dash-head">
      <div>
        <h1><?= t('dash_title') ?></h1>
        <p class="muted" id="user-email"><?= e($user['email'] ?? '') ?></p>
      </div>
      <div class="summary" id="summary"></div>
    </header>

    <section id="links-container">
      <div class="empty" id="empty" hidden>
        <p class="serif"><?= t('dash_empty') ?></p>
        <p><?= t('dash_empty_sub', base_path() . '/') ?></p>
      </div>
      <div class="link-table" id="link-table"></div>
    </section>

    <section id="billing" style="margin-top:48px;padding:24px;border:1px solid var(--rule,#ddd);border-radius:12px;"></section>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/app.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
