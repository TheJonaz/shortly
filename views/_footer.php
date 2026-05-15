<?php
/**
 * Site footer.
 *
 * Driven entirely by the `footer` block in config.php — see
 * config.example.php for the full schema. If `footer` is missing or empty,
 * a minimal footer with just the report link and consent reset is shown.
 */
$footer = (array) (config()['footer'] ?? []);
$brandName    = (string) ($footer['brand_name']    ?? '');
$brandTagline = (string) ($footer['brand_tagline'] ?? '');
$columns      = (array)  ($footer['columns']       ?? []);
$contactLines = (array)  ($footer['contact_lines'] ?? []);
$copyright    = (string) ($footer['copyright']     ?? ('© ' . date('Y')));
$version      = (string) ($footer['version']       ?? '');
$logoSvg      = (string) ($footer['logo_svg']      ?? '');
$bisBadge     = (string) ($footer['bis_badge']     ?? '');  // path to "Based In Sweden" image; empty = hide
$bisHref      = (string) ($footer['bis_href']      ?? 'https://www.basedinsweden.se');
?>
  <footer class="thern-footer">
    <div class="foot-inner">
      <?php if ($brandName !== '' || $brandTagline !== ''): ?>
      <div class="foot-brand">
        <div class="foot-logo">
          <?php if ($logoSvg !== ''): ?>
          <div class="foot-logo-icon"><?= $logoSvg /* trusted: comes from config.php */ ?></div>
          <?php endif; ?>
          <div class="foot-logo-text">
            <?php if ($brandName !== ''): ?><strong><?= e($brandName) ?></strong><?php endif; ?>
            <?php if ($brandTagline !== ''): ?><span><?= e($brandTagline) ?></span><?php endif; ?>
          </div>
        </div>
        <p class="foot-tagline"><?= t('foot_tagline') ?></p>
        <?php if ($bisBadge !== ''): ?>
        <a class="foot-bis" href="<?= e($bisHref) ?>" target="_blank" rel="noopener" title="Based In Sweden">
          <img src="<?= e($bisBadge) ?>" alt="Based In Sweden" width="100" height="100" loading="lazy" decoding="async">
          <span><?= t('foot_bis_member') ?></span>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php foreach ($columns as $col): ?>
      <div class="foot-col">
        <?php if (!empty($col['heading'])): ?><h5><?= e((string) $col['heading']) ?></h5><?php endif; ?>
        <?php foreach ((array) ($col['links'] ?? []) as $link):
            $href = (string) ($link['href'] ?? '#');
            $label = (string) ($link['label'] ?? '');
            if ($label === '') continue;
        ?>
        <a href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <?php if (!empty($contactLines)): ?>
      <div class="foot-col">
        <h5><?= t('foot_con_head') ?></h5>
        <ul>
          <?php foreach ($contactLines as $line): ?>
          <li><?= $line /* trusted: lines come from config.php and may include mailto: anchors */ ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <div class="foot-bottom">
      <div class="foot-bottom-left">
        <span class="foot-status">
          <span class="foot-status-dot"></span>
          <?= t('foot_systems') ?>
        </span>
        <span class="foot-copy">
          <?= e($copyright) ?>
          <?php if ($version !== ''): ?>
            &nbsp;&middot;&nbsp; <span class="foot-version"><?= e($version) ?></span>
          <?php endif; ?>
          &nbsp;&middot;&nbsp;
          <a href="<?= base_path() ?>/report"><?= t('foot_report_link') ?></a>
          &nbsp;&middot;&nbsp;
          <a href="#" id="consent-reset"><?= t('consent_reset') ?></a>
        </span>
      </div>
      <?php if (!empty($footer['legal_line'])): ?>
      <div class="foot-bottom-right"><?= e((string) $footer['legal_line']) ?></div>
      <?php endif; ?>
    </div>
  </footer>

  <?php require __DIR__ . '/_consent.php'; ?>
</body>
</html>
