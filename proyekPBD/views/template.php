<?php
require_once __DIR__ . '/../config/AppConfig.php';
if (!isset($title)) { $title = 'Inventory'; }
if (!isset($content)) { $content = '<p>No content</p>'; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($title) ?> - Inventory</title>
  <style>
    :root{--bg:#0f172a;--panel:#111827;--muted:#94a3b8;--text:#e5e7eb;--brand:#2563eb;--line:#1f2937}
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,Arial,sans-serif;background:var(--bg);color:var(--text);}
    
    .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
  aside{background:linear-gradient(180deg,#111827,#0d1320);border-right:1px solid var(--line);padding:16px;display:flex;flex-direction:column}
    .brand{font-weight:700;color:#fff;margin:0 0 12px}
  nav a{display:flex;align-items:center;gap:8px;color:var(--text);text-decoration:none;padding:10px 12px;border-radius:8px;margin:2px 0;font-size:14px;transition:.15s background,.15s color}
  nav a:hover{background:#1e293b;color:#fff}
    .section{margin-top:14px}
  .section h4{margin:14px 0 6px;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.1em;display:flex;justify-content:space-between;align-items:center;cursor:pointer}
  .badge{display:inline-block;padding:2px 6px;font-size:11px;border-radius:12px;background:#1e3a8a;color:#bfdbfe;margin-left:4px}
  .status-badge{padding:2px 8px;border-radius:14px;font-size:12px;font-weight:600}
  .status-A{background:#065f46;color:#d1fae5}
  .status-P{background:#92400e;color:#fcd34d}
  .status-0{background:#991b1b;color:#fecaca}
  .status-1{background:#1e3a8a;color:#bfdbfe}
  .search-bar{margin:4px 0 12px}
  .search-bar input{width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--line);background:#0b1220;color:#e5e7eb}
  .collapsed .section-links{display:none}

    header{display:flex;justify-content:space-between;align-items:center;padding:16px;border-bottom:1px solid var(--line)}
    header .user{color:var(--muted);font-size:14px}
    main{padding:16px 24px}

    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:10px 0 20px}
    .card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px;text-align:center}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left}
    .actions{display:flex;gap:8px}
    .btn{background:var(--brand);color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer}
    .btn.secondary{background:#334155}
    .btn.danger{background:#dc2626}
    form.inline{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
    input,select{background:#0b1220;color:#e5e7eb;border:1px solid var(--line);border-radius:8px;padding:8px 10px}
  </style>
</head>
<body>
  <div class="layout">
    <aside>
      <h2 class="brand">Inventory</h2>
      <nav id="sidebarNav">
        <div class="section">
          <h4 onclick="toggleSection(this)">Home<span class="badge">Core</span></h4>
          <a href="<?= BASE_PATH ?>/index.php">Dashboard</a>
        </div>
        <div class="section">
          <h4 onclick="toggleSection(this)">Data Master</h4>
          <div class="section-links">
            <a href="<?= BASE_PATH ?>/views/master/user.php">👥 User</a>
            <a href="<?= BASE_PATH ?>/views/master/barang.php">📦 Barang</a>
            <a href="<?= BASE_PATH ?>/views/master/satuan.php">⚖️ Satuan</a>
            <a href="<?= BASE_PATH ?>/views/master/vendor.php">🏢 Vendor</a>
            <a href="<?= BASE_PATH ?>/views/master/role.php">🔐 Role</a>
            <a href="<?= BASE_PATH ?>/views/master/margin.php">💹 Margin</a>
          </div>
        </div>
        <div class="section">
          <h4 onclick="toggleSection(this)">Transaksi</h4>
          <div class="section-links">
            <a href="<?= BASE_PATH ?>/views/transaksi/pengadaan.php">📝 Pengadaan</a>
            <a href="<?= BASE_PATH ?>/views/transaksi/penerimaan.php">📥 Penerimaan</a>
            <a href="<?= BASE_PATH ?>/views/transaksi/penjualan.php">🛒 Penjualan</a>
            <a href="<?= BASE_PATH ?>/views/transaksi/retur.php">↩️ Retur</a>
            <a href="<?= BASE_PATH ?>/views/transaksi/kartustok.php">📊 Kartu Stok</a>
          </div>
        </div>
        <div class="section">
          <h4 onclick="toggleSection(this)">Akun</h4>
          <a href="<?= BASE_PATH ?>/logout.php">Logout</a>
        </div>
      </nav>
    </aside>
    <div>
      <header>
        <div><strong><?= htmlspecialchars($title) ?></strong></div>
        <div class="user">👤 <?= htmlspecialchars($_SESSION['username'] ?? '-') ?> (<?= htmlspecialchars($_SESSION['role_name'] ?? '-') ?>)</div>
      </header>
      <main>
        <div class="search-bar"><input type="text" placeholder="Cari di tabel aktif..." oninput="globalSearch(this.value)"></div>
        <?= $content ?>
      </main>
    </div>
  </div>
</body>
<script>
function toggleSection(el){
  const section = el.parentElement;
  section.classList.toggle('collapsed');
}
function globalSearch(q){
  q = q.toLowerCase();
  const tables = document.querySelectorAll('table');
  tables.forEach(t=>{
    const rows = t.querySelectorAll('tbody tr');
    let matchCount=0;
    rows.forEach(r=>{
      const text = r.innerText.toLowerCase();
      const show = text.indexOf(q) !== -1;
      r.style.display = show ? '' : 'none';
      if(show) matchCount++;
    });
    t.dataset.matches = matchCount;
  });
}
</script>
</html>
