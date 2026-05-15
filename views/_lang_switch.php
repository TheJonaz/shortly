<?php
$lang = $GLOBALS['LANG'] ?? 'en';
$cur  = $GLOBALS['CURRENCY'] ?? 'sek';
?>
<div class="lang-switcher">
  <a href="?lang=sv" class="lang-btn<?= $lang === 'sv' ? ' active' : '' ?>" title="Svenska" aria-label="Byt till svenska">🇸🇪</a>
  <a href="?lang=en" class="lang-btn<?= $lang === 'en' ? ' active' : '' ?>" title="English" aria-label="Switch to English">🇬🇧</a>
  <span class="lang-sep" aria-hidden="true">·</span>
  <a href="?currency=sek" class="lang-btn cur-btn<?= $cur === 'sek' ? ' active' : '' ?>" title="SEK" aria-label="<?= e(t('billing_curr_label')) ?>: SEK">SEK</a>
  <a href="?currency=eur" class="lang-btn cur-btn<?= $cur === 'eur' ? ' active' : '' ?>" title="EUR" aria-label="<?= e(t('billing_curr_label')) ?>: EUR">EUR</a>
  <a href="?currency=usd" class="lang-btn cur-btn<?= $cur === 'usd' ? ' active' : '' ?>" title="USD" aria-label="<?= e(t('billing_curr_label')) ?>: USD">USD</a>
</div>
