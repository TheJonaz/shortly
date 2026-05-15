<?php
/** @var string $title */
/** @var string|null $description */
/** @var string|null $bodyClass */
$title       = $title ?? 'Shortly';
$description = $description ?? 'A small, sharp URL shortener. Custom slugs, expiry, password gates, per-link click stats.';
$bodyClass   = $bodyClass ?? '';
$base        = public_url();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($description) ?>">
  <link rel="preconnect" href="https://rsms.me">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=IBM+Plex+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap">
  <link rel="stylesheet" href="<?= base_path() ?>/assets/css/style.css">
  <link rel="icon" type="image/svg+xml" href="<?= base_path() ?>/favicon.svg">
  <script nonce="<?= e(csp_nonce()) ?>">
    (function () {
      try {
        var saved = localStorage.getItem('shortly.theme');
        document.documentElement.dataset.theme = saved || 'light';
      } catch (e) { document.documentElement.dataset.theme = 'light'; }
    })();
    window.LANG = '<?= htmlspecialchars($GLOBALS['LANG'] ?? 'en', ENT_QUOTES) ?>';
    window.CURRENCY = '<?= htmlspecialchars($GLOBALS['CURRENCY'] ?? 'sek', ENT_QUOTES) ?>';
  </script>
</head>
<body<?= $bodyClass ? ' class="' . e($bodyClass) . '"' : '' ?>>
