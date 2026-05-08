<?php
$user = auth_current_user();
$title = t('title_keys');
require __DIR__ . '/_header.php';
?>
  <div class="shell">
    <header class="topbar">
      <a class="wordmark" href="<?= base_path() ?>/">shortly <em>/ url shortener</em></a>
      <nav class="topnav">
        <a href="<?= base_path() ?>/app"><?= t('nav_dashboard') ?></a>
        <a href="<?= base_path() ?>/app/bio"><?= t('nav_bio') ?></a>
        <a href="<?= base_path() ?>/app/keys" style="font-weight:500"><?= t('nav_keys') ?></a>
        <a href="#" id="logout-btn"><?= t('nav_signout') ?></a>
        <?php require __DIR__ . '/_lang_switch.php'; ?>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
      </nav>
    </header>

    <header class="dash-head">
      <div>
        <h1><?= t('keys_title') ?></h1>
        <p class="muted"><?= t('keys_sub') ?></p>
      </div>
    </header>

    <section style="max-width:720px">
      <div class="auth-error" id="keys-error"></div>

      <form id="keys-create-form" autocomplete="off"
            style="display:flex;gap:8px;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap">
        <div style="flex:1 1 240px">
          <label for="key-label"><?= t('keys_label_label') ?></label>
          <input type="text" id="key-label" maxlength="100" placeholder="my-script">
        </div>
        <button class="btn primary" type="submit"><?= t('keys_create') ?></button>
      </form>

      <div id="keys-fresh" hidden style="margin-bottom:24px;padding:16px;
            background:rgba(255,200,90,.12);border:1px solid rgba(255,200,90,.4);
            border-radius:10px;font-size:13px;">
        <p style="margin:0 0 8px;font-weight:500;"><?= t('keys_show_once') ?></p>
        <code id="keys-fresh-value" style="display:block;padding:10px 14px;
              background:#1a1a1c;color:#f0eee8;border-radius:8px;
              font-family:var(--mono,monospace);font-size:13px;
              word-break:break-all;"></code>
        <button type="button" class="btn ghost small" id="keys-fresh-copy" style="margin-top:8px;"><?= t('keys_copy') ?></button>
      </div>

      <div id="keys-list"></div>
    </section>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/keys.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
