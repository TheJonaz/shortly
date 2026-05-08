<?php
$user = auth_current_user();
$title = t('title_bio_editor');
require __DIR__ . '/_header.php';
?>
  <div class="shell">
    <header class="topbar">
      <a class="wordmark" href="<?= base_path() ?>/">shortly <em>/ url shortener</em></a>
      <nav class="topnav">
        <a href="<?= base_path() ?>/app"><?= t('nav_dashboard') ?></a>
        <a href="<?= base_path() ?>/app/bio" style="font-weight:500"><?= t('nav_bio') ?></a>
        <a href="<?= base_path() ?>/app/keys"><?= t('nav_keys') ?></a>
        <a href="#" id="logout-btn"><?= t('nav_signout') ?></a>
        <?php require __DIR__ . '/_lang_switch.php'; ?>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
      </nav>
    </header>

    <header class="dash-head">
      <div>
        <h1><?= t('bio_editor_title') ?></h1>
        <p class="muted"><?= t('bio_editor_sub') ?></p>
      </div>
      <div id="bio-public-link"></div>
    </header>

    <section style="max-width:640px">
      <div class="auth-error" id="bio-error"></div>
      <div class="auth-info"  id="bio-info"></div>

      <form id="bio-form" autocomplete="off" style="display:grid;gap:16px;">
        <div>
          <label for="bio-slug"><?= t('bio_label_slug') ?></label>
          <input type="text" id="bio-slug" required minlength="2" maxlength="32"
                 pattern="[a-z0-9_-]{2,32}"
                 placeholder="yourname"
                 style="font-family:var(--mono,monospace)">
          <p class="muted" style="margin:4px 0 0;font-size:12px;" id="bio-url-preview"></p>
        </div>
        <div>
          <label for="bio-title"><?= t('bio_label_title') ?></label>
          <input type="text" id="bio-title" maxlength="100" placeholder="Your name or tagline">
        </div>
        <div>
          <label for="bio-theme"><?= t('bio_label_theme') ?></label>
          <select id="bio-theme">
            <option value="light">Light</option>
            <option value="dark">Dark</option>
          </select>
        </div>
        <div>
          <label><?= t('bio_label_links') ?></label>
          <div id="bio-links" style="display:grid;gap:8px;"></div>
          <button type="button" class="btn ghost small" id="bio-add" style="margin-top:8px;"><?= t('bio_add_link') ?></button>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn primary" type="submit"><?= t('bio_save') ?></button>
          <button class="btn ghost"   type="button" id="bio-delete-btn" hidden><?= t('bio_delete') ?></button>
        </div>
      </form>
    </section>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/bio.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
