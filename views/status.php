<?php
/** @var int    $code      HTTP status code */
/** @var string $title     headline shown to the visitor (escaped here) */
/** @var string $sub       PRE-ESCAPED HTML fragment — caller is responsible
 *                          for running e() on any user data inside it. */
$code      = $code ?? 404;
$headline  = $title ?? 'Something went wrong.';
$sub_html  = $sub ?? '';
$title     = $code . ' · Shortly';   // page <title>, used by _header.php
$bodyClass = 'status-page';
require __DIR__ . '/_header.php';
?>
  <main class="status">
    <p class="status-code"><?= e((string) $code) ?></p>
    <h1><?= e($headline) ?></h1>
    <p class="muted"><?= $sub_html ?></p>
    <a class="btn" href="<?= base_path() ?>/">Back to start</a>
  </main>
  <?php $extra_class = 'status-theme-toggle'; require __DIR__ . '/_theme_toggle.php'; ?>
  <script type="module" nonce="<?= e(csp_nonce()) ?>">
    import { bindThemeToggle } from '<?= base_path() ?>/assets/js/common.js';
    bindThemeToggle(document.getElementById('theme-toggle'));
  </script>
  <?php require __DIR__ . '/_consent.php'; ?>
</body>
</html>
