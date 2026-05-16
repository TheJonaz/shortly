<?php
/** @var string $q    Search filter from ?q= */
/** @var int    $page 1-based page number */
$q = $q ?? '';
$page = $page ?? 1;
$title = 'Admin · Users — Shortly';
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
        <a href="<?= base_path() ?>/admin/users" class="active">Users</a>
        <a href="<?= base_path() ?>/admin/plans">Plans</a>
        <a href="<?= base_path() ?>/admin/stats">Stats</a>
      </nav>

      <header class="admin-head">
        <h1>Users</h1>
        <form id="search-form" class="admin-search">
          <input type="search" name="q" id="q" value="<?= e($q) ?>" placeholder="Filter by email…" autocomplete="off">
          <button class="btn ghost small" type="submit">Search</button>
        </form>
      </header>

      <div id="admin-toast" class="admin-toast" hidden></div>

      <div id="users-wrap">Loading…</div>

      <div class="admin-pager" id="pager" hidden>
        <a href="#" id="prev-page">← Prev</a>
        <span id="page-info"></span>
        <a href="#" id="next-page">Next →</a>
      </div>
    </main>
  </div>

  <script type="module" src="<?= base_path() ?>/assets/js/admin_users.js"></script>

<?php require __DIR__ . '/_footer.php'; ?>
