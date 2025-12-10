<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

$barang = $fetcher->barangAktif();
$vendors = $fetcher->vendorsAktif();
$message='';

// Workflow baru: 1) Tambah Header minimal (vendor+status) 2) Tambah detail ke header terpilih 3) Update total header

$activeId = isset($_GET['idpengadaan']) ? (int)$_GET['idpengadaan'] : 0;
// Flash message (PRG)
if(isset($_SESSION['flash_pengadaan'])){
  $message = $_SESSION['flash_pengadaan'];
  unset($_SESSION['flash_pengadaan']);
}

if($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
    if ($action === 'create_header') {
    $iduser = (int)$_SESSION['user_id'];
    $vendor_id = (int)$_POST['vendor_idvendor'];
    $status = 'A'; // auto aktif
    try {
      // Gunakan transaksi agar header tidak tersimpan sebagian jika terjadi error
      $db->beginTransaction();
      $db->execute('INSERT INTO pengadaan (user_iduser, status, vendor_idvendor, subtotal_nilai, ppn, total_nilai) VALUES (?,?,?,?,?,?)',
        [$iduser, $status, $vendor_id, 0, 0, 0]);
      $newId = (int)$db->getConnection()->lastInsertId();
      $db->commit();
      // Setelah membuat header, langsung buka halaman detail pengadaan
      header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan_detail.php?id=' . $newId);
      exit;
    } catch (Throwable $e) {
      try { $db->rollBack(); } catch (Throwable $_) {}
      $message = 'Gagal membuat header: ' . $e->getMessage();
    }
  } elseif ($action === 'add_detail') {
    $idpengadaan = (int)$_POST['idpengadaan'];
    // Periksa apakah pengadaan sudah final
    $hdrCheck = $db->fetch('SELECT status FROM pengadaan WHERE idpengadaan = ? LIMIT 1', [$idpengadaan]);
    if ($hdrCheck && ($hdrCheck['status'] ?? '') === 'F') {
      $message = 'Pengadaan sudah final. Tidak dapat menambah detail.';
    } else {
      $idbarang = (int)$_POST['idbarang'];
      $jumlah = (int)$_POST['jumlah_pesan'];
      $harga = (int)$_POST['harga_satuan'];
      $sub = $jumlah * $harga;
      try {
        // Mulai transaksi agar insert detail + update total bersifat atomik
        $db->beginTransaction();
        // Tambah detail via Stored Procedure utama
        // Asumsi utama signature: (idpengadaan, idbarang, jumlah, harga_satuan)
        try {
          $db->callProcedure('sp_InsertDetailPengadaan', [ $idpengadaan, $idbarang, $jumlah, $harga ]);
        } catch (Throwable $sig4Err) {
          // Coba varian dengan parameter subtotal bila SP mengharuskan
          try {
            $db->callProcedure('sp_InsertDetailPengadaan', [ $idpengadaan, $idbarang, $jumlah, $harga, $sub ]);
          } catch (Throwable $sig5Err) {
            // Fallback terakhir: direct SQL supaya proses user tidak terblokir
            $db->execute('INSERT INTO detail_pengadaan (idpengadaan, idbarang, jumlah, harga_satuan, sub_total) VALUES (?,?,?,?,?)',
              [$idpengadaan, $idbarang, $jumlah, $harga, $sub]);
          }
        }

        // Sinkronisasi total header melalui Stored Procedure baru.
        // Utama: CALL SP_UpdatePengadaanTotals(idpengadaan)
        try {
          $db->callProcedure('SP_UpdatePengadaanTotals', [ $idpengadaan ]);
        } catch (Throwable $spErr) {
          // Fallback jika SP belum tersedia: hitung manual agar tidak memblokir operasi.
          $sum = $db->fetch('SELECT SUM(sub_total) AS subtotal FROM detail_pengadaan WHERE idpengadaan = ?', [$idpengadaan]);
          $subtotal = (int)($sum['subtotal'] ?? 0);
          $ppn = (int)round($subtotal * 0.11);
          $total = $subtotal + $ppn;
          $db->execute('UPDATE pengadaan SET subtotal_nilai=?, ppn=?, total_nilai=? WHERE idpengadaan=?', [$subtotal,$ppn,$total,$idpengadaan]);
        }

        // Commit jika semua sukses
        $db->commit();
        // PRG: setelah simpan detail, tetap berada pada header aktif agar user bisa menambah item berikutnya
        $_SESSION['flash_pengadaan'] = 'Detail ditambahkan.';
        header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan.php?idpengadaan=' . $idpengadaan . '#formAddDetail');
        exit;
      } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable $_) {}
        $message = 'Gagal menambah detail: ' . $e->getMessage();
      }
    }
  } elseif ($action === 'update_status') {
    $idpengadaan = (int)$_POST['idpengadaan'];
    $toStatus = $_POST['to_status'] === 'P' ? 'P' : 'A';
    try {
      $db->execute('UPDATE pengadaan SET status=? WHERE idpengadaan=?', [$toStatus,$idpengadaan]);
      $message = 'Status pengadaan diperbarui.';
      $activeId = $idpengadaan;
    } catch (Throwable $e) {
      $message = 'Gagal update status: ' . $e->getMessage();
    }
  } elseif ($action === 'delete_pengadaan') {
    $idpengadaan = (int)($_POST['idpengadaan'] ?? 0);
    if ($idpengadaan <= 0) {
      $message = 'ID Pengadaan tidak valid.';
    } else {
      try {
        // Cek adanya relasi yang mencegah penghapusan
        $cntDetail = $db->fetch('SELECT COUNT(*) AS c FROM detail_pengadaan WHERE idpengadaan = ?', [$idpengadaan]);
        $cntPenerimaan = $db->fetch('SELECT COUNT(*) AS c FROM penerimaan WHERE idpengadaan = ?', [$idpengadaan]);
        $hasDetail = (int)($cntDetail['c'] ?? 0) > 0;
        $hasPenerimaan = (int)($cntPenerimaan['c'] ?? 0) > 0;
        if ($hasDetail || $hasPenerimaan) {
          $message = 'Tidak dapat menghapus: pengadaan memiliki detail atau penerimaan terkait. Hapus detail/penerimaan terlebih dahulu.';
        } else {
          // aman untuk menghapus header
          try {
            $db->beginTransaction();
            $db->execute('DELETE FROM pengadaan WHERE idpengadaan = ?', [$idpengadaan]);
            $db->commit();
            $_SESSION['flash_pengadaan'] = 'Pengadaan #' . $idpengadaan . ' berhasil dihapus.';
            header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan.php');
            exit;
          } catch (Throwable $eDel) {
            try { $db->rollBack(); } catch (Throwable $_) {}
            throw $eDel;
          }
        }
      } catch (Throwable $e) {
        $message = 'Gagal menghapus pengadaan: ' . $e->getMessage();
      }
    }
  }
}

// Ambil semua header lalu pisahkan header aktif (jika dipilih) untuk ditampilkan paling atas
$headersAll = $fetcher->pengadaanHeader();
$headers = $headersAll; // default fallback
if ($activeId) {
  $currentHeader = array_values(array_filter($headersAll, fn($h) => (int)$h['idpengadaan'] === $activeId));
  $otherHeaders = array_values(array_filter($headersAll, fn($h) => (int)$h['idpengadaan'] !== $activeId));
} else {
  $currentHeader = [];
  $otherHeaders = $headersAll;
}
$details = $db->fetchAll('SELECT * FROM v_detailpengadaanlengkap ORDER BY idpengadaan DESC, iddetail_pengadaan DESC');
$title='Transaksi Pengadaan';
ob_start();
?>
<style>
/* Page-specific pengadaan table styling */
.txn-wrap { display:flex; gap:20px; align-items:flex-start; }
.txn-left { flex: 1 1 auto; }
.table.pengadaan-table { width:100%; table-layout: fixed; border-collapse: collapse; }
.table.pengadaan-table th, .table.pengadaan-table td { padding:10px 12px; font-size:14px; vertical-align: middle; }
.table.pengadaan-table thead th { background: var(--primary-blue, #0d6efd); color:#fff; }
.table.pengadaan-table th.numeric, .table.pengadaan-table td.numeric { text-align: right; white-space:nowrap; }
.table.pengadaan-table td.idcol { width:60px; text-align: right; white-space:nowrap; }
.table.pengadaan-table td.vendor { white-space: normal; min-width:220px; max-width:420px; word-wrap:break-word; }
.table.pengadaan-table td.status { text-align: center; }
.table.pengadaan-table tr { border-bottom:1px solid #eee; }
.table.pengadaan-table td.actions-col { width:270px; text-align: right; white-space:nowrap; }
.table.pengadaan-table .actions { display:flex; gap:8px; justify-content:flex-end; align-items:center; flex-wrap:nowrap; }
.table.pengadaan-table .actions .btn { padding:8px 12px; font-size:13px; border-radius:6px; }
.table.pengadaan-table .status-badge { display:inline-block; width:30px; height:30px; line-height:30px; border-radius:50%; font-size:13px; }
@media (max-width:1100px){ .txn-wrap{ flex-direction:column; } .table.pengadaan-table td.vendor{ min-width:auto; } }
</style>
<?php
?>
<h1>Transaksi Pengadaan</h1>
<?php if($message): ?><div class="card" style="padding:16px; background:#D1ECF1; color:#0C5460; border:2px solid #BEE5EB; margin-bottom:20px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Tambah Pengadaan</h2>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="create_header">
    <label>Vendor<br>
      <select name="vendor_idvendor" required>
        <?php foreach($vendors as $v): ?>
          <option value="<?= $v['idvendor'] ?>"><?= htmlspecialchars($v['nama_vendor']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn">Tambah Pengadaan</button>
  </form>
</section>

<!-- Pada halaman utama hanya tampilkan tombol tambah dan daftar header. Detail ditangani di halaman terpisah. -->
<div class="txn-wrap">
  <div class="txn-left txn-panel">
    <h3>Header Pengadaan</h3>
    <div style="margin-top:12px;">
      <table class="table pengadaan-table">
        <colgroup>
          <col style="width:60px">
          <col style="width:180px">
          <col style="width:45%">
          <col style="width:80px">
          <col style="width:110px">
          <col style="width:200px">
        </colgroup>
        <thead>
          <tr>
            <th class="idcol">ID</th>
            <th class="tanggal">TANGGAL</th>
            <th class="vendor">VENDOR</th>
            <th class="status">STATUS</th>
            <th class="numeric">TOTAL</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach(array_merge($currentHeader, $otherHeaders) as $h): ?>
          <tr style="<?= (isset($h['idpengadaan']) && $h['idpengadaan']==$activeId) ? 'background:#E9F5FF;border-left:4px solid var(--primary-blue);' : '' ?>">
            <td class="idcol"><?= $h['idpengadaan'] ?></td>
            <td class="tanggal"><?= $h['tanggal_pengadaan'] ?></td>
            <td class="vendor"><?= htmlspecialchars($h['nama_vendor']) ?></td>
            <td class="status"><span class="status-badge status-<?= htmlspecialchars($h['status'] ?? '0') ?>"><?= htmlspecialchars($h['status'] ?? '-') ?></span></td>
            <td class="numeric"><?= isset($h['total_nilai']) ? number_format((float)$h['total_nilai']) : '0' ?></td>
            <td class="actions-col">
              <div class="actions">
                <a class="btn" href="?idpengadaan=<?= $h['idpengadaan'] ?>" onclick="window.location='pengadaan_detail.php?id='+<?= $h['idpengadaan'] ?>; return false;">Lihat</a>
                <form method="post" style="display:inline-block">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="idpengadaan" value="<?= $h['idpengadaan'] ?>">
                  <select name="to_status">
                    <option value="A" <?= ($h['status']=='A')?'selected':'' ?>>A</option>
                    <option value="P" <?= ($h['status']=='P')?'selected':'' ?>>P</option>
                  </select>
                  <button class="btn secondary">Ubah</button>
                </form>
                <form method="post" onsubmit="return confirm('Apakah Anda yakin ingin menghapus Pengadaan #<?= $h['idpengadaan'] ?>?');" style="display:inline-block;margin-left:6px">
                  <input type="hidden" name="action" value="delete_pengadaan">
                  <input type="hidden" name="idpengadaan" value="<?= $h['idpengadaan'] ?>">
                  <button class="btn danger">Hapus</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>

  <!-- Ringkasan dipindahkan ke halaman detail; di halaman utama hanya daftar header ditampilkan. -->
</div>
<script>
function calcSubTotal(){
  const form=document.getElementById('formAddDetail');
  if(!form) return;
  const j=parseInt(form.jumlah_pesan?.value||0); const h=parseInt(form.harga_satuan?.value||0);
  const sub=j*h; if(form.sub_total_preview) form.sub_total_preview.value=sub;
}
function lockSubmit(form){
  const btn = document.getElementById('btnSubmitDetail');
  if(btn){ btn.disabled = true; btn.textContent = 'Menyimpan...'; }
  return true; // lanjutkan submit
}
// Pastikan form detail kosong saat halaman dimuat kembali untuk input berikutnya
document.addEventListener('DOMContentLoaded', function(){
  const form=document.getElementById('formAddDetail');
  if(form){ form.reset(); if(form.sub_total_preview) form.sub_total_preview.value=''; }
});

// Auto-fill harga_satuan dari harga barang saat barang dipilih
document.addEventListener('DOMContentLoaded', function(){
  const form = document.getElementById('formAddDetail');
  if(!form) return;
  const barangSelect = form.querySelector('select[name="idbarang"]');
  const hargaInput = form.querySelector('input[name="harga_satuan"]');
  function applyHarga(){
    const opt = barangSelect && barangSelect.selectedOptions ? barangSelect.selectedOptions[0] : null;
    const h = opt ? parseInt(opt.dataset.harga || '0') : 0;
    if(hargaInput){ hargaInput.value = h || ''; }
    calcSubTotal();
  }
  if(barangSelect){
    barangSelect.addEventListener('change', applyHarga);
    // Set harga awal jika sudah ada pilihan default
    if(barangSelect.value){ applyHarga(); }
  }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
