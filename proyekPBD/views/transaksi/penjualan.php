<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

$message = '';

// Handle header-only actions: create header, update status, delete header (only if no details exist)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create_header') {
    $iduser = (int)$_SESSION['user_id'];
    $idmargin = (int)($_POST['idmargin_penjualan'] ?? 0);
    try {
      $db->beginTransaction();
      $db->execute('INSERT INTO penjualan (iduser, idmargin_penjualan) VALUES (?,?)', [$iduser, $idmargin]);
      $row = $db->fetch('SELECT LAST_INSERT_ID() AS idpenjualan');
      $newId = (int)($row['idpenjualan'] ?? 0);
      $db->commit();
      if ($newId > 0) {
        header('Location: penjualan_add.php?idpenjualan=' . urlencode($newId));
        exit;
      } else {
        $message = 'Gagal membuat header penjualan.';
      }
    } catch (Throwable $e) {
      try { $db->rollBack(); } catch (Throwable $_) {}
      $message = 'Gagal membuat header penjualan: ' . $e->getMessage();
    }
  } elseif ($action === 'update_status') {
    $idp = (int)($_POST['idpenjualan'] ?? 0);
    $toStatus = $_POST['to_status'] ?? 'A';
    try {
      // ensure status column exists; if missing, try to add it (best-effort)
      try {
        $col = $db->fetch("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status'");
        $hasStatus = !empty($col['cnt']);
      } catch (Throwable $_) { $hasStatus = false; }
      if (!$hasStatus) {
        try {
          $db->execute("ALTER TABLE `penjualan` ADD COLUMN `status` CHAR(1) NOT NULL DEFAULT 'A'");
          // attempt to recreate view silently
          $createView = "CREATE OR REPLACE VIEW `v_penjualanheader` AS SELECT pj.`idpenjualan` AS `idpenjualan`, pj.`created_at` AS `tanggal_penjualan`, u.`username` AS `kasir`, mp.`persen` AS `margin_persen`, pj.`total_nilai` AS `total_nilai`, pj.`status` AS `status` FROM `penjualan` pj JOIN `user` u ON u.`iduser` = pj.`iduser` JOIN `margin_penjualan` mp ON mp.`idmargin_penjualan` = pj.`idmargin_penjualan`;";
          try { $db->execute($createView); } catch (Throwable $_) {}
        } catch (Throwable $_) { /* ignore alter errors */ }
      }
      $db->execute('UPDATE penjualan SET status=? WHERE idpenjualan=?', [$toStatus, $idp]);
      $message = 'Status penjualan diperbarui.';
    } catch (Throwable $e) { $message = 'Gagal update status: ' . $e->getMessage(); }
  } elseif ($action === 'delete_penjualan') {
    $idp = (int)($_POST['idpenjualan'] ?? 0);
    if ($idp <= 0) { $message = 'ID Penjualan tidak valid.'; }
    else {
      try {
        // Prevent deleting finalized headers: if status column exists and header is 'F', block deletion
        $hdrStatus = null;
        try {
          $s = $db->fetch('SELECT `status` FROM penjualan WHERE idpenjualan = ? LIMIT 1', [$idp]);
          if ($s && array_key_exists('status', $s)) $hdrStatus = $s['status'];
        } catch (Throwable $_) { $hdrStatus = null; }
        if ($hdrStatus === 'F') {
          $message = 'Tidak dapat menghapus: penjualan telah difinalisasi.';
        } else {
          $cnt = $db->fetch('SELECT COUNT(*) AS c FROM detail_penjualan WHERE penjualan_idpenjualan = ?', [$idp]);
          if ((int)($cnt['c'] ?? 0) > 0) {
            $message = 'Tidak dapat menghapus: penjualan memiliki detail.';
          } else {
            $db->beginTransaction();
            $db->execute('DELETE FROM penjualan WHERE idpenjualan = ?', [$idp]);
            $db->commit();
            $message = 'Penjualan #' . $idp . ' berhasil dihapus.';
          }
        }
      } catch (Throwable $e) { try { $db->rollBack(); } catch (Throwable $_) {} $message = 'Gagal menghapus: ' . $e->getMessage(); }
    }
  }
}

// Only load what we need for header list
$marginAktif = $db->fetchAll('SELECT * FROM v_marginaktif ORDER BY created_at DESC');
$headersAll = $fetcher->penjualanHeader(); // uses v_penjualanheader
if(isset($_SESSION['flash_penjualan'])){ $message = $_SESSION['flash_penjualan']; unset($_SESSION['flash_penjualan']); }

$title = 'Transaksi Penjualan';
ob_start();
?>
<style>
/* Layout and form tidy for Penjualan */
.txn-wrap { display:flex; gap:20px; align-items:flex-start; }
.txn-left { flex: 1 1 360px; }
.txn-right { flex: 1 1 560px; }
.txn-panel { background:white; padding:18px; border-radius:10px; border:1px solid #eef3f6; }
.table { width:100%; border-collapse:collapse; }
.table th, .table td { padding:10px 12px; border-bottom:1px solid #f1f4f6; }
.table thead th { background:var(--primary-blue,#0d6efd); color:#fff; }
.header-row.active { background:#f0f7ff; }
.form-card { background:white; padding:20px; border-radius:12px; border:1px solid #eef3f6; }
.label-sm { display:block; font-size:12px; font-weight:600; color:#6c757d; text-transform:uppercase; margin-bottom:6px; }
.input-full { width:100%; padding:8px; box-sizing:border-box; }
.alert { padding:12px 16px; border-radius:6px; margin-bottom:16px; }
.alert.success { background:#e6f4ea; color:#1b5e20; border:1px solid #b7e1c7; }
.alert.error { background:#fdecea; color:#7a1a14; border:1px solid #f5c2c0; }
@media (max-width:1000px){ .txn-wrap{ flex-direction:column; } }

/* Penerimaan-like table styling for headers/actions */
.table.pengadaan-table { width:100%; table-layout: auto; border-collapse: collapse; box-sizing: border-box; }
.table.pengadaan-table col { width: auto !important; }
.table.pengadaan-table th, .table.pengadaan-table td { padding:10px 12px; font-size:14px; vertical-align: middle; box-sizing: border-box; }
.table.pengadaan-table thead th { background: var(--primary-blue, #0d6efd); color:#fff; }
.table.pengadaan-table th.numeric, .table.pengadaan-table td.numeric { text-align: right; white-space:nowrap; }
.table.pengadaan-table td.idcol { width:60px; text-align: right; white-space:nowrap; }
.table.pengadaan-table tr { border-bottom:1px solid #eee; }
  .table.pengadaan-table td.actions-col { width:270px; text-align:right; white-space:nowrap; }
.table.pengadaan-table .actions { display:flex; gap:8px; justify-content:flex-end; align-items:center; flex-wrap:nowrap; position:relative; z-index:1200; }
.table.pengadaan-table .actions .btn { padding:8px 12px; font-size:13px; border-radius:6px; position:relative; z-index:1210; }
.table.pengadaan-table tr { overflow: visible; }
/* stretch header table to panel edges like penerimaan (compensate for panel padding) */
.table.pengadaan-table { width:100%; table-layout: auto; border-collapse: collapse; box-sizing: border-box; }
.table.pengadaan-table col { width: auto !important; }
.table.pengadaan-table th, .table.pengadaan-table td { padding:10px 12px; font-size:14px; vertical-align: middle; box-sizing: border-box; }
.table.pengadaan-table thead th { background: var(--primary-blue, #0d6efd); color:#fff; }
.table.pengadaan-table th.numeric, .table.pengadaan-table td.numeric { text-align: right; white-space:nowrap; }
.table.pengadaan-table td.idcol { width:60px; text-align: right; white-space:nowrap; }
.table.pengadaan-table td.vendor, .table.pengadaan-table td.barang { white-space: normal; word-wrap:break-word; }
.txn-left.txn-panel .table.pengadaan-table { margin-top:12px; }
/* rounded blue header like penerimaan */
.txn-left.txn-panel .table.pengadaan-table thead th:first-child{ border-top-left-radius:10px; }
.txn-left.txn-panel .table.pengadaan-table thead th:last-child{ border-top-right-radius:10px; }
/* keep table full-width inside panel like pengadaan/penerimaan */
@media (max-width:1000px){ .txn-left.txn-panel .table.pengadaan-table { width:100%; margin-left:0; margin-right:0; } }
/* status badge */
.status-badge{display:inline-block;width:30px;height:30px;border-radius:50%;text-align:center;line-height:30px;font-weight:700}
.status-badge.green{background:#e9f7ef;color:#1b5e20}
.status-badge.gray{background:#f1f3f5;color:#6c757d}
</style>

<h1>Transaksi Penjualan</h1>
<?php if ($message):
  $isError = (stripos($message,'gagal') !== false || stripos($message,'error') !== false);
?>
  <div class="alert <?= $isError ? 'error' : 'success' ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<section style="margin-bottom:16px">
  <div class="txn-panel" style="padding:14px;">
    <h3 style="margin-top:0">Tambah Penjualan</h3>
    <form method="post" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="action" value="create_header">
      <?php if(count($marginAktif) === 1): ?>
        <input type="hidden" name="idmargin_penjualan" value="<?= (int)$marginAktif[0]['idmargin_penjualan'] ?>">
        <div style="font-size:14px;color:#6c757d;padding:6px 10px;background:#f8fafc;border-radius:8px">Margin aktif: <?= (float)$marginAktif[0]['persen'] ?>%</div>
      <?php else: ?>
        <label style="margin:0">
          <div style="font-size:12px;color:#6c757d;margin-bottom:6px">Margin</div>
          <select name="idmargin_penjualan" style="padding:8px;border-radius:6px">
            <option value="">- pilih margin -</option>
            <?php foreach($marginAktif as $m): ?>
              <option value="<?= $m['idmargin_penjualan'] ?>"><?= (float)$m['persen'] ?>%</option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <div style="flex:1"></div>
      <button class="btn" type="submit">Tambah Penjualan</button>
    </form>
  </div>
</section>
<!-- preview, input detail, and detail table removed per request; keep only header creation and header list -->

<!-- Headers panel placed below the input/preview area to match penerimaan/pengadaan layout -->
    <div style="margin-top:12px;">
    <table class="table pengadaan-table">
      <colgroup>
        <col style="width:60px">
        <col style="width:180px">
        <col style="width:140px">
        <col style="width:100px">
        <col style="width:110px">
        <col style="width:80px">
        <col style="width:270px">
      </colgroup>
      <thead>
        <tr>
          <th class="idcol">ID</th>
          <th class="tanggal">TANGGAL</th>
          <th class="kasir">KASIR</th>
          <th class="numeric">MARGIN %</th>
          <th class="numeric">TOTAL</th>
          <th>STATUS</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($headersAll as $h):
        $hid = $h['idpenjualan'] ?? '';
        $status = isset($h['status']) ? strtoupper(trim($h['status'])) : '';
        $tanggal = $h['created_at'] ?? $h['tanggal_penjualan'] ?? '-';
        $kasir = $h['username'] ?? $h['kasir'] ?? $h['nama_kasir'] ?? '-';
        $marginVal = isset($h['margin_persen']) ? $h['margin_persen'] : (isset($h['persen']) ? $h['persen'] : null);
        $totalVal = isset($h['total_nilai']) ? (int)$h['total_nilai'] : 0;
      ?>
      <tr class="header-row <?= ($status === 'F') ? 'active' : '' ?>">
        <td class="idcol"><?= $hid ? htmlspecialchars($hid) : '' ?></td>
        <td class="tanggal"><?= htmlspecialchars($tanggal) ?></td>
        <td class="kasir"><?= htmlspecialchars($kasir) ?></td>
        <td class="numeric"><?= $marginVal !== null ? htmlspecialchars((string)$marginVal) . ' %' : '-' ?></td>
        <td class="numeric"><?= number_format($totalVal) ?></td>
        <td>
          <?php
            $badgeClass = 'gray';
            if ($status === 'F') { $badgeClass = 'green'; }
          ?>
          <span class="status-badge <?= $badgeClass ?>"><?= $status ? htmlspecialchars($status) : '-' ?></span>
        </td>
        <td class="actions-col">
          <div class="actions">
            <a class="btn" href="penjualan_add.php?idpenjualan=<?= $hid ?>">Lihat</a>
            <form method="post" style="display:inline-block">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="idpenjualan" value="<?= $hid ?>">
              <select name="to_status">
                <option value="A" <?= (($h['status'] ?? '')=='A') ? 'selected' : '' ?>>A</option>
                <option value="P" <?= (($h['status'] ?? '')=='P') ? 'selected' : '' ?>>P</option>
                <option value="F" <?= (($h['status'] ?? '')=='F') ? 'selected' : '' ?>>F</option>
              </select>
              <button class="btn secondary">Ubah</button>
            </form>
            <form method="post" onsubmit="return confirm('Apakah Anda yakin ingin menghapus Penjualan #<?= $hid ?>?');" style="display:inline-block;margin-left:6px">
              <input type="hidden" name="action" value="delete_penjualan">
              <input type="hidden" name="idpenjualan" value="<?= $hid ?>">
              <button class="btn danger">Hapus</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
      </tbody>
    </table>
    </div>
  </div>
</div>

<script>
// Minimal JS: auto-select first margin option for convenience
document.addEventListener('DOMContentLoaded', function(){
  const marginSel = document.getElementById('idmargin_penjualan');
  if(marginSel && !marginSel.value){
    const firstOpt = Array.from(marginSel.options).find(o => o.value !== '');
    if(firstOpt){ marginSel.value = firstOpt.value; }
  }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
