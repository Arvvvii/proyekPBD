<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

// Tidak lagi memuat semua barang di awal; barang akan dimuat dinamis berdasarkan pengadaan (dependency logic)
// $barang = $fetcher->barangAktif(); // dihapus untuk mencegah penggunaan variabel yang tidak relevan
$pengadaanHeader = $fetcher->pengadaanHeader();
$pengadaanFilterId = isset($_GET['idpengadaan']) ? (int)$_GET['idpengadaan'] : 0;
$message = '';

if ($_SERVER['REQUEST_METHOD']=== 'POST') {
  // Kumpulkan parameter dari form dan session
  $idpengadaan = (int)($_POST['idpengadaan'] ?? 0);
  $status = 'A'; // auto aktif
  $iduser = (int)$_SESSION['user_id'];
  $idbarang = isset($_POST['idbarang']) ? (int)$_POST['idbarang'] : 0;
  $jumlah_terima = (int)($_POST['jumlah_terima'] ?? 0);
  $harga_satuan_terima = (int)($_POST['harga_satuan_terima'] ?? 0);

  // Validasi dasar
  if($idpengadaan<=0 || $idbarang<=0){
    $message = 'Gagal: Pengadaan atau Barang tidak valid.';
  } else {
    // Pastikan barang memang ada di detail pengadaan
    $orderedRow = $db->fetch('SELECT jumlah FROM detail_pengadaan WHERE idpengadaan=? AND idbarang=? LIMIT 1',[ $idpengadaan, $idbarang ]);
    if(!$orderedRow){
      $message = 'Gagal: Barang tidak terdaftar pada pengadaan ini.';
    } else {
      $orderedQty = (int)$orderedRow['jumlah'];
      // Hitung sudah diterima sebelumnya
      $receivedRow = $db->fetch('SELECT COALESCE(SUM(dtp.jumlah_terima),0) AS received_qty
                                 FROM detail_penerimaan dtp
                                 JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan
                                 WHERE prm.idpengadaan=? AND dtp.barang_idbarang=?',[ $idpengadaan, $idbarang ]);
      $receivedQty = (int)($receivedRow['received_qty'] ?? 0);
      $outstanding = $orderedQty - $receivedQty;
      if($outstanding <= 0){
        $message = 'Semua qty untuk barang ini sudah diterima.';
      } elseif($jumlah_terima <=0){
        $message = 'Jumlah terima harus > 0.';
      } elseif($jumlah_terima > $outstanding){
        $message = 'Gagal: Qty terima (' . $jumlah_terima . ') melebihi sisa (' . $outstanding . ').';
      } else {
        try {
          // Gunakan prosedur tunggal sp_insert_penerimaan_lengkap sesuai schema dump
          $db->callProcedure('sp_insert_penerimaan_lengkap',[ $idpengadaan, $iduser, $status, $idbarang, $jumlah_terima, $harga_satuan_terima ]);

          // Langkah kritis baru: segera update harga jual barang berdasarkan harga beli terbaru
          // CALL SP_UpdateHargaJualBarang(p_idbarang, p_harga_beli_terbaru)
          try {
            $db->callProcedure('SP_UpdateHargaJualBarang', [ $idbarang, $harga_satuan_terima ]);
            // Verifikasi perubahan harga benar-benar terjadi
            $beforeAfter = $db->fetch('SELECT harga FROM barang WHERE idbarang = ? LIMIT 1', [$idbarang]);
            $currentHarga = isset($beforeAfter['harga']) ? (int)$beforeAfter['harga'] : null;
            if($currentHarga !== null && $currentHarga !== (int)$harga_satuan_terima) {
              // Jika SP tidak mengubah (atau logika lain menambah PPN), paksa override tanpa PPN
              $db->execute('UPDATE barang SET harga = ? WHERE idbarang = ?', [ (int)$harga_satuan_terima, $idbarang ]);
              $currentHarga = (int)$harga_satuan_terima;
              $updateHargaMsg = ' Harga jual barang diupdate ulang ke ' . $currentHarga;
            } else {
              $updateHargaMsg = ' Harga jual barang telah diperbarui.';
            }
          } catch (Throwable $eHarga) {
            // Jangan gagalkan transaksi utama; cukup catat pesan
            // Fallback manual langsung UPDATE jika SP gagal
            try {
              $db->execute('UPDATE barang SET harga = ? WHERE idbarang = ?', [ (int)$harga_satuan_terima, $idbarang ]);
              $updateHargaMsg = ' Harga jual diupdate fallback (SP gagal: ' . $eHarga->getMessage() . ').';
            } catch (Throwable $e2) {
              $updateHargaMsg = ' (Catatan: update harga jual gagal total: ' . $eHarga->getMessage() . ' / ' . $e2->getMessage() . ')';
            }
          }

          // Ambil idpenerimaan terakhir (header yang baru dibuat oleh SP)
          $rowId = $db->fetch('SELECT LAST_INSERT_ID() AS idpenerimaan');
          $idpenerimaanBaru = (int)($rowId['idpenerimaan'] ?? 0);
          $message = 'Penerimaan berhasil (ID #' . $idpenerimaanBaru . '). Diterima ' . $jumlah_terima . ' dari sisa ' . $outstanding . '.' . ($updateHargaMsg ?? '');
        } catch (Throwable $e) {
          $message = 'Gagal simpan penerimaan: ' . $e->getMessage();
        }
      }
    }
  }
}

if($pengadaanFilterId>0){
  // Tampilkan penerimaan hanya untuk pengadaan tertentu agar fokus
  $currentHeaders = $db->fetchAll('SELECT * FROM v_penerimaanheader WHERE idpengadaan = ? ORDER BY idpenerimaan DESC',[ $pengadaanFilterId ]);
  $otherHeaders = $db->fetchAll('SELECT * FROM v_penerimaanheader WHERE idpengadaan <> ? ORDER BY idpenerimaan DESC',[ $pengadaanFilterId ]);
  $headers = $currentHeaders; // tampilkan yang terkait pengadaan terpilih di atas
} else {
  $headers = $fetcher->penerimaanHeader();
  $otherHeaders = [];
}

$title='Transaksi Penerimaan';
ob_start();
?>
<h1>Penerimaan Barang</h1>
<?php if($message): ?><div class="card" style="background:#0b1220; padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Input Penerimaan <?= $pengadaanFilterId? '(Pengadaan #'.$pengadaanFilterId.')':'' ?></h2>
  <form method="post" class="inline" id="formPenerimaan" onsubmit="return lockSubmitPenerimaan(this)">
    <label>Pengadaan<br>
      <select name="idpengadaan" id="idpengadaan" required>
        <?php foreach($pengadaanHeader as $p): ?>
          <option value="<?= $p['idpengadaan'] ?>" <?= $pengadaanFilterId==$p['idpengadaan']?'selected':'' ?>>#<?= $p['idpengadaan'] ?> - <?= htmlspecialchars($p['nama_vendor']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <!-- Status di-set otomatis ke Aktif -->
        <label>Barang (Outstanding)<br>
          <select name="idbarang" id="barang_id" required disabled>
            <option value="">-- pilih pengadaan dulu --</option>
          </select>
        </label>
        <div id="infoOutstanding" style="font-size:.85em;color:#ccc;margin-top:4px"></div>
  <label>Jumlah Terima<br><input type="number" name="jumlah_terima" id="jumlah_terima" min="1" required></label>
    <label>Harga Satuan Terima<br><input type="number" name="harga_satuan_terima" min="0" required></label>
    <button class="btn">Simpan</button>
  </form>
</section>
<section>
  <h2>Header Penerimaan (v_penerimaanheader)</h2>
  <table class="table">
    <thead><tr><th>ID</th><th>Tanggal</th><th>Pengadaan</th><th>Diterima Oleh</th><th>Vendor</th></tr></thead>
    <tbody>
      <?php foreach($headers as $h): ?>
        <tr>
          <td><?= $h['idpenerimaan'] ?></td>
          <td><?= $h['tanggal_terima'] ?></td>
          <td><?= $h['idpengadaan'] ?></td>
          <td><?= htmlspecialchars($h['diterima_oleh']) ?></td>
          <td><?= htmlspecialchars($h['nama_vendor']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!empty($otherHeaders)): ?>
        <tr><td colspan="5" style="background:#111b2b">Riwayat / Lainnya</td></tr>
        <?php foreach($otherHeaders as $h): ?>
          <tr>
            <td><?= $h['idpenerimaan'] ?></td>
            <td><?= $h['tanggal_terima'] ?></td>
            <td><?= $h['idpengadaan'] ?></td>
            <td><?= htmlspecialchars($h['diterima_oleh']) ?></td>
            <td><?= htmlspecialchars($h['nama_vendor']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</section>
<section>
  <h2>Detail Penerimaan (v_detailpenerimaanlengkap)</h2>
  <table class="table">
    <thead><tr><th>ID Detail</th><th>ID Penerimaan</th><th>Barang</th><th>Jumlah</th><th>Harga Satuan</th><th>Subtotal</th></tr></thead>
    <tbody>
      <?php foreach($details as $d): ?>
        <tr>
          <td><?= $d['iddetail_penerimaan'] ?></td>
          <td><?= $d['idpenerimaan'] ?></td>
          <td><?= htmlspecialchars($d['nama_barang']) ?></td>
          <td><?= $d['jumlah_terima'] ?></td>
          <td><?= number_format($d['harga_satuan_terima']) ?></td>
          <td><?= number_format($d['sub_total_terima']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<script>
const pengadaanSelect = document.getElementById('idpengadaan');
const barangSelect = document.getElementById('barang_id');
const pengadaanFilterId = parseInt('<?= $pengadaanFilterId ?>');
async function loadBarangUntukPengadaan(id){
  if(!barangSelect) return;
  barangSelect.innerHTML = '<option value="">memuat...</option>'; barangSelect.disabled = true;
  document.getElementById('infoOutstanding').textContent='';
  try {
    const resp = await fetch('get_po_details.php?idpengadaan=' + encodeURIComponent(id));
    const data = await resp.json();
    barangSelect.innerHTML = '';
    if(Array.isArray(data) && data.length){
      barangSelect.appendChild(new Option('-- pilih barang --',''));
      data.forEach(row => {
        const label = `${row.nama_barang} (pesan: ${row.ordered_qty}, terima: ${row.received_qty}, sisa: ${row.outstanding_qty})`;
        const opt = new Option(label, row.idbarang);
        opt.dataset.outstanding = row.outstanding_qty;
        barangSelect.appendChild(opt);
      });
      barangSelect.disabled = false;
      document.getElementById('infoOutstanding').textContent = 'Hanya barang dengan sisa > 0 yang ditampilkan.';
    } else {
      barangSelect.appendChild(new Option('Semua barang sudah diterima atau tidak ada detail',''));
      barangSelect.disabled = true;
    }
  } catch(e){
    barangSelect.innerHTML = '<option value="">gagal memuat</option>';
    barangSelect.disabled = true;
  }
}
if(barangSelect){
  barangSelect.addEventListener('change', e => {
    const sel = e.target.selectedOptions[0];
    if(sel && sel.dataset.outstanding){
      const sisa = parseInt(sel.dataset.outstanding||0);
      document.getElementById('infoOutstanding').textContent = 'Sisa qty: ' + sisa;
      const qtyInput = document.getElementById('jumlah_terima');
      if(qtyInput){
        qtyInput.max = sisa;
        if(parseInt(qtyInput.value||0) > sisa){ qtyInput.value = sisa; }
      }
    }
  });
}
if(pengadaanSelect){
  pengadaanSelect.addEventListener('change', e => {
    const val = e.target.value; if(val){ loadBarangUntukPengadaan(val); }
  });
  // Pre-selection dari URL (GET idpengadaan) lebih prioritas
  if(pengadaanFilterId>0){
    loadBarangUntukPengadaan(pengadaanFilterId);
  } else if(pengadaanSelect.value){
    loadBarangUntukPengadaan(pengadaanSelect.value);
  }
}
function lockSubmitPenerimaan(form){
  const btn = form.querySelector('button[type="submit"]');
  if(btn){ btn.disabled = true; btn.textContent = 'Menyimpan...'; }
  return true;
}
// Pesan edukasi jika tidak ada barang di pengadaan terpilih
function showAlertIfEmpty(){
  if(barangSelect && barangSelect.disabled && pengadaanSelect.value){
    const info = document.createElement('div');
    info.className='card';
    info.style.marginTop='8px';
    info.textContent='Pengadaan ini belum memiliki detail barang. Tambahkan detail di halaman Pengadaan terlebih dahulu.';
    const form = document.getElementById('formPenerimaan');
    form.parentNode.insertBefore(info, form.nextSibling);
  }
}
setTimeout(showAlertIfEmpty,1200);
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';