<?php
/** @var string $slug  Pre-filled from ?slug= or empty */
$slug  = $slug ?? '';
$title = t('title_report');
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
      <h1><?= t('report_title') ?></h1>
      <p class="muted" id="report-sub"><?= t('report_sub') ?></p>

      <div class="auth-error" id="report-error"></div>
      <div class="auth-info"  id="report-info"></div>

      <form id="report-form" autocomplete="off" style="display:grid;gap:14px;">
        <div>
          <label for="report-slug"><?= t('report_field_slug') ?></label>
          <input type="text" id="report-slug" required
                 pattern="[A-Za-z0-9_-]{2,32}" maxlength="32"
                 value="<?= e($slug) ?>"
                 style="font-family:var(--mono,monospace)">
        </div>
        <div>
          <label for="report-reason"><?= t('report_field_reason') ?></label>
          <select id="report-reason" required>
            <option value="phishing"><?= t('report_reason_phishing') ?></option>
            <option value="malware"><?= t('report_reason_malware') ?></option>
            <option value="illegal"><?= t('report_reason_illegal') ?></option>
            <option value="spam"><?= t('report_reason_spam') ?></option>
            <option value="other"><?= t('report_reason_other') ?></option>
          </select>
        </div>
        <div>
          <label for="report-detail"><?= t('report_field_detail') ?></label>
          <textarea id="report-detail" maxlength="1000" rows="4"></textarea>
        </div>
        <button class="btn primary" type="submit"><?= t('report_submit') ?></button>
      </form>
    </main>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/report.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
