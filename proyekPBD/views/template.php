<?php
require_once __DIR__ . '/../config/AppConfig.php';
// centralize session start so individual views don't call session_start()
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
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
    /* === TEMA BIRU PROFESIONAL === */
    :root {
      --primary-blue: #0066CC;
      --primary-dark: #004999;
      --primary-light: #3399FF;
      --bg-main: #F5F8FA;
      --bg-sidebar: #FFFFFF;
      --bg-card: #FFFFFF;
      --text-primary: #1A1A1A;
      --text-secondary: #666666;
      --text-muted: #999999;
      --border-color: #E0E7EE;
      --hover-bg: #F0F4F8;
      --success: #00A651;
      --warning: #FF9500;
      --danger: #DC3545;
      --info: #17A2B8;
      --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
      --shadow-md: 0 4px 12px rgba(0,0,0,0.1);
      --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
    }
    
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    
    body {
      margin: 0;
      font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
      background: var(--bg-main);
      color: var(--text-primary);
      font-size: 14px;
      line-height: 1.6;
    }
    
    /* === LAYOUT === */
    .layout {
      display: grid;
      grid-template-columns: 280px 1fr;
      min-height: 100vh;
    }
    
    /* === SIDEBAR === */
    aside {
      background: var(--bg-sidebar);
      border-right: 2px solid var(--border-color);
      padding: 24px 16px;
      display: flex;
      flex-direction: column;
      box-shadow: var(--shadow-sm);
      overflow-y: auto;
    }
    
    .brand {
      font-weight: 700;
      font-size: 24px;
      color: var(--primary-blue);
      margin: 0 0 32px;
      padding: 0 12px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .brand::before {
      content: "📦";
      font-size: 28px;
    }
    
    /* === NAVIGATION === */
    nav {
      flex: 1;
    }
    
    .section {
      margin-bottom: 24px;
    }
    
    .section h4 {
      margin: 0 0 8px;
      padding: 8px 12px;
      color: var(--text-secondary);
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      display: flex;
      justify-content: space-between;
      align-items: center;
      cursor: pointer;
      user-select: none;
      border-radius: 6px;
      transition: background 0.2s;
    }
    
    .section h4:hover {
      background: var(--hover-bg);
    }
    
    .section h4::after {
      content: "▼";
      font-size: 10px;
      transition: transform 0.2s;
    }
    
    .section.collapsed h4::after {
      transform: rotate(-90deg);
    }
    
    .section-links {
      display: flex;
      flex-direction: column;
      gap: 2px;
      overflow: hidden;
      transition: max-height 0.3s ease;
    }
    
    .collapsed .section-links {
      max-height: 0;
      display: none;
    }
    
    nav a {
      display: flex;
      align-items: center;
      gap: 12px;
      color: var(--text-primary);
      text-decoration: none;
      padding: 12px 16px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 500;
      transition: all 0.2s;
      position: relative;
    }
    
    nav a:hover {
      background: var(--hover-bg);
      color: var(--primary-blue);
      transform: translateX(4px);
    }
    
    nav a:active {
      transform: translateX(2px);
    }
    
    .badge {
      display: inline-block;
      padding: 3px 8px;
      font-size: 10px;
      font-weight: 700;
      border-radius: 12px;
      background: var(--primary-blue);
      color: white;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    
    /* === HEADER === */
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px 32px;
      background: white;
      border-bottom: 2px solid var(--border-color);
      box-shadow: var(--shadow-sm);
      position: sticky;
      top: 0;
      z-index: 100;
    }
    
    header strong {
      font-size: 20px;
      color: var(--text-primary);
      font-weight: 700;
    }
    
    header .user {
      color: var(--text-secondary);
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      background: var(--bg-main);
      border-radius: 8px;
      font-weight: 500;
    }
    
    /* === MAIN CONTENT === */
    main {
      padding: 32px;
      max-width: 1400px;
    }
    
    /* === SEARCH BAR === */
    .search-bar {
      margin: 0 0 24px;
    }
    
    .search-bar input {
      width: 100%;
      max-width: 400px;
      padding: 12px 16px;
      border-radius: 8px;
      border: 2px solid var(--border-color);
      background: white;
      color: var(--text-primary);
      font-size: 14px;
      transition: all 0.2s;
    }
    
    .search-bar input:focus {
      outline: none;
      border-color: var(--primary-blue);
      box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
    }
    
    .search-bar input::placeholder {
      color: var(--text-muted);
    }
    
    /* === DASHBOARD === */
    .dashboard h1 {
      font-size: 28px;
      color: var(--text-primary);
      margin: 0 0 24px;
      font-weight: 700;
    }
    
    /* === CARDS === */
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px;
      margin: 0 0 32px;
    }
    
    .card {
      background: var(--bg-card);
      border: 2px solid var(--border-color);
      border-radius: 12px;
      padding: 24px;
      text-align: center;
      box-shadow: var(--shadow-sm);
      transition: all 0.3s;
      position: relative;
      overflow: hidden;
    }
    
    .card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary-blue), var(--primary-light));
    }
    
    .card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-md);
      border-color: var(--primary-light);
    }
    
    .card h3 {
      font-size: 13px;
      color: var(--text-secondary);
      margin: 0 0 12px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
    }
    
    .card p {
      font-size: 36px;
      font-weight: 700;
      color: var(--primary-blue);
      margin: 0;
    }
    
    /* === SECTIONS === */
    section.stok {
      background: white;
      border-radius: 12px;
      padding: 28px;
      box-shadow: var(--shadow-sm);
      border: 2px solid var(--border-color);
    }
    
    section.stok h2 {
      font-size: 20px;
      color: var(--text-primary);
      margin: 0 0 20px;
      font-weight: 700;
      padding-bottom: 16px;
      border-bottom: 2px solid var(--border-color);
    }
    
    /* === TABLES === */
    .table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      background: white;
      border-radius: 8px;
      overflow: hidden;
    }
    
    .table thead {
      background: linear-gradient(135deg, var(--primary-blue), var(--primary-dark));
    }
    
    .table th {
      padding: 14px 16px;
      text-align: left;
      font-weight: 600;
      font-size: 13px;
      color: white;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border: none;
    }
    
    .table td {
      padding: 14px 16px;
      border-bottom: 1px solid var(--border-color);
      color: var(--text-primary);
      font-size: 14px;
    }
    
    .table tbody tr {
      transition: background 0.2s;
    }
    
    .table tbody tr:hover {
      background: var(--hover-bg);
    }
    
    .table tbody tr:last-child td {
      border-bottom: none;
    }
    
    /* === STATUS BADGES === */
    .status-badge {
      padding: 4px 12px;
      border-radius: 16px;
      font-size: 12px;
      font-weight: 600;
      display: inline-block;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    
    .status-A {
      background: #D4EDDA;
      color: #155724;
    }
    
    .status-P {
      background: #FFF3CD;
      color: #856404;
    }
    
    .status-0 {
      background: #F8D7DA;
      color: #721C24;
    }
    
    .status-1 {
      background: #D1ECF1;
      color: #0C5460;
    }
    
    /* === BUTTONS === */
    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    
    .btn {
      background: var(--primary-blue);
      color: white;
      border: none;
      padding: 10px 18px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    
    .btn:hover {
      background: var(--primary-dark);
      transform: translateY(-1px);
      box-shadow: var(--shadow-sm);
    }
    
    .btn:active {
      transform: translateY(0);
    }
    
    .btn.secondary {
      background: #6C757D;
    }
    
    .btn.secondary:hover {
      background: #5A6268;
    }
    
    .btn.danger {
      background: var(--danger);
    }
    
    .btn.danger:hover {
      background: #C82333;
    }
    
    .btn.success {
      background: var(--success);
    }
    
    .btn.success:hover {
      background: #008A44;
    }
    
    /* === FORMS === */
    form.inline {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: end;
      background: white;
      padding: 20px;
      border-radius: 12px;
      box-shadow: var(--shadow-sm);
      border: 2px solid var(--border-color);
      margin-bottom: 24px;
    }
    
    form.inline > div {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    
    form.inline label {
      font-size: 13px;
      font-weight: 600;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    
    input, select, textarea {
      background: white;
      color: var(--text-primary);
      border: 2px solid var(--border-color);
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 14px;
      font-family: inherit;
      transition: all 0.2s;
    }
    
    input:focus, select:focus, textarea:focus {
      outline: none;
      border-color: var(--primary-blue);
      box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
    }
    
    textarea {
      resize: vertical;
      min-height: 80px;
    }
    
    /* === UTILITIES === */
    .text-center {
      text-align: center;
    }
    
    .mb-3 {
      margin-bottom: 24px;
    }
    
    .mt-3 {
      margin-top: 24px;
    }
    
    /* === RESPONSIVE === */
    @media (max-width: 968px) {
      .layout {
        grid-template-columns: 1fr;
      }
      
      aside {
        display: none;
      }
      
      main {
        padding: 20px;
      }
      
      .cards {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      }
    }

    /* === TRANSAKSI LAYOUT (dua kolom) === */
    .txn-wrap {
      display: grid;
      /* make left column wider for better readability */
      grid-template-columns: 480px 1fr;
      gap: 24px;
      align-items: start;
    }

    .txn-panel {
      background: white;
      border-radius: 12px;
      padding: 18px;
      border: 2px solid var(--border-color);
      box-shadow: var(--shadow-sm);
    }

    .txn-left .table { max-height: 64vh; overflow: auto; display:block; }
    .txn-right .table { max-height: 64vh; overflow: auto; display:block; }

    .detail-empty {
      padding: 24px; text-align: center; color: var(--text-muted);
    }
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
