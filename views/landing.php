<?php
$title = t('title_landing');
// Resolve auth BEFORE _header.php — auth_thern_sso() may setcookie() on the
// SSO branch, which silently fails once HTML output has started.
$user = auth_current_user();
require __DIR__ . '/_header.php';
?>
  <div class="shell">
    <header class="topbar">
      <a class="wordmark" href="<?= base_path() ?>/">shortly <em>/ url shortener</em></a>
      <nav class="topnav" id="topnav">
        <?php if ($user): ?>
          <a href="<?= base_path() ?>/app"><?= t('nav_dashboard') ?></a>
        <?php else: ?>
          <a href="<?= base_path() ?>/login"><?= t('nav_signin') ?></a>
        <?php endif; ?>
        <?php require __DIR__ . '/_lang_switch.php'; ?>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
      </nav>
    </header>

    <section class="hero">
      <span class="eyebrow"><?= t('eyebrow') ?></span>
      <h1><?= t('hero_title') ?></h1>
      <p class="lede"><?= t('hero_lede') ?></p>
    </section>

    <form class="shorten" id="shorten-form" autocomplete="off">
      <div class="shorten-row">
        <input
          class="shorten-input"
          type="url"
          id="target"
          name="target"
          placeholder="https://example.com/the-long-one"
          required
          autofocus
        >
        <button class="btn primary" type="submit" id="submit-btn">
          <span><?= t('btn_shorten') ?></span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </div>

      <details class="advanced">
        <summary class="advanced-toggle">
          <svg class="chev" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
          <?= t('advanced_toggle') ?>
        </summary>
        <div class="advanced-grid">
          <div class="field-group">
            <label for="slug"><?= t('label_slug') ?></label>
            <input type="text" id="slug" name="slug" placeholder="optional" pattern="[A-Za-z0-9_-]{2,32}" maxlength="32">
          </div>
          <div class="field-group">
            <label for="expires_at"><?= t('label_expires') ?></label>
            <input type="datetime-local" id="expires_at" name="expires_at">
          </div>
          <div class="field-group">
            <label for="password"><?= t('label_password') ?></label>
            <input type="password" id="password" name="password" placeholder="optional" autocomplete="new-password">
          </div>
        </div>
      </details>
      <?php if (!$user): ?>
        <?php if (turnstile_is_configured()): ?>
          <div class="cf-turnstile" data-sitekey="<?= e(turnstile_site_key()) ?>" data-callback="onTurnstileToken" style="margin-top:12px;"></div>
        <?php endif; ?>
        <p class="anon-note" style="margin-top:12px;font-size:12px;opacity:.65;">
          <?= t('anon_link_warning') ?>
        </p>
      <?php endif; ?>
    </form>

    <?php if (!$user && turnstile_is_configured()): ?>
      <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="<?= e(csp_nonce()) ?>"></script>
      <script nonce="<?= e(csp_nonce()) ?>">
        // Turnstile invokes this callback with the verification token. We
        // stash it on window for the form-submit handler in index.js to
        // pick up — keeps the integration loosely-coupled with the JS bundle.
        window.onTurnstileToken = function (token) { window.__turnstileToken = token; };
      </script>
    <?php endif; ?>

    <div class="result" id="result" hidden>
      <div>
        <div class="short" id="result-short"></div>
        <span class="target" id="result-target"></span>
      </div>
      <div class="result-actions">
        <button class="btn ghost small" type="button" id="copy-btn" data-i18n="btn_copy"><?= t('btn_copy') ?></button>
        <button class="btn ghost small" type="button" id="qr-btn"><?= t('btn_qr') ?></button>
        <button class="btn ghost small" type="button" id="new-btn" data-i18n="btn_new"><?= t('btn_new') ?></button>
      </div>
    </div>
    <div class="qr-wrap" id="qr-wrap"><canvas id="qr"></canvas></div>

    <section class="pricing">
      <header class="pricing-header">
        <h2><?= t('pricing_h') ?></h2>
        <p><?= t('pricing_sub') ?></p>
      </header>
      <?php
        // /upgrade is the unified entry point for both anon + signed-in
        // visitors. Server-side it bounces anon users through /register
        // (carrying the plan in the query string) or starts Stripe Checkout
        // directly for signed-in free users — see handle_upgrade_redirect().
        $proLabel       = t('pricing_cta_pro');
        $proMonthlyHref = base_path() . '/upgrade?plan=monthly';
        $proYearlyHref  = base_path() . '/upgrade?plan=yearly';
        $freeHref       = $user ? base_path() . '/app' : base_path() . '/register';
        $freeLabel      = $user ? t('pricing_cta_app') : t('pricing_cta_free');
      ?>
      <div class="pricing-grid">
        <article class="tier">
          <span class="tier-name"><?= t('tier_free') ?></span>
          <div class="tier-price">
            <span class="amount"><?= t('pricing_free') ?></span>
          </div>
          <p class="tier-tagline"><?= t('tier_free_tag') ?></p>
          <ul class="tier-features">
            <li><?= t('tier_free_f1') ?></li>
            <li><?= t('tier_free_f2') ?></li>
            <li><?= t('tier_free_f3') ?></li>
            <li><?= t('tier_free_f4') ?></li>
          </ul>
          <a class="btn ghost tier-cta" href="<?= e($freeHref) ?>"><?= e($freeLabel) ?></a>
        </article>

        <article class="tier">
          <span class="tier-name"><?= t('tier_pro_monthly') ?></span>
          <div class="tier-price">
            <span class="amount"><?= e(price_display('monthly')) ?></span>
            <span class="period"><?= t('pricing_per_month') ?></span>
          </div>
          <p class="tier-tagline"><?= t('tier_pro_tag') ?></p>
          <ul class="tier-features">
            <li><?= t('tier_pro_f1') ?></li>
            <li><?= t('tier_pro_f2') ?></li>
            <li><?= t('tier_pro_f3') ?></li>
            <li><?= t('tier_pro_f4') ?></li>
            <li><?= t('tier_pro_f5') ?></li>
            <li><?= t('tier_pro_f6') ?></li>
          </ul>
          <a class="btn ghost tier-cta" href="<?= e($proMonthlyHref) ?>"><?= e($proLabel) ?></a>
        </article>

        <article class="tier featured">
          <span class="tier-badge"><?= t('pricing_most_value') ?></span>
          <span class="tier-name"><?= t('tier_pro_yearly') ?></span>
          <div class="tier-price">
            <span class="amount"><?= e(price_display('yearly')) ?></span>
            <span class="period"><?= t('pricing_per_year') ?></span>
          </div>
          <p class="tier-tagline"><?= t('billing_yearly_save') ?> · <?= t('tier_pro_tag') ?></p>
          <ul class="tier-features">
            <li><?= t('tier_pro_f1') ?></li>
            <li><?= t('tier_pro_f2') ?></li>
            <li><?= t('tier_pro_f3') ?></li>
            <li><?= t('tier_pro_f4') ?></li>
            <li><?= t('tier_pro_f5') ?></li>
            <li><?= t('tier_pro_f6') ?></li>
          </ul>
          <a class="btn primary tier-cta" href="<?= e($proYearlyHref) ?>"><?= e($proLabel) ?></a>
        </article>
      </div>
    </section>

    <section class="features">
      <article class="feature">
        <span class="num">01</span>
        <h3><?= t('feat1_title') ?></h3>
        <p><?= t('feat1_body') ?></p>
      </article>
      <article class="feature">
        <span class="num">02</span>
        <h3><?= t('feat2_title') ?></h3>
        <p><?= t('feat2_body') ?></p>
      </article>
      <article class="feature">
        <span class="num">03</span>
        <h3><?= t('feat3_title') ?></h3>
        <p><?= t('feat3_body') ?></p>
      </article>
    </section>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/index.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
