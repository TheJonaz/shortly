<?php
$title = 'Admin · Plans — Shortly';
require __DIR__ . '/_header.php';
$productId = (string) (config()['stripe_product_id'] ?? '');
?>
  <div class="shell">
    <header class="topbar">
      <a class="wordmark" href="<?= base_path() ?>/">shortly <em>/ admin</em></a>
      <nav class="topnav">
        <a href="<?= base_path() ?>/app">Dashboard</a>
        <?php require __DIR__ . '/_theme_toggle.php'; ?>
      </nav>
    </header>

    <main class="admin">
      <nav class="admin-tabs">
        <a href="<?= base_path() ?>/admin/users">Users</a>
        <a href="<?= base_path() ?>/admin/plans" class="active">Plans</a>
      </nav>

      <header class="admin-head">
        <h1>Payment plans</h1>
        <label class="admin-toggle">
          <input type="checkbox" id="show-archived"> Show archived
        </label>
      </header>

      <?php if ($productId === ''): ?>
        <div class="admin-warning">
          <strong>stripe_product_id is not set in config.</strong> The list
          below will show every Price on the account; <em>Create</em> is
          disabled until a Product is configured.
        </div>
      <?php endif; ?>

      <div id="admin-toast" class="admin-toast" hidden></div>

      <section class="admin-block">
        <h2>Existing prices</h2>
        <div id="plans-wrap">Loading…</div>
      </section>

      <section class="admin-block">
        <h2>Create new price</h2>
        <form id="create-form" class="admin-form"<?= $productId === '' ? ' inert' : '' ?>>
          <div class="row">
            <label>Interval
              <select name="interval" required>
                <option value="month">Monthly</option>
                <option value="year">Yearly</option>
              </select>
            </label>
            <label>Base currency
              <select name="currency" required>
                <option value="sek">SEK</option>
                <option value="eur">EUR</option>
                <option value="usd">USD</option>
              </select>
            </label>
            <label>Amount (minor units)
              <input type="number" name="unit_amount" min="1" required placeholder="e.g. 4900 = 49 kr">
            </label>
          </div>
          <div class="row">
            <label>Lookup key (optional)
              <input type="text" name="lookup_key" placeholder="e.g. shortly_pro_monthly">
            </label>
            <label>Nickname (optional)
              <input type="text" name="nickname" placeholder="internal label">
            </label>
          </div>
          <fieldset class="admin-fieldset">
            <legend>Other currencies on the same Price (optional)</legend>
            <div class="row">
              <label>SEK <input type="number" name="opt_sek" min="0" placeholder="minor units"></label>
              <label>EUR <input type="number" name="opt_eur" min="0" placeholder="minor units"></label>
              <label>USD <input type="number" name="opt_usd" min="0" placeholder="minor units"></label>
            </div>
            <p class="muted small">Leave blank for currencies you don’t want, or use the base-currency dropdown above. Stripe rejects entries that duplicate the base currency.</p>
          </fieldset>
          <button class="btn primary" type="submit" id="create-submit" <?= $productId === '' ? 'disabled' : '' ?>>Create Price</button>
        </form>
      </section>
    </main>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/admin_plans.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
