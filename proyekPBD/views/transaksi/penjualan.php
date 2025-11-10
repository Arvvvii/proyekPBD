<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

$barang = $fetcher->barangAktif();
$marginAktif = $db->fetchAll('SELECT * FROM v_marginaktif ORDER BY created_at DESC');
$message = '';
$activeId = isset($_GET['idpenjualan']) ? (int)$_GET['idpenjualan'] : 0;
$defaultMarginId = isset($marginAktif[0]['idmargin_penjualan']) ? (int)$marginAktif[0]['idmargin_penjualan'] : 0;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Single page: langsung panggil SP insert header+detail
  $iduser = (int)$_SESSION['user_id'];
  $idmargin = (int)($_POST['idmargin_penjualan'] ?? 0);
  if($idmargin === 0 && $defaultMarginId > 0){
    // Pakai margin aktif default bila user tidak memilih
    $idmargin = $defaultMarginId;
  }
  $idbarang = (int)$_POST['idbarang'];
  $jumlah = (int)$_POST['jumlah'];
  $harga = (int)$_POST['harga_satuan'];
  try{
    $db->callProcedure('sp_insert_penjualan_lengkap', [$iduser,$idmargin,$idbarang,$jumlah,$harga]);
    // Ambil header terbaru untuk user ini, lalu update total
    $header = $db->fetch('SELECT idpenjualan FROM penjualan WHERE iduser = ? ORDER BY created_at DESC LIMIT 1', [$iduser]);
    if ($header) {
      $db->callProcedure('SP_UpdateHeaderPenjualan', [$header['idpenjualan']]);
    }
    $_SESSION['flash_penjualan'] = 'Transaksi penjualan berhasil.';
    header('Location: ' . BASE_PATH . '/views/transaksi/penjualan.php?idpenjualan=' . urlencode($header['idpenjualan']));
    exit;
  } catch (Throwable $e) {
    $message = 'Gagal: ' . $e->getMessage();
  }
}

$headersAll = $fetcher->penjualanHeader();
if(isset($_SESSION['flash_penjualan'])){ $message = $_SESSION['flash_penjualan']; unset($_SESSION['flash_penjualan']); }

if ($activeId) {
  $currentHeader = array_values(array_filter($headersAll, fn($h) => (int)$h['idpenjualan'] === $activeId));
  $otherHeaders = array_values(array_filter($headersAll, fn($h) => (int)$h['idpenjualan'] !== $activeId));
} else {
  $currentHeader = [];
  $otherHeaders = $headersAll;
}
$details = $db->fetchAll('SELECT * FROM v_detailpenjualanlengkap ORDER BY idpenjualan DESC, iddetail_penjualan DESC');

$title = 'Transaksi Penjualan';
ob_start();
?>
<h1>Transaksi Penjualan</h1>
<?php if ($message): ?><div class="card" style="background:#0b1220; padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Input Detail</h2>
  <form method="post" class="inline">
    <label>Margin Aktif<br>
      <?php if(count($marginAktif) === 1): ?>
        <div style="padding:6px 10px;background:#182235;border-radius:4px;display:inline-block;min-width:140px">
          <?= (float)$marginAktif[0]['persen'] ?> % (auto)
        </div>
        <input type="hidden" name="idmargin_penjualan" value="<?= $marginAktif[0]['idmargin_penjualan'] ?>">
      <?php else: ?>
        <select name="idmargin_penjualan" id="idmargin_penjualan">
          <option value="">- pilih margin -</option>
          <?php foreach($marginAktif as $m): ?>
            <option value="<?= $m['idmargin_penjualan'] ?>" data-persen="<?= (float)$m['persen'] ?>">
              <?= (float)$m['persen'] ?> %
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </label>
    <label>Barang<br>
      <select name="idbarang" id="idbarang_penjualan" required>
        <?php foreach($barang as $b): ?>
          <option value="<?= $b['idbarang'] ?>" data-harga="<?= (int)$b['harga'] ?>">
            <?= htmlspecialchars($b['nama_barang']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Jumlah<br><input type="number" name="jumlah" min="1" required></label>
    <label>Harga Satuan<br><input type="number" name="harga_satuan" id="harga_satuan_penjualan" min="0" required></label>
    <button class="btn">Simpan</button>
  </form>
</section>
<script>
document.addEventListener('DOMContentLoaded', function(){
  // Auto-select first active margin if select exists and empty
  const marginSel = document.getElementById('idmargin_penjualan');
  if(marginSel && !marginSel.value){
    const firstOpt = Array.from(marginSel.options).find(o => o.value !== '');
    if(firstOpt){ marginSel.value = firstOpt.value; }
  }
  // Auto-fill harga satuan saat pilih barang
  const barangSel = document.getElementById('idbarang_penjualan');
  const hargaInput = document.getElementById('harga_satuan_penjualan');
  function applyHarga(){
    if(!barangSel || !hargaInput) return;
    const opt = barangSel.selectedOptions[0];
    const h = opt ? parseInt(opt.dataset.harga || '0') : 0;
    hargaInput.value = h || '';
  }
  if(barangSel){
    barangSel.addEventListener('change', applyHarga);
    applyHarga(); // initial fill
  }
});
</script>
<section>
  <h2>Header Penjualan (v_penjualanheader)</h2>
  <?php if($activeId && $currentHeader): ?>
    <h3 style="margin-top:10px">Sedang Dikerjakan (ID #<?= $activeId ?>)</h3>
    <table class="table">
      <thead><tr><th>ID</th><th>Tanggal</th><th>Kasir</th><th>Margin</th><th>Total</th></tr></thead>
      <tbody>
        <?php foreach($currentHeader as $h): ?>
          <tr style="background:#182235">
            <td><?= $h['idpenjualan'] ?></td>
            <td><?= $h['tanggal_penjualan'] ?></td>
            <td><?= htmlspecialchars($h['kasir']) ?></td>
            <td><?= (float)$h['margin_persen'] ?>%</td>
            <td><?= number_format($h['total_nilai']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <h3 style="margin-top:24px">Riwayat / Lainnya</h3>
  <?php endif; ?>
  <table class="table">
    <thead><tr><th>ID</th><th>Tanggal</th><th>Kasir</th><th>Margin</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach($otherHeaders as $h): ?>
        <tr>
          <td><a href="?idpenjualan=<?= $h['idpenjualan'] ?>"><?= $h['idpenjualan'] ?></a></td>
          <td><?= $h['tanggal_penjualan'] ?></td>
          <td><?= htmlspecialchars($h['kasir']) ?></td>
          <td><?= (float)$h['margin_persen'] ?>%</td>
          <td><?= number_format($h['total_nilai']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<section>
  <h2>Detail Penjualan (v_detailpenjualanlengkap)</h2>
  <table class="table">
    <thead><tr><th>ID Detail</th><th>ID Penjualan</th><th>Barang</th><th>Jumlah</th><th>Harga</th><th>Subtotal</th></tr></thead>
    <tbody>
      <?php foreach($details as $d): ?>
        <tr>
          <td><?= $d['iddetail_penjualan'] ?></td>
          <td><?= $d['idpenjualan'] ?></td>
          <td><?= htmlspecialchars($d['nama_barang']) ?></td>
          <td><?= $d['jumlah'] ?></td>
          <td><?= number_format($d['harga_satuan']) ?></td>
          <td><?= number_format($d['sub_total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
