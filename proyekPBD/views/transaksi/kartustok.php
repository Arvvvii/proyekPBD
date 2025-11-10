<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

$entries = $fetcher->kartuStokAll();
$title='Kartu Stok';
ob_start();
?>
<h1>Kartu Stok</h1>
<p>Menampilkan pergerakan stok terakhir (masuk/keluar) dengan jenis transaksi dan saldo berjalan.</p>
<table class="table"><thead><tr><th>ID</th><th>Tanggal</th><th>Barang</th><th>Jenis Tx</th><th>Masuk</th><th>Keluar</th><th>Stock Setelah</th><th>ID Transaksi</th></tr></thead><tbody>
<?php foreach($entries as $e): ?>
  <tr>
    <td><?= $e['idkartu_stok'] ?></td>
    <td><?= $e['created_at'] ?></td>
    <td><?= htmlspecialchars($e['nama_barang'] ?? $e['idbarang']) ?></td>
    <td><?= htmlspecialchars($e['jenis_transaksi']) ?></td>
    <td><?= (int)$e['masuk'] ?></td>
    <td><?= (int)$e['keluar'] ?></td>
    <td><?= (int)$e['stock'] ?></td>
    <td><?= htmlspecialchars($e['idtransaksi']) ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
