<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

// Ambil header penerimaan untuk dipilih saat retur
$penerimaanHeader = $fetcher->penerimaanHeader();
$selectedPenerimaan = isset($_GET['idpenerimaan']) ? (int)$_GET['idpenerimaan'] : (count($penerimaanHeader) ? (int)$penerimaanHeader[0]['idpenerimaan'] : 0);
$detailOptions = $selectedPenerimaan ? $db->fetchAll('SELECT * FROM v_detailpenerimaanlengkap WHERE idpenerimaan = ? ORDER BY iddetail_penerimaan DESC', [$selectedPenerimaan]) : [];

$message='';
if($_SERVER['REQUEST_METHOD']==='POST') {
  $idpenerimaan = (int)$_POST['idpenerimaan'];
  $iduser = (int)$_SESSION['user_id'];
  $iddetail_penerimaan = (int)$_POST['iddetail_penerimaan'];
  $jumlah = (int)$_POST['jumlah'];
  $alasan = trim($_POST['alasan']);
  try{
    // 1) Insert header retur
    $db->execute('INSERT INTO retur_barang (idpenerimaan, iduser) VALUES (?,?)', [$idpenerimaan,$iduser]);
    $idretur = (int)$db->getConnection()->lastInsertId();
    // 2) Insert detail retur
    $db->execute('INSERT INTO detail_retur (jumlah, alasan, idretur, iddetail_penerimaan) VALUES (?,?,?,?)', [$jumlah,$alasan,$idretur,$iddetail_penerimaan]);
    // Catatan: Stok tidak otomatis berubah karena belum ada trigger retur; disarankan menambah trigger di DB.
    $message='Retur berhasil dicatat.';
  } catch(\Throwable $e) {
    $message='Gagal: '.$e->getMessage();
  }
}

// Listing header+detail retur (belum ada view; join minimal untuk tampilan)
$headerRetur = $db->fetchAll('SELECT rb.*, u.username, p.created_at AS tgl_penerimaan FROM retur_barang rb JOIN user u ON rb.iduser=u.iduser JOIN penerimaan p ON rb.idpenerimaan=p.idpenerimaan ORDER BY rb.created_at DESC');
$detailRetur = $db->fetchAll('SELECT dr.*, b.nama AS nama_barang FROM detail_retur dr LEFT JOIN detail_penerimaan dp ON dr.iddetail_penerimaan=dp.iddetail_penerimaan LEFT JOIN barang b ON dp.barang_idbarang=b.idbarang ORDER BY dr.iddetail_retur DESC');

$title='Transaksi Retur';
ob_start();
?>
<h1>Retur Barang</h1>
<?php if($message): ?><div class="card" style="background:#0b1220;padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Input Retur</h2>
  <form method="post" class="inline">
    <label>Penerimaan<br>
      <select name="idpenerimaan" onchange="location='?idpenerimaan='+this.value" required>
        <?php foreach($penerimaanHeader as $ph): ?>
          <option value="<?= $ph['idpenerimaan'] ?>" <?= $selectedPenerimaan==$ph['idpenerimaan']?'selected':'' ?>>#<?= $ph['idpenerimaan'] ?> - <?= htmlspecialchars($ph['nama_vendor']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Detail Penerimaan<br>
      <select name="iddetail_penerimaan" required>
        <?php foreach($detailOptions as $d): ?>
          <option value="<?= $d['iddetail_penerimaan'] ?>">#<?= $d['iddetail_penerimaan'] ?> - <?= htmlspecialchars($d['nama_barang']) ?> (<?= $d['jumlah_terima'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Jumlah Retur<br><input type="number" name="jumlah" min="1" required></label>
    <label>Alasan<br><input name="alasan" required></label>
    <button class="btn">Simpan</button>
  </form>
</section>
<section>
  <h2>Header Retur</h2>
  <table class="table"><thead><tr><th>ID</th><th>Tanggal</th><th>Penerimaan</th><th>User</th></tr></thead><tbody>
    <?php foreach($headerRetur as $h): ?>
      <tr>
        <td><?= $h['idretur'] ?></td>
        <td><?= $h['created_at'] ?></td>
        <td>#<?= $h['idpenerimaan'] ?> (<?= $h['tgl_penerimaan'] ?>)</td>
        <td><?= htmlspecialchars($h['username']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
</section>
<section>
  <h2>Detail Retur</h2>
  <table class="table"><thead><tr><th>ID Detail</th><th>ID Retur</th><th>Barang</th><th>Jumlah</th><th>Alasan</th></tr></thead><tbody>
    <?php foreach($detailRetur as $d): ?>
      <tr>
        <td><?= $d['iddetail_retur'] ?></td>
        <td><?= $d['idretur'] ?></td>
        <td><?= htmlspecialchars($d['nama_barang'] ?? '-') ?></td>
        <td><?= (int)$d['jumlah'] ?></td>
        <td><?= htmlspecialchars($d['alasan']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
