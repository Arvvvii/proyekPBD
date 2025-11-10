<?php
session_start();
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/AppConfig.php';
require_once __DIR__ . '/utilities/DataFetcher.php';

if(!isset($_SESSION['user_id'])) {
  header('Location: ' . BASE_PATH . '/login.php');
    exit;
}

$db = Database::getInstance();
$fetcher = new DataFetcher($db);

$stats = [
    'total_user' => $db->fetch("SELECT COUNT(*) AS cnt FROM v_userall")['cnt'] ?? 0,
    'total_barang_aktif' => $db->fetch("SELECT COUNT(*) AS cnt FROM v_barangaktif")['cnt'] ?? 0,
    'total_pengadaan' => $db->fetch("SELECT COUNT(*) AS cnt FROM v_pengadaanheader")['cnt'] ?? 0,
    'total_penjualan' => $db->fetch("SELECT COUNT(*) AS cnt FROM v_penjualanheader")['cnt'] ?? 0,
];

$stokSummary = $db->fetchAll("SELECT * FROM v_stoksummary ORDER BY nama_barang");

$title = 'Dashboard';
ob_start();
?>
<div class="dashboard">
  <h1>Selamat Datang, <?= htmlspecialchars($_SESSION['username']) ?> (<?= htmlspecialchars($_SESSION['role_name']) ?>)</h1>
  <div class="cards">
    <div class="card"><h3>User</h3><p><?= $stats['total_user'] ?></p></div>
    <div class="card"><h3>Barang Aktif</h3><p><?= $stats['total_barang_aktif'] ?></p></div>
    <div class="card"><h3>Pengadaan</h3><p><?= $stats['total_pengadaan'] ?></p></div>
    <div class="card"><h3>Penjualan</h3><p><?= $stats['total_penjualan'] ?></p></div>
  </div>
  <section class="stok">
    <h2>Ringkasan Stok (v_stoksummary)</h2>
    <table class="table">
      <thead><tr><th>ID Barang</th><th>Nama</th><th>Stok Saat Ini</th></tr></thead>
      <tbody>
        <?php foreach($stokSummary as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['idbarang']) ?></td>
            <td><?= htmlspecialchars($row['nama_barang']) ?></td>
            <td><?= htmlspecialchars($row['stok_saat_ini']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/views/template.php';
