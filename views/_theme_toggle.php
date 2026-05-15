<?php
/** Render the theme-toggle pill button + a tiny inline click handler.
 *  Self-contained: works without any JS module loading (the inline
 *  script below carries the CSP nonce). CSS shows the correct
 *  icon+label based on <html data-theme>. */
$extra_class = $extra_class ?? '';
?>
<button class="theme-toggle<?= $extra_class ? ' ' . e($extra_class) : '' ?>" id="theme-toggle" type="button" aria-label="Toggle dark mode" title="Byt tema">
  <span class="t-light">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z"/></svg>
    <span class="label">Mörkt</span>
  </span>
  <span class="t-dark">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
    <span class="label">Ljust</span>
  </span>
</button>
<script nonce="<?= e(csp_nonce()) ?>">
  // Inline so the toggle keeps working even if the page's module bundle
  // fails to load or hasn't been wired with bindThemeToggle().
  (function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn || btn.dataset.bound) return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      try { localStorage.setItem('shortly.theme', next); } catch (e) {}
    });
  })();
</script>
