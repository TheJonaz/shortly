<?php
/** @var array $bio  Validated bio_pages row dict */
$title    = $bio['title'] ?? ('@' . $bio['slug']);
$bodyClass = 'bio-public bio-theme-' . ($bio['theme'] === 'dark' ? 'dark' : 'light');
require __DIR__ . '/_header.php';
?>
<style>
  body.bio-public {
    min-height: 100vh;
    margin: 0;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 60px 20px;
    font-family: var(--sans, system-ui, sans-serif);
  }
  body.bio-theme-light { background: #f6f5f1; color: #181613; }
  body.bio-theme-dark  { background: #0f0f10; color: #f0eee8; }
  .bio-card {
    width: 100%;
    max-width: 480px;
    text-align: center;
  }
  .bio-card h1 {
    font-size: 28px;
    margin: 0 0 6px;
    font-weight: 500;
    letter-spacing: -.01em;
  }
  .bio-card .bio-handle {
    font-family: var(--mono, monospace);
    font-size: 13px;
    opacity: .55;
    margin-bottom: 32px;
  }
  .bio-link {
    display: block;
    padding: 14px 20px;
    margin: 10px 0;
    border-radius: 12px;
    font-size: 16px;
    text-decoration: none;
    transition: transform .12s ease, box-shadow .12s ease;
    border: 1px solid;
  }
  body.bio-theme-light .bio-link {
    background: #fff;
    color: #181613;
    border-color: rgba(0,0,0,.08);
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
  }
  body.bio-theme-dark .bio-link {
    background: #1a1a1c;
    color: #f0eee8;
    border-color: rgba(255,255,255,.08);
  }
  .bio-link:hover { transform: translateY(-1px); }
  body.bio-theme-light .bio-link:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); }
  body.bio-theme-dark  .bio-link:hover { background: #222226; }
  .bio-foot {
    margin-top: 40px;
    font-size: 11px;
    opacity: .35;
  }
  .bio-foot a { color: inherit; text-decoration: none; }
  .bio-foot a:hover { text-decoration: underline; }
</style>

<main class="bio-card">
  <h1><?= e($bio['title'] ?? ('@' . $bio['slug'])) ?></h1>
  <div class="bio-handle">@<?= e($bio['slug']) ?></div>
  <div class="bio-links">
    <?php foreach ($bio['links'] as $l): ?>
      <a class="bio-link" href="<?= e($l['url']) ?>" rel="noopener" target="_blank"><?= e($l['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <p class="bio-foot">Made with <a href="<?= base_path() ?>/">shortly</a></p>
</main>

<?php require __DIR__ . '/_consent.php'; ?>
</body>
</html>
