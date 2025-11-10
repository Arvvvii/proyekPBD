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
      $db->execute('INSERT INTO pengadaan (user_iduser, status, vendor_idvendor, subtotal_nilai, ppn, total_nilai) VALUES (?,?,?,?,?,?)',
        [$iduser, $status, $vendor_id, 0, 0, 0]);
      $newId = (int)$db->getConnection()->lastInsertId();
      header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan.php?idpengadaan=' . $newId);
      exit;
    } catch (Throwable $e) {
      $message = 'Gagal membuat header: ' . $e->getMessage();
    }
  } elseif ($action === 'add_detail') {
    $idpengadaan = (int)$_POST['idpengadaan'];
    $idbarang = (int)$_POST['idbarang'];
    $jumlah = (int)$_POST['jumlah_pesan'];
    $harga = (int)$_POST['harga_satuan'];
    $sub = $jumlah * $harga;
    try {
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
  // PRG: setelah simpan detail, tetap berada pada header aktif agar user bisa menambah item berikutnya
  $_SESSION['flash_pengadaan'] = 'Detail ditambahkan.';
  header('Location: ' . BASE_PATH . '/views/transaksi/pengadaan.php?idpengadaan=' . $idpengadaan . '#formAddDetail');
      exit;
    } catch (Throwable $e) {
      $message = 'Gagal menambah detail: ' . $e->getMessage();
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
<h1>Transaksi Pengadaan</h1>
<?php if($message): ?><div class="card" style="background:#0b1220;padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
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

<?php if ($activeId): ?>
<section>
  <h2>Tambah Detail untuk #<?= $activeId ?></h2>
  <form id="formAddDetail" method="post" class="inline" oninput="calcSubTotal()" onsubmit="return lockSubmit(this)" autocomplete="off">
    <input type="hidden" name="action" value="add_detail">
    <input type="hidden" name="idpengadaan" value="<?= $activeId ?>">
    <label>Barang<br>
      <select name="idbarang" required>
        <?php foreach($barang as $b): ?>
          <option value="<?= $b['idbarang'] ?>" data-harga="<?= (int)$b['harga'] ?>"><?= htmlspecialchars($b['nama_barang']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Jumlah<br><input type="number" name="jumlah_pesan" min="1" required></label>
    <label>Harga Satuan<br><input type="number" name="harga_satuan" id="harga_satuan" min="0" required></label>
    <label>Sub Total (auto)<br><input type="number" name="sub_total_preview" readonly></label>
    <button class="btn" type="submit" id="btnSubmitDetail">Simpan Detail</button>
  </form>
</section>
<?php endif; ?>
<section>
  <h2>Header Pengadaan (v_pengadaanheader)</h2>
  <?php if($activeId && $currentHeader): ?>
    <h3 style="margin-top:10px">Sedang Dikerjakan (ID #<?= $activeId ?>)</h3>
    <table class="table"><thead><tr><th>ID</th><th>Tanggal</th><th>Vendor</th><th>Dibuat Oleh</th><th>Status</th><th>Total Nilai</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach($currentHeader as $h): ?>
        <tr style="background:#182235">
          <td><?= $h['idpengadaan'] ?></td>
          <td><?= $h['tanggal_pengadaan'] ?></td>
          <td><?= htmlspecialchars($h['nama_vendor']) ?></td>
          <td><?= htmlspecialchars($h['dibuat_oleh']) ?></td>
          <td><?= htmlspecialchars($h['status']) ?></td>
          <td><?= isset($h['total_nilai']) ? number_format((float)$h['total_nilai']) : '0' ?></td>
          <td>
            <a class="btn secondary" href="?idpengadaan=<?= $h['idpengadaan'] ?>#formAddDetail" style="margin-right:6px">Tambah Detail</a>
            <form method="post" class="inline" style="display:inline-flex;gap:6px">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="idpengadaan" value="<?= $h['idpengadaan'] ?>">
              <select name="to_status">
                <option value="A" <?= $h['status']=='A'?'selected':'' ?>>Aktif</option>
                <option value="P" <?= $h['status']=='P'?'selected':'' ?>>Pending</option>
              </select>
              <button class="btn secondary">Ubah</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <h3 style="margin-top:24px">Riwayat / Lainnya</h3>
  <?php endif; ?>
  <table class="table"><thead><tr><th>ID</th><th>Tanggal</th><th>Vendor</th><th>Dibuat Oleh</th><th>Status</th><th>Total Nilai</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach($otherHeaders as $h): ?>
      <tr>
        <td><?= $h['idpengadaan'] ?></td>
        <td><?= $h['tanggal_pengadaan'] ?></td>
        <td><?= htmlspecialchars($h['nama_vendor']) ?></td>
        <td><?= htmlspecialchars($h['dibuat_oleh']) ?></td>
        <td><?= htmlspecialchars($h['status']) ?></td>
        <td><?= isset($h['total_nilai']) ? number_format((float)$h['total_nilai']) : '0' ?></td>
        <td>
          <a class="btn secondary" href="?idpengadaan=<?= $h['idpengadaan'] ?>#formAddDetail" style="margin-right:6px">Tambah Detail</a>
          <form method="post" class="inline" style="display:inline-flex;gap:6px">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="idpengadaan" value="<?= $h['idpengadaan'] ?>">
            <select name="to_status">
              <option value="A" <?= $h['status']=='A'?'selected':'' ?>>Aktif</option>
              <option value="P" <?= $h['status']=='P'?'selected':'' ?>>Pending</option>
            </select>
            <button class="btn secondary">Ubah</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
</section>
<section>
  <h2>Detail Pengadaan (v_detailpengadaanlengkap)</h2>
  <table class="table"><thead><tr><th>ID Detail</th><th>ID Pengadaan</th><th>Barang</th><th>Satuan</th><th>Jumlah</th><th>Harga</th><th>Subtotal</th></tr></thead><tbody>
    <?php foreach($details as $d): ?>
      <tr>
        <td><?= $d['iddetail_pengadaan'] ?></td>
        <td><?= $d['idpengadaan'] ?></td>
        <td><?= htmlspecialchars($d['nama_barang']) ?></td>
        <td><?= htmlspecialchars($d['nama_satuan']) ?></td>
        <td><?= $d['jumlah_pesan'] ?></td>
        <td><?= isset($d['harga_satuan']) ? number_format((float)$d['harga_satuan']) : '0' ?></td>
        <td><?= isset($d['sub_total']) ? number_format((float)$d['sub_total']) : '0' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
</section>
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
