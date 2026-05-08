<?php $lang = $GLOBALS['LANG'] ?? 'en'; ?>
<div class="lang-switcher">
  <a href="?lang=sv" class="lang-btn<?= $lang === 'sv' ? ' active' : '' ?>" title="Svenska" aria-label="Byt till svenska">🇸🇪</a>
  <a href="?lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" title="English" aria-label="Switch to English">🇬🇧</a>
</div>
