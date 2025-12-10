<?php
// Ensure session is started before using $_SESSION (template includes start later)
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

// deteksi apakah tabel detail_pengadaan memiliki kolom is_deleted (agar kompatibel dengan DB lama)
$hasIsDeleted = false;
try {
  $col = $db->fetch("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'detail_pengadaan' AND column_name = 'is_deleted'");
  $hasIsDeleted = !empty($col['cnt']);
} catch (Throwable $_) {
  $hasIsDeleted = false;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan.php'); exit; }

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
    if ($action === 'add_detail') {
    $idpengadaan = (int)$id;
    // cek apakah header sudah difinalisasi (status='F')
    $hdr = $db->fetch('SELECT status FROM pengadaan WHERE idpengadaan = ? LIMIT 1', [$idpengadaan]);
    $hdrStatus = strtoupper(trim($hdr['status'] ?? ''));
    if ($hdr && $hdrStatus === 'F') {
      $message = 'Pengadaan sudah final. Tidak bisa ditambah detail.';
    } else {
      $idbarang = (int)($_POST['idbarang'] ?? 0);
      $jumlah = (int)($_POST['jumlah_pesan'] ?? 0);
      $harga = (int)($_POST['harga_satuan'] ?? 0);
      if ($idbarang <=0 || $jumlah <= 0) { $message = 'Barang / jumlah tidak valid.'; }
      else {
        try {
          $sub = $jumlah * $harga;
          $db->beginTransaction();
          try {
            $db->callProcedure('sp_InsertDetailPengadaan', [ $idpengadaan, $idbarang, $jumlah, $harga ]);
          } catch (Throwable $e1) {
            try {
              $db->callProcedure('sp_InsertDetailPengadaan', [ $idpengadaan, $idbarang, $jumlah, $harga, $sub ]);
            } catch (Throwable $e2) {
              $db->execute('INSERT INTO detail_pengadaan (idpengadaan, idbarang, jumlah, harga_satuan, sub_total) VALUES (?,?,?,?,?)', [$idpengadaan, $idbarang, $jumlah, $harga, $sub]);
            }
          }
          // update totals
          try {
            $db->callProcedure('SP_UpdatePengadaanTotals', [ $idpengadaan ]);
          } catch (Throwable $esp) {
            $sumQuery = $hasIsDeleted
              ? 'SELECT COALESCE(SUM(sub_total),0) AS subtotal FROM detail_pengadaan WHERE idpengadaan = ? AND is_deleted = 0'
              : 'SELECT COALESCE(SUM(sub_total),0) AS subtotal FROM detail_pengadaan WHERE idpengadaan = ?';
            $sum = $db->fetch($sumQuery, [$idpengadaan]);
            $subtotal = (int)($sum['subtotal'] ?? 0);
            $ppn = (int)round($subtotal * 0.11);
            $total = $subtotal + $ppn;
            $db->execute('UPDATE pengadaan SET subtotal_nilai=?, ppn=?, total_nilai=? WHERE idpengadaan=?', [$subtotal, $ppn, $total, $idpengadaan]);
          }
          $db->commit();
          $_SESSION['flash_pengadaan'] = 'Detail ditambahkan.';
          header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan_detail.php?id=' . $idpengadaan);
          exit;
        } catch (Throwable $e) {
          try { $db->rollBack(); } catch (Throwable $_) {}
          $message = 'Gagal menambah detail: ' . $e->getMessage();
        }
      }
    }
  } elseif ($action === 'delete_detail') {
    $iddetail = (int)($_POST['iddetail'] ?? 0);
    if ($iddetail <= 0) { $message = 'ID detail tidak valid.'; }
    else {
      try {
        $db->beginTransaction();
        // ambil idpengadaan untuk update totals
        $row = $db->fetch('SELECT idpengadaan, sub_total FROM detail_pengadaan WHERE iddetail_pengadaan = ? LIMIT 1', [$iddetail]);
        if (!$row) { throw new RuntimeException('Detail tidak ditemukan'); }
        $idpengadaan = (int)$row['idpengadaan'];
        // cek apakah header sudah difinalisasi (status='F')
        $hdr2 = $db->fetch('SELECT status FROM pengadaan WHERE idpengadaan = ? LIMIT 1', [$idpengadaan]);
        $hdr2Status = strtoupper(trim($hdr2['status'] ?? ''));
        if ($hdr2 && $hdr2Status === 'F') { throw new RuntimeException('Pengadaan sudah final. Tidak bisa diubah.'); }
        // hapus: gunakan soft-delete jika kolom tersedia, jika tidak lakukan DELETE fisik
        if ($hasIsDeleted) {
          $db->execute('UPDATE detail_pengadaan SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? WHERE iddetail_pengadaan = ?', [ $_SESSION['user_id'] ?? null, $iddetail ]);
        } else {
          $db->execute('DELETE FROM detail_pengadaan WHERE iddetail_pengadaan = ?', [$iddetail]);
        }
        // update totals
        try {
          $db->callProcedure('SP_UpdatePengadaanTotals', [ $idpengadaan ]);
        } catch (Throwable $esp) {
          $sumQuery = $hasIsDeleted
            ? 'SELECT COALESCE(SUM(sub_total),0) AS subtotal FROM detail_pengadaan WHERE idpengadaan = ? AND is_deleted = 0'
            : 'SELECT COALESCE(SUM(sub_total),0) AS subtotal FROM detail_pengadaan WHERE idpengadaan = ?';
          $sum = $db->fetch($sumQuery, [$idpengadaan]);
          $subtotal = (int)($sum['subtotal'] ?? 0);
          $ppn = (int)round($subtotal * 0.11);
          $total = $subtotal + $ppn;
          $db->execute('UPDATE pengadaan SET subtotal_nilai=?, ppn=?, total_nilai=? WHERE idpengadaan=?', [$subtotal, $ppn, $total, $idpengadaan]);
        }
        $db->commit();
        $_SESSION['flash_pengadaan'] = 'Detail dihapus.';
        header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan_detail.php?id=' . $idpengadaan);
        exit;
      } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable $_) {}
        $message = 'Gagal hapus detail: ' . $e->getMessage();
      }
    }
  }
  elseif ($action === 'finalize') {
    // finalisasi header: set status = 'F'
    $idpengadaan = (int)$id;
    try {
      $db->beginTransaction();
      $db->execute("UPDATE pengadaan SET status = ? WHERE idpengadaan = ?", ['F', $idpengadaan]);
      $db->commit();
      $_SESSION['flash_pengadaan'] = 'Pengadaan telah difinalisasi.';
      header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan_detail.php?id=' . $idpengadaan);
      exit;
    } catch (Throwable $e) {
      try { $db->rollBack(); } catch (Throwable $_) {}
      $message = 'Gagal finalisasi: ' . $e->getMessage();
    }
  }
}

// fetch header and details
$header = $db->fetch('SELECT p.*, v.nama_vendor, u.username AS dibuat_oleh FROM pengadaan p LEFT JOIN vendor v ON v.idvendor = p.vendor_idvendor LEFT JOIN `user` u ON u.iduser = p.user_iduser WHERE p.idpengadaan = ? LIMIT 1', [$id]);
$detailsQuery = $hasIsDeleted
  ? 'SELECT dp.*, b.nama AS nama_barang, s.nama_satuan FROM detail_pengadaan dp LEFT JOIN barang b ON b.idbarang = dp.idbarang LEFT JOIN satuan s ON s.idsatuan = b.idsatuan WHERE dp.idpengadaan = ? AND dp.is_deleted = 0 ORDER BY dp.iddetail_pengadaan DESC'
  : 'SELECT dp.*, b.nama AS nama_barang, s.nama_satuan FROM detail_pengadaan dp LEFT JOIN barang b ON b.idbarang = dp.idbarang LEFT JOIN satuan s ON s.idsatuan = b.idsatuan WHERE dp.idpengadaan = ? ORDER BY dp.iddetail_pengadaan DESC';
$details = $db->fetchAll($detailsQuery, [$id]);
$header_status = strtoupper(trim($header['status'] ?? ''));
$isFinalized = ($header_status === 'F');
$barang = $fetcher->barangAktif();

$title = 'Detail Pengadaan #' . $id;
ob_start();
?>
<h1>Detail Pengadaan #<?= $id ?></h1>
<?php if(!empty($_SESSION['flash_pengadaan'])){ echo '<div class="card" style="padding:12px; background:#DFF0D8; color:#155724; border:2px solid #C3E6CB; margin-bottom:12px">'.htmlspecialchars($_SESSION['flash_pengadaan']).'</div>'; unset($_SESSION['flash_pengadaan']); }
if($message){ echo '<div class="card" style="padding:12px; background:#F8D7DA; color:#721C24; border:2px solid #F5C6CB; margin-bottom:12px">'.htmlspecialchars($message).'</div>'; }
?>
<div class="txn-wrap">
  <div class="txn-left txn-panel">
    <h3>Ringkasan</h3>
    <?php if($header): ?>
      <div style="margin-top:8px">
        <div style="font-weight:700">Vendor: <?= htmlspecialchars($header['nama_vendor'] ?? '-') ?></div>
        <div style="color:var(--text-secondary); margin-top:4px">Dibuat oleh: <?= htmlspecialchars($header['dibuat_oleh'] ?? '-') ?> • Status: <span class="status-badge status-<?= htmlspecialchars($header['status'] ?? '0') ?>"><?= htmlspecialchars($header['status'] ?? '-') ?></span></div>
      </div>
      <?php $sumQuery = $hasIsDeleted
        ? 'SELECT COALESCE(SUM(sub_total),0) AS subtotal FROM detail_pengadaan WHERE idpengadaan = ? AND is_deleted = 0'
        : 'SELECT COALESCE(SUM(sub_total),0) AS subtotal FROM detail_pengadaan WHERE idpengadaan = ?';
        $sum = $db->fetch($sumQuery, [$id]); $subtotal = (int)($sum['subtotal'] ?? 0); $ppn = (int)round($subtotal * 0.11); $total = $subtotal + $ppn; ?>
      <div style="margin-top:12px">
        <div style="padding:12px;background:#FBFCFE;border-radius:8px;border:1px solid var(--border-color);">Subtotal: <strong>Rp <?= number_format($subtotal) ?></strong></div>
        <div style="padding:12px;background:#FFF9F0;border-radius:8px;border:1px solid var(--border-color); margin-top:8px">PPN (11%): <strong>Rp <?= number_format($ppn) ?></strong></div>
        <div style="padding:12px;background:#F8FFF9;border-radius:8px;border:1px solid var(--border-color); margin-top:8px">Total: <strong style="color:var(--primary-blue)">Rp <?= number_format($total) ?></strong></div>
      </div>
    <?php else: ?>
      <div class="detail-empty">Header tidak ditemukan.</div>
    <?php endif; ?>
    <div style="margin-top:16px">
      <?php if($header && !$isFinalized): ?>
        <form method="post" onsubmit="return confirm('Finalisasi pengadaan? Setelah finalisasi tidak dapat diubah.');" style="display:inline-block">
          <input type="hidden" name="action" value="finalize">
          <button class="btn secondary">Finalisasi / Kunci</button>
        </form>
      <?php else: ?>
        <a class="btn" href="pengadaan.php">Kembali ke Header</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="txn-right txn-panel">
    <h3>Tambah Detail</h3>
    <?php if(!$header || !$isFinalized): ?>
    <form method="post" class="inline" onsubmit="return confirm('Simpan detail?');">
      <input type="hidden" name="action" value="add_detail">
      <label>Barang<br>
        <select name="idbarang" id="idbarang_select" required>
          <?php foreach($barang as $b): ?>
            <option value="<?= $b['idbarang'] ?>" data-harga="<?= (int)$b['harga'] ?>"><?= htmlspecialchars($b['nama_barang']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Jumlah<br><input type="text" id="jumlah_pesan" name="jumlah_pesan" inputmode="numeric" pattern="\d*" value="1" required placeholder="Masukkan jumlah"></label>
      <label>Harga Satuan<br><input type="number" id="harga_satuan" name="harga_satuan" min="0" value="<?= (int)($barang[0]['harga'] ?? 0) ?>" required></label>
      <button class="btn" type="submit">Simpan Detail</button>
    </form>
    <?php else: ?>
      <div class="card" style="padding:12px; background:#F1F7FF; border:1px solid var(--border-color);">Pengadaan ini telah difinalisasi. Tidak dapat menambah atau menghapus detail.</div>
    <?php endif; ?>

    <h3 style="margin-top:18px">Daftar Detail</h3>
    <?php if($details): ?>
      <table class="table"><thead><tr><th>ID</th><th>Barang</th><th>Jumlah</th><th>Harga</th><th>Subtotal</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach($details as $d): ?>
          <tr>
            <td><?= $d['iddetail_pengadaan'] ?></td>
            <td><?= htmlspecialchars($d['nama_barang']) ?></td>
            <td><?= $d['jumlah'] ?></td>
            <td><?= number_format($d['harga_satuan']) ?></td>
            <td><?= number_format($d['sub_total']) ?></td>
            <td>
              <?php if(!$header || !$isFinalized): ?>
                <form method="post" style="display:inline-block" onsubmit="return confirm('Hapus detail ini?');">
                  <input type="hidden" name="action" value="delete_detail">
                  <input type="hidden" name="iddetail" value="<?= $d['iddetail_pengadaan'] ?>">
                  <input type="hidden" name="idpengadaan" value="<?= $id ?>">
                  <button class="btn danger">Hapus</button>
                </form>
              <?php else: ?>
                <span style="color:var(--text-secondary)">Terkunci</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php else: ?>
      <div class="detail-empty">Belum ada detail untuk pengadaan ini.</div>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var select = document.getElementById('idbarang_select');
  var hargaInput = document.getElementById('harga_satuan');
  var jumlahInput = document.getElementById('jumlah_pesan');
  function updateHarga(){
    if(!select || !hargaInput) return;
    var opt = select.options[select.selectedIndex];
    var harga = opt ? opt.getAttribute('data-harga') : '';
    hargaInput.value = harga || '';
  }
  if(select){
    select.addEventListener('change', updateHarga);
    updateHarga();
  }
  if(jumlahInput){
    // Allow manual numeric typing only; strip non-digits but do not force a value while typing
    jumlahInput.addEventListener('input', function(){
      // remove any non-digit characters
      this.value = this.value.replace(/\D/g, '');
      // allow empty while typing; we'll validate on submit
    });
    // Validate on form submit to ensure a positive integer
    const formAdd = document.querySelector('form.inline');
    if(formAdd){
      formAdd.addEventListener('submit', function(e){
        const v = jumlahInput.value.trim();
        if(v === '' || isNaN(parseInt(v)) || parseInt(v) <= 0){
          e.preventDefault();
          alert('Jumlah harus berupa angka bulat positif (minimal 1).');
          jumlahInput.focus();
          return false;
        }
        return true;
      });
    }
  }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
