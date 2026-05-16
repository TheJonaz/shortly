<?php
$title = 'Admin · Stats — Shortly';
require __DIR__ . '/_header.php';
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
        <a href="<?= base_path() ?>/admin/plans">Plans</a>
        <a href="<?= base_path() ?>/admin/stats" class="active">Stats</a>
      </nav>

      <header class="admin-head">
        <h1>Visitor stats</h1>
        <p class="muted" style="margin:0;font-size:13px;">
          Click events on shortened links. Country resolved at click time
          via ipinfo.io — historical rows pre-dating that show as <code>??</code>.
        </p>
      </header>

      <div id="admin-toast" class="admin-toast" hidden></div>

      <section class="admin-block" style="margin-top:0;padding-top:0;border-top:0;">
        <div class="stat-grid" id="stat-cards">
          <div class="stat-card" data-stat="totals-all"><span class="stat-label">Total clicks</span><span class="stat-value">—</span><span class="stat-sub">all time</span></div>
          <div class="stat-card" data-stat="totals-30"><span class="stat-label">Total clicks</span><span class="stat-value">—</span><span class="stat-sub">last 30 days</span></div>
          <div class="stat-card" data-stat="totals-7"><span class="stat-label">Total clicks</span><span class="stat-value">—</span><span class="stat-sub">last 7 days</span></div>
          <div class="stat-card" data-stat="uniq-all"><span class="stat-label">Unique visitors</span><span class="stat-value">—</span><span class="stat-sub">all time</span></div>
          <div class="stat-card" data-stat="uniq-30"><span class="stat-label">Unique visitors</span><span class="stat-value">—</span><span class="stat-sub">last 30 days</span></div>
          <div class="stat-card" data-stat="uniq-7"><span class="stat-label">Unique visitors</span><span class="stat-value">—</span><span class="stat-sub">last 7 days</span></div>
        </div>
      </section>

      <section class="admin-block">
        <h2>Top countries · last 30 days</h2>
        <div id="countries-wrap">Loading…</div>
      </section>
    </main>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/admin_stats.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
