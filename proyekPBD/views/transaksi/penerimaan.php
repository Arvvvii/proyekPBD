<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

$message = '';

// Handle create header action (Tambah Penerimaan) and simple actions (status/update/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create_header') {
    $idpengadaan = (int)($_POST['idpengadaan'] ?? 0);
    $iduser = (int)($_SESSION['user_id'] ?? 0);
    if ($idpengadaan <= 0) {
      $message = 'Pengadaan tidak valid.';
    } else {
      // Cek apakah pengadaan masih memiliki outstanding (sisa qty) sebelum membuat penerimaan
      $outRow = $db->fetch('SELECT (SELECT COALESCE(SUM(jumlah),0) FROM detail_pengadaan WHERE idpengadaan = ?) - COALESCE((SELECT SUM(dtp.jumlah_terima) FROM detail_penerimaan dtp JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan WHERE prm.idpengadaan = ?),0) AS outstanding', [$idpengadaan, $idpengadaan]);
      $outstanding = (int)($outRow['outstanding'] ?? 0);
      if ($outstanding <= 0) {
        $message = 'Pengadaan ini sudah diterima semua. Tidak dapat membuat penerimaan baru.';
      } else {
      try {
        try {
          $db->callProcedure('SP_CreatePenerimaanHeader', [ $idpengadaan, $iduser, 'A' ]);
          $row = $db->fetch('SELECT LAST_INSERT_ID() AS idpenerimaan');
          $newId = (int)($row['idpenerimaan'] ?? 0);
        } catch (Throwable $_sp) {
          $db->beginTransaction();
          $db->execute('INSERT INTO penerimaan (idpengadaan, iduser, status) VALUES (?,?,?)', [$idpengadaan, $iduser, 'A']);
          $row = $db->fetch('SELECT LAST_INSERT_ID() AS idpenerimaan');
          $newId = (int)($row['idpenerimaan'] ?? 0);
          $db->commit();
        }
        if ($newId > 0) {
          header('Location: ' . BASE_PATH . '/views/transaksi/penerimaan_add.php?idpenerimaan=' . urlencode($newId));
          exit;
        } else {
          $message = 'Gagal membuat header penerimaan.';
        }
      } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable $_) {}
        $message = 'Gagal membuat header penerimaan: ' . $e->getMessage();
      }
      }
    }
  } elseif ($action === 'update_status') {
    $idp = (int)($_POST['idpenerimaan'] ?? 0);
    $toStatus = $_POST['to_status'] ?? 'A';
    try {
      $db->execute('UPDATE penerimaan SET status=? WHERE idpenerimaan=?', [$toStatus, $idp]);
      $message = 'Status penerimaan diperbarui.';
    } catch (Throwable $e) { $message = 'Gagal update status: ' . $e->getMessage(); }
  } elseif ($action === 'delete_penerimaan') {
    $idp = (int)($_POST['idpenerimaan'] ?? 0);
    if ($idp <= 0) { $message = 'ID Penerimaan tidak valid.'; }
    else {
      try {
        $cnt = $db->fetch('SELECT COUNT(*) AS c FROM detail_penerimaan WHERE idpenerimaan = ?', [$idp]);
        if ((int)($cnt['c'] ?? 0) > 0) {
          $message = 'Tidak dapat menghapus: penerimaan memiliki detail.';
        } else {
          $db->beginTransaction();
          $db->execute('DELETE FROM penerimaan WHERE idpenerimaan = ?', [$idp]);
          $db->commit();
          $message = 'Penerimaan #' . $idp . ' berhasil dihapus.';
        }
      } catch (Throwable $e) { try { $db->rollBack(); } catch (Throwable $_) {} $message = 'Gagal menghapus: ' . $e->getMessage(); }
    }
  }
}

// Tidak lagi memuat semua barang di awal; barang akan dimuat dinamis berdasarkan pengadaan (dependency logic)
// $barang = $fetcher->barangAktif(); // dihapus untuk mencegah penggunaan variabel yang tidak relevan
$pengadaanHeader = $fetcher->pengadaanHeader();
// Hitung outstanding per pengadaan agar pengadaan yang sudah penuh dapat dinonaktifkan
$pengadaanOutstanding = [];
foreach ($pengadaanHeader as $p) {
  $pid = (int)($p['idpengadaan'] ?? 0);
  if ($pid <= 0) continue;
  $row = $db->fetch('SELECT (SELECT COALESCE(SUM(jumlah),0) FROM detail_pengadaan WHERE idpengadaan = ?) - COALESCE((SELECT SUM(dtp.jumlah_terima) FROM detail_penerimaan dtp JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan WHERE prm.idpengadaan = ?),0) AS outstanding', [$pid,$pid]);
  $pengadaanOutstanding[$pid] = (int)($row['outstanding'] ?? 0);
}
$pengadaanFilterId = isset($_GET['idpengadaan']) ? (int)$_GET['idpengadaan'] : 0;

// Periksa apakah pengadaan terpilih memiliki detail pada tabel detail_pengadaan
$hasDetailPengadaan = 0;
if ($pengadaanFilterId > 0) {
  $cntRow = $db->fetch('SELECT COUNT(*) AS c FROM detail_pengadaan WHERE idpengadaan = ?', [ $pengadaanFilterId ]);
  $hasDetailPengadaan = (int)($cntRow['c'] ?? 0);
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

// Ambil detail penerimaan dari view v_detailpenerimaanlengkap
$details = $db->fetchAll('SELECT * FROM v_detailpenerimaanlengkap ORDER BY idpenerimaan DESC, iddetail_penerimaan') ?? [];

// Prepare status and totals for each penerimaan header so table can match pengadaan layout
$statusRows = $db->fetchAll('SELECT idpenerimaan, status FROM penerimaan');
$statusMap = [];
foreach($statusRows as $sr) { $statusMap[$sr['idpenerimaan']] = $sr['status'] ?? ''; }
$totalRows = $db->fetchAll('SELECT idpenerimaan, COALESCE(SUM(sub_total_terima),0) AS total_terima FROM detail_penerimaan GROUP BY idpenerimaan');
$totalMap = [];
foreach($totalRows as $tr) { $totalMap[$tr['idpenerimaan']] = (int)$tr['total_terima']; }

// Filter detail penerimaan untuk pengadaan terpilih (dipakai saat menampilkan tabel detail)
$detailPenerimaanForPengadaan = [];
if ($pengadaanFilterId > 0) {
  $detailPenerimaanForPengadaan = array_values(array_filter($details, fn($d) => isset($d['idpengadaan']) && (int)$d['idpengadaan'] === (int)$pengadaanFilterId));
}

$title='Transaksi Penerimaan';
ob_start();
?>
<style>
/* Penerimaan table styling to match pengadaan layout */
.txn-wrap { display:flex; gap:20px; align-items:flex-start; }
.txn-left { flex: 1 1 100%; }
.txn-right { flex: 1 1 520px; }
.table.penerimaan-table { width:100%; table-layout: auto; border-collapse: collapse; box-sizing: border-box; }
.table.penerimaan-table col { width: auto !important; }
.table.penerimaan-table th, .table.penerimaan-table td { padding:10px 12px; font-size:14px; vertical-align: middle; box-sizing: border-box; }
.table.penerimaan-table thead th { background: var(--primary-blue, #0d6efd); color:#fff; }
.table.penerimaan-table th.numeric, .table.penerimaan-table td.numeric { text-align: right; white-space:nowrap; }
.table.penerimaan-table td.idcol { width:60px; text-align: right; white-space:nowrap; }
.table.penerimaan-table td.vendor, .table.penerimaan-table td.barang { white-space: normal; word-wrap:break-word; }
.table.penerimaan-table tr { border-bottom:1px solid #eee; }
.detail-empty { color:#666; padding:12px; }
@media (max-width:1100px){ .txn-wrap{ flex-direction:column; } }

/* Notifikasi (alert) */
.alert { padding:12px 16px; border-radius:6px; margin:12px 0; font-weight:500; box-shadow:0 2px 6px rgba(0,0,0,0.06); }
.alert.success { background:#e6f4ea; color:#1b5e20; border:1px solid #b7e1c7; }
.alert.error { background:#fdecea; color:#7a1a14; border:1px solid #f5c2c0; }
.alert.hide { display:none !important; }
/* Actions layout: align buttons horizontally like pengadaan */
.table.penerimaan-table td.actions-col, .table.pengadaan-table td.actions-col { width:270px; text-align:right; white-space:nowrap; }
.table.penerimaan-table .actions, .table.pengadaan-table .actions { display:flex; gap:8px; justify-content:flex-end; align-items:center; flex-wrap:nowrap; position:relative; z-index:1200; }
.table.penerimaan-table .actions .btn, .table.pengadaan-table .actions .btn { padding:8px 12px; font-size:13px; border-radius:6px; position:relative; z-index:1210; }
.table.penerimaan-table tr, .table.pengadaan-table tr { overflow: visible; }
</style>

<h1>Transaksi Penerimaan</h1>
<?php if($message):
  $isError = (stripos($message,'gagal') !== false || stripos($message,'error') !== false);
?>
  <div id="penerimaanMessage" class="alert <?= $isError ? 'error' : 'success' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<section>
  <h2>Tambah Penerimaan</h2>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="create_header">
    <label>Pengadaan<br>
      <select name="idpengadaan" required>
        <?php foreach($pengadaanHeader as $p): 
          $pidOpt = (int)($p['idpengadaan'] ?? 0);
          $out = $pengadaanOutstanding[$pidOpt] ?? 0;
        ?>
          <option value="<?= $pidOpt ?>" <?= $out <= 0 ? 'disabled' : '' ?>>#<?= $pidOpt ?> - <?= htmlspecialchars($p['nama_vendor'] ?? '-') ?><?= $out <= 0 ? ' (selesai)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <div style="font-size:12px;color:#666;margin-top:6px">Pengadaan yang sudah semua diterima ditandai sebagai "selesai" dan tidak bisa dipilih.</div>
    </label>
    <button class="btn">Tambah Penerimaan</button>
  </form>
</section>

<div class="txn-panel">
  <h3>Header Penerimaan</h3>
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
        <?php foreach($headers as $h): ?>
          <?php $pid = $h['idpenerimaan'] ?? ''; $pp = $h['idpengadaan'] ?? ''; $pname = $h['nama_vendor'] ?? '-'; $stat = $statusMap[$pid] ?? '-'; $total = $totalMap[$pid] ?? 0; ?>
          <tr>
            <td class="idcol"><?= $pid ? $pid : '' ?></td>
            <td class="tanggal"><?= isset($h['tanggal_terima']) ? $h['tanggal_terima'] : '' ?></td>
            <td class="vendor"><?= htmlspecialchars($pname) ?></td>
            <td class="status"><span class="status-badge status-<?= htmlspecialchars($stat ?: '0') ?>"><?= htmlspecialchars($stat ?: '-') ?></span></td>
            <td class="numeric"><?= number_format($total) ?></td>
            <td class="actions-col">
              <div class="actions">
                <?php $pp_out = $pengadaanOutstanding[$pp] ?? null; ?>
                <?php
                // Render Lihat as a consistent anchor for all rows. Keep a helpful title when pengadaan
                // is fully received but still allow navigation to the detail page.
                $lihatHref = BASE_PATH . '/views/transaksi/penerimaan_add.php?idpenerimaan=' . $pid;
                $lihatTitle = ($pp_out !== null && $pp_out <= 0) ? 'Pengadaan selesai: lihat detail (read-only)' : 'Lihat detail penerimaan';
                ?>
                <a class="btn lihat-btn" href="<?= $lihatHref ?>" title="<?= htmlspecialchars($lihatTitle) ?>" onclick="try{ if(event && (event.ctrlKey||event.metaKey||event.shiftKey)) return true; window.location.href=this.href; }catch(e){}; return false;">Lihat</a>
                <form method="post" style="display:inline-block">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="idpenerimaan" value="<?= $pid ?>">
                  <select name="to_status">
                    <option value="A" <?= ($stat=='A')?'selected':'' ?>>A</option>
                    <option value="P" <?= ($stat=='P')?'selected':'' ?>>P</option>
                  </select>
                  <button class="btn secondary">Ubah</button>
                </form>
                <form method="post" onsubmit="return confirm('Apakah Anda yakin ingin menghapus Penerimaan #<?= $pid ?>?');" style="display:inline-block;margin-left:6px">
                  <input type="hidden" name="action" value="delete_penerimaan">
                  <input type="hidden" name="idpenerimaan" value="<?= $pid ?>">
                  <button class="btn danger">Hapus</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
</div>
<script>
// Auto-hide notification after a delay and allow click-to-dismiss
(function(){
  const msg = document.getElementById('penerimaanMessage');
  if(!msg) return;
  msg.addEventListener('click', ()=> msg.classList.add('hide'));
  setTimeout(()=>{ try{ msg.classList.add('hide'); }catch(e){} }, 6000);
})();

// Fallback: jika ada handler lain yang mencegah navigasi, pastikan link "Lihat" tetap membuka halaman detail
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.actions a.btn').forEach(function(a){
    // attach a click handler that forces navigation to href
    a.addEventListener('click', function(ev){
      try{
        if(!this.href) return;
        // allow normal behavior on modifier keys (ctrl/meta to open in new tab)
        if (ev.ctrlKey || ev.metaKey || ev.shiftKey) return;
        ev.preventDefault();
        window.location.href = this.href;
      }catch(e){ /* ignore */ }
    });
  });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';