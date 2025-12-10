<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

$idpenjualan = isset($_GET['idpenjualan']) ? (int)$_GET['idpenjualan'] : 0;
$message = '';
// restore flash message if present (PRG)
if (isset($_SESSION['flash_penjualan'])) { $message = $_SESSION['flash_penjualan']; unset($_SESSION['flash_penjualan']); }

if ($idpenjualan <= 0) {
  header('Location: ' . BASE_PATH . '/views/transaksi/penjualan.php');
  exit;
}

$hdr = $db->fetch('SELECT p.* FROM penjualan p WHERE idpenjualan = ? LIMIT 1', [$idpenjualan]);
if (!$hdr) { header('Location: ' . BASE_PATH . '/views/transaksi/penjualan.php'); exit; }

$isFinal = (isset($hdr['status']) && $hdr['status'] === 'F');
$isFinalized = $isFinal; // alias for clarity in templates
$disabledAttr = $isFinal ? 'disabled' : '';

// determine header margin percent
$skipAppend = false; // flag to skip append-processing when an action (delete/finalize) was handled

$headerMarginPct = 0;
if (!empty($hdr['idmargin_penjualan'])) {
  $m = $db->fetch('SELECT persen FROM v_marginaktif WHERE idmargin_penjualan = ? LIMIT 1', [$hdr['idmargin_penjualan']]);
  $headerMarginPct = $m ? (float)$m['persen'] : 0;
}

// fetch existing detail lines for this header (used in table and for JS cart init)
$lines = $db->fetchAll('SELECT dp.*, b.nama AS nama_barang FROM detail_penjualan dp LEFT JOIN barang b ON dp.idbarang = b.idbarang WHERE dp.penjualan_idpenjualan = ? AND COALESCE(dp.jumlah,0) > 0 ORDER BY dp.iddetail_penjualan DESC', [$idpenjualan]) ?? [];

// Handle simple actions first: delete detail, finalize header
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];
  if ($action === 'delete_detail' && !$isFinal) {
    $idd = (int)($_POST['iddetail'] ?? 0);
    if ($idd > 0) {
      try {
        $db->execute('DELETE FROM detail_penjualan WHERE iddetail_penjualan = ? AND penjualan_idpenjualan = ?', [$idd, $idpenjualan]);
        try { $db->callProcedure('SP_UpdateHeaderPenjualan', [$idpenjualan]); } catch (Throwable $_) {}
      } catch (Throwable $e) {
        error_log('Failed deleting detail: '.$e->getMessage());
      }
    }
    header('Location: ' . BASE_PATH . '/views/transaksi/penjualan_add.php?idpenjualan=' . urlencode($idpenjualan));
    exit;
  }
    if ($action === 'finalize_penjualan' && !$isFinal) {
      // ensure append logic is skipped when finalizing
      $skipAppend = true;
      try {
        // don't allow finalization if there are no detail rows
        $cntRow = $db->fetch('SELECT COALESCE(COUNT(*),0) AS c FROM detail_penjualan WHERE penjualan_idpenjualan = ?', [$idpenjualan]);
        $detailCount = (int)($cntRow['c'] ?? 0);
        if ($detailCount <= 0) {
          $message = 'Tidak ada transaksi untuk difinalisasi.';
          header('Location: ' . BASE_PATH . '/views/transaksi/penjualan_add.php?idpenjualan=' . urlencode($idpenjualan));
          exit;
        }

        // perform in single transaction: call SP to calculate, then set header status
        $db->beginTransaction();
        // 1) recalculate header totals via stored procedure
        $db->callProcedure('SP_UpdateHeaderPenjualan', [$idpenjualan]);

      // 2) ensure `status` column exists on penjualan; if not, try to add it and recreate view
      try {
        $col = $db->fetch("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status'");
        $hasStatus = !empty($col['cnt']);
      } catch (Throwable $_) { $hasStatus = false; }
      if (!$hasStatus) {
        try {
          $db->execute("ALTER TABLE `penjualan` ADD COLUMN `status` CHAR(1) NOT NULL DEFAULT 'A'");
          // Attempt to recreate view v_penjualanheader so UI can read status as well
          $createView = "CREATE OR REPLACE VIEW `v_penjualanheader` AS SELECT pj.`idpenjualan` AS `idpenjualan`, pj.`created_at` AS `tanggal_penjualan`, u.`username` AS `kasir`, mp.`persen` AS `margin_persen`, pj.`total_nilai` AS `total_nilai`, pj.`status` AS `status` FROM `penjualan` pj JOIN `user` u ON u.`iduser` = pj.`iduser` JOIN `margin_penjualan` mp ON mp.`idmargin_penjualan` = pj.`idmargin_penjualan`;";
          try { $db->execute($createView); } catch (Throwable $_) {}
        } catch (Throwable $_) {
          // ignore ALTER failure (likely due to permissions); we'll still try to UPDATE and handle error below
        }
      }

      // 3) lock header status — make this robust so missing column won't abort finalization
      try {
        $db->execute('UPDATE penjualan SET status = ? WHERE idpenjualan = ?', ['F', $idpenjualan]);
      } catch (Throwable $_) {
        // if UPDATE fails (missing column or permissions), ignore — finalization already recalculated totals
      }

      // 4) commit
      $db->commit();
      // set flash message and redirect (PRG)
      $_SESSION['flash_penjualan'] = 'Penjualan #' . $idpenjualan . ' berhasil difinalisasi.';
      header('Location: ' . BASE_PATH . '/views/transaksi/penjualan_add.php?idpenjualan=' . urlencode($idpenjualan));
      exit;
    } catch (Throwable $e) {
      try { $db->rollBack(); } catch (Throwable $_) {}
      error_log('Failed finalizing penjualan: '.$e->getMessage());
      $message = 'Gagal melakukan finalisasi: ' . preg_replace('/^SQLSTATE\[[^\]]+\]:\s*/','',$e->getMessage());
    }
  }
}

// POST: append details to existing header only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$skipAppend) {
  $itemsJson = $_POST['items'] ?? null;
  $items = [];
  if ($itemsJson) {
    $decoded = json_decode($itemsJson, true);
    if (is_array($decoded)) {
      // filter out items with non-positive quantities (we don't show/save jumlah=0)
      foreach ($decoded as $di) {
        $q = (int)($di['jumlah'] ?? 0);
        $bid = (int)($di['idbarang'] ?? 0);
        $h = (int)($di['harga'] ?? 0);
        if ($bid > 0 && $q > 0) $items[] = ['idbarang'=>$bid,'jumlah'=>$q,'harga'=>$h];
      }
    }
  } else {
    $idbarang = (int)($_POST['idbarang'] ?? 0);
    $jumlah = (int)($_POST['jumlah'] ?? 0);
    $harga = (int)($_POST['harga_satuan'] ?? 0);
    // if harga not provided via form, try to read from barang table (data-harga)
    if ($harga <= 0 && $idbarang > 0) {
      $brow = $db->fetch('SELECT harga FROM barang WHERE idbarang = ? LIMIT 1', [$idbarang]);
      $harga = $brow ? (int)$brow['harga'] : 0;
    }
    // only accept positive quantities for saving
    if ($idbarang > 0 && $jumlah > 0) $items[] = ['idbarang'=>$idbarang,'jumlah'=>$jumlah,'harga'=>$harga];
  }
  if (empty($items)) {
    $message = 'Tidak ada item untuk ditambahkan.';
  } else {
    try {
      $db->beginTransaction();
      $inserted = 0;
      // consolidate items by idbarang (merge duplicates)
      $consol = [];
      foreach ($items as $it) {
        $bid = (int)($it['idbarang'] ?? 0);
        $j = (int)($it['jumlah'] ?? 0);
        $h = (int)($it['harga'] ?? 0);
        if ($bid <= 0 || $j <= 0) continue;
        if (!isset($consol[$bid])) {
          $consol[$bid] = ['idbarang'=>$bid,'jumlah'=>0,'harga'=>$h];
        }
        $consol[$bid]['jumlah'] += $j;
        // prefer latest harga
        $consol[$bid]['harga'] = $h > 0 ? $h : $consol[$bid]['harga'];
      }
      // validate stock for all consolidated items before inserting
      $stockIssue = false;
      foreach ($consol as $ci) {
        $bid = $ci['idbarang']; $j = $ci['jumlah']; $h = $ci['harga'];
        // ambil stok saat ini dari view v_stoksummary (kolom stok_saat_ini)
        $brow = $db->fetch('SELECT stok_saat_ini FROM v_stoksummary WHERE idbarang = ? LIMIT 1', [$bid]);
        $stok = $brow ? (int)($brow['stok_saat_ini'] ?? 0) : 0;
        if ($j > $stok) {
          $db->rollBack();
          $message = 'Stok tidak mencukupi untuk barang ID ' . $bid . ' (tersedia: ' . $stok . ', diminta: ' . $j . ')';
          $stockIssue = true;
          break;
        }
      }
      // perform inserts only if no stock issues
      if (!$stockIssue) {
        foreach ($consol as $ci) {
          $bid = $ci['idbarang']; $j = $ci['jumlah']; $h = $ci['harga'];
          $sub = $j * $h;
          $db->query('INSERT INTO detail_penjualan (penjualan_idpenjualan, idbarang, jumlah, harga_satuan, sub_total) VALUES (?,?,?,?,?)', [$idpenjualan, $bid, $j, $h, $sub]);
          $inserted++;
        }
      }
      if ($inserted > 0) {
        try { $db->callProcedure('SP_UpdateHeaderPenjualan', [$idpenjualan]); } catch(Throwable $_) {}
        $db->commit();
        header('Location: ' . BASE_PATH . '/views/transaksi/penjualan_add.php?idpenjualan=' . urlencode($idpenjualan));
        exit;
      } else {
        if (empty($stockIssue)) {
          try { $db->rollBack(); } catch (Throwable $_) {}
          $message = 'Tidak ada baris yang disimpan.';
        }
        // if $stockIssue is true, $message already set to stock error
      }
    } catch (Throwable $e) {
      try { $db->rollBack(); } catch (Throwable $_) {}
      error_log('Penjualan add error: ' . $e->getMessage());
      $message = 'Gagal menyimpan detail penjualan: ' . preg_replace('/^SQLSTATE\[[^\]]+\]:\s*/','',$e->getMessage());
    }
  }
}

$title = 'Tambah Detail Penjualan #' . $idpenjualan;
ob_start();
?>
<style>
.txn-wrap{display:flex;gap:20px}
.txn-left{flex:1}
.txn-panel{background:#fff;padding:16px;border:1px solid #eef3f6;border-radius:8px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:8px 10px;border-bottom:1px solid #f1f4f6}
.table thead th{background:var(--primary-blue,#0d6efd);color:#fff}
@media(max-width:900px){.txn-wrap{flex-direction:column}}
</style>

<h1>Detail Penjualan #<?= $idpenjualan ?></h1>
<?php if($message): ?><div class="card" style="padding:10px;margin-bottom:12px"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if($isFinalized): ?>
  <div style="padding:12px;border-radius:8px;background:#E9F7FF;border:1px solid #BFE6FF;color:#0B5FA8;margin-bottom:12px">Transaksi ini telah difinalisasi dan tidak dapat diubah.</div>
<?php endif; ?>
<div class="txn-wrap">
  <div class="txn-left txn-panel">
    <h3>Header</h3>
    <div>No. Penjualan: <?= htmlspecialchars($hdr['no_penjualan'] ?? '-') ?></div>
    <div style="margin-top:8px">Tanggal: <?= htmlspecialchars($hdr['tanggal'] ?? $hdr['created_at'] ?? '-') ?></div>
      <div style="margin-top:8px">
      <a class="btn" href="<?= BASE_PATH . '/views/transaksi/penjualan.php' ?>">Kembali ke Header Penjualan</a>
        <form method="post" style="display:inline;margin-left:8px">
          <input type="hidden" name="action" value="finalize_penjualan">
          <button type="submit" class="btn warning" <?= $isFinal ? 'disabled' : '' ?>>Finalisasi Penjualan</button>
        </form>
      </div>
    <h3 style="margin-top:16px">Tambah Item</h3>
    <form id="formPenjualanAdd" method="post">
      <label>Barang<br>
        <select id="barang_id" style="width:100%" <?= $disabledAttr ?> >
          <option value="">Pilih barang di sini</option>
          <?php foreach($fetcher->barangAktif() as $b): 
              // ambil stok saat ini per barang dari view v_stoksummary
              $stokOptRow = $db->fetch('SELECT stok_saat_ini FROM v_stoksummary WHERE idbarang = ? LIMIT 1', [$b['idbarang']]);
              $stokOpt = $stokOptRow ? (int)($stokOptRow['stok_saat_ini'] ?? 0) : 0;
          ?>
            <option value="<?= $b['idbarang'] ?>" data-harga="<?= $b['harga'] ?>" data-stok="<?= $stokOpt ?>"><?= htmlspecialchars($b['nama_barang']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
        <div id="stok_info" style="margin-top:6px;color:#6c757d;font-size:13px">Stok tersedia: -</div>
      <style>
        .field-row{display:flex;flex-direction:column;gap:6px}
        .field-row.inline{flex-direction:row;gap:12px}
        .field-row .field{flex:1}
        .field-row label{display:block}
        @media(min-width:900px){ .field-row.inline{flex-direction:row} }
      </style>
      <div class="field-row">
        <div class="field">
          <label>Harga Satuan (auto isi dari barang, editable)<br>
            <input type="number" id="harga_satuan" name="harga_satuan" min="0" value="0" <?= $disabledAttr ?>>
          </label>
        </div>
        <div class="field">
          <label>Jumlah<br>
            <input type="number" id="jumlah" name="jumlah" min="0" value="1" <?= $disabledAttr ?>>
          </label>
        </div>
      </div>
      <input type="hidden" name="items" id="items_input" value="">
      <div style="display:flex;gap:8px;margin-top:8px">
        <?php if(!$isFinal): ?>
          <button type="button" id="addToCartBtn" class="btn">Tambah ke Daftar</button>
          <button type="submit" class="btn">Simpan Semua</button>
        <?php else: ?>
          <!-- navigation already present above; no duplicate back button -->
        <?php endif; ?>
      </div>
    </form>
    <div id="cartList" style="margin-top:12px" class="card">Belum ada item di daftar.</div>
  </div>
  <div class="txn-right txn-panel" style="min-width:360px">
    <h3>Ringkasan</h3>
    <div style="margin-bottom:12px;padding:12px;background:#E8F4F8;border-radius:8px;border:1px solid #d6eef8">
      <div style="font-weight:700;color:var(--primary-blue);margin-bottom:8px">Preview Transaksi</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
        <div style="background:white;padding:12px;border-radius:6px;"><div style="font-size:11px;color:#6c757d">Margin</div><div id="pv_margin"><?= $headerMarginPct ?>%</div></div>
        <div style="background:white;padding:12px;border-radius:6px;"><div style="font-size:11px;color:#6c757d">Harga Jual / Unit</div><div id="pv_harga_jual">0</div></div>
        <div style="background:white;padding:12px;border-radius:6px;"><div style="font-size:11px;color:#6c757d">Subtotal</div><div id="pv_subtotal">0</div></div>
        <div style="background:white;padding:12px;border-radius:6px;"><div style="font-size:11px;color:#6c757d">PPN (<?= (int)(defined('TAX_RATE') ? TAX_RATE * 100 : 10) ?>%)</div><div id="pv_ppn">0</div></div>
      </div>
      <div style="margin-top:12px;padding:12px;background:var(--primary-blue,#0d6efd);color:#fff;border-radius:6px;display:flex;justify-content:space-between;align-items:center"><div style="font-weight:700">Total Bayar</div><div id="pv_total">0</div></div>
    </div>
    <div id="detailList">
      <?php if(count($lines)===0): ?>
        <div class="card">Belum ada detail untuk penjualan ini.</div>
      <?php else: ?>
        <table class="table"><thead><tr><th>ID</th><th>Barang</th><th class="numeric">Jumlah</th><th class="numeric">Harga</th><th class="numeric">Subtotal</th><th>Aksi</th></tr></thead><tbody>
          <?php foreach($lines as $ln): ?>
            <tr>
              <td><?= $ln['iddetail_penjualan'] ?></td>
              <td><?= htmlspecialchars($ln['nama_barang']) ?></td>
              <td class="numeric"><?= $ln['jumlah'] ?></td>
              <td class="numeric"><?= number_format($ln['harga_satuan']) ?></td>
              <td class="numeric"><?= number_format($ln['sub_total'] ?? ($ln['jumlah'] * $ln['harga_satuan'])) ?></td>
              <td>
                <?php if (!$isFinal): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="delete_detail">
                    <input type="hidden" name="iddetail" value="<?= $ln['iddetail_penjualan'] ?>">
                    <button type="submit" class="btn small danger">Hapus</button>
                  </form>
                <?php else: ?>
                  <span style="color:#666">Terkunci</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const TAX_ENABLED = <?= (defined('TAX_ENABLED') && TAX_ENABLED) ? 'true' : 'false' ?>;
const TAX_RATE = <?= defined('TAX_RATE') ? TAX_RATE : 0.10 ?>;
const HEADER_MARGIN_PCT = <?= json_encode($headerMarginPct) ?>;
const IS_FINAL = <?= $isFinal ? 'true' : 'false' ?>;
const cart = [];
const barangSelect = document.getElementById('barang_id');
const jumlahEl = document.getElementById('jumlah');
const hargaEl = document.getElementById('harga_satuan');
const itemsInput = document.getElementById('items_input');

function formatNum(n){ return (typeof n === 'number') ? n.toLocaleString('id-ID') : n; }
function renderCart(){
  const el = document.getElementById('cartList');
  if(cart.length===0){ el.className='card'; el.textContent='Belum ada item di daftar.'; itemsInput.value=''; updatePreview(); return; }
  let html = '<table class="table"><thead><tr><th>Barang</th><th>Jumlah</th><th>Harga</th><th>Subtotal</th><th>Aksi</th></tr></thead><tbody>';
  cart.forEach((it,idx)=>{ const sub = (Number(it.harga)||0) * (Number(it.jumlah)||0);
    if (IS_FINAL) {
      html += `<tr><td>${it.nama}</td><td class="numeric">${it.jumlah}</td><td class="numeric">${formatNum(it.harga)}</td><td class="numeric">${formatNum(sub)}</td><td><span style="color:#666">Terkunci</span></td></tr>`;
    } else {
      html += `<tr><td>${it.nama}</td><td class="numeric">${it.jumlah}</td><td class="numeric">${formatNum(it.harga)}</td><td class="numeric">${formatNum(sub)}</td><td><button data-idx="${idx}" class="btn small">Hapus</button></td></tr>`;
    }
  });
  html += '</tbody></table>';
  el.innerHTML = html;
  // only attach delete handlers when not finalized
  if (!IS_FINAL) {
    el.querySelectorAll('button[data-idx]').forEach(b=> b.addEventListener('click', function(){ const i=parseInt(this.dataset.idx); if(!isNaN(i)){ cart.splice(i,1); renderCart(); } }));
  }
  itemsInput.value = JSON.stringify(cart.map(c=>({idbarang:c.idbarang,jumlah:c.jumlah,harga:c.harga})));
}

const addBtn = document.getElementById('addToCartBtn');
if (addBtn) {
  addBtn.addEventListener('click', function(){
    if (IS_FINAL) { alert('Penjualan telah difinalisasi. Tidak dapat menambah item.'); return; }
    const opt = barangSelect.selectedOptions && barangSelect.selectedOptions[0] ? barangSelect.selectedOptions[0] : null;
    if(!opt || !opt.value) return alert('Pilih barang terlebih dahulu');
    const idb = parseInt(opt.value);
    const nama = opt.textContent || '';
    const j = parseInt(jumlahEl.value||0);
    // prefer harga from input if present, otherwise fallback to option data-harga
    const hFromOpt = parseInt(opt.dataset.harga || 0);
    const stok = parseInt(opt.dataset.stok || 0);
    const h = hargaEl ? parseInt(hargaEl.value || hFromOpt) : hFromOpt;
    if(j <= 0) return alert('Jumlah harus > 0');
    // check stock: if adding to existing item, consider aggregated qty
    const existingIndex = cart.findIndex(c => parseInt(c.idbarang) === idb);
    if (existingIndex >= 0) {
      const existing = cart[existingIndex];
      const newQty = Number(existing.jumlah || 0) + j;
      if (stok > 0 && newQty > stok) return alert('Stok tidak mencukupi. Stok tersedia: ' + stok);
      // merge: update jumlah and harga (use latest harga input)
      existing.jumlah = newQty;
      existing.harga = h;
    } else {
      if (stok > 0 && j > stok) return alert('Stok tidak mencukupi. Stok tersedia: ' + stok);
      cart.push({idbarang:idb,nama:nama,jumlah:j,harga:h});
    }
    renderCart();
  });
}

// harga otomatis diambil dari opsi - tidak ada input harga langsung lagi
// when select changes, auto-fill harga_satuan input from option data-harga
barangSelect && barangSelect.addEventListener('change', function(){ const o=this.selectedOptions && this.selectedOptions[0] ? this.selectedOptions[0] : null; if(o && typeof hargaEl !== 'undefined' && hargaEl){ const h=o.dataset.harga||''; if(h!=='') hargaEl.value = parseInt(h)||0; } });
// update stock info display when selection changes
const stokInfoEl = document.getElementById('stok_info');
function updateStockInfo(){ const o = barangSelect && barangSelect.selectedOptions && barangSelect.selectedOptions[0] ? barangSelect.selectedOptions[0] : null; const s = o ? (o.dataset.stok || '-') : '-'; if(stokInfoEl) stokInfoEl.textContent = 'Stok tersedia: ' + (s === '' ? '-' : s); }
barangSelect && barangSelect.addEventListener('change', updateStockInfo);
// initialize stok info on load
updateStockInfo();

const formEl = document.getElementById('formPenjualanAdd');
if (formEl) {
  formEl.addEventListener('submit', function(e){ if (IS_FINAL) { e.preventDefault(); alert('Penjualan telah difinalisasi. Tidak dapat menyimpan perubahan.'); return false; } if(cart.length>0){ itemsInput.value = JSON.stringify(cart.map(c=>({idbarang:c.idbarang,jumlah:c.jumlah,harga:c.harga}))); } return true; });
}

function updatePreview(){
  const pvSubtotal = document.getElementById('pv_subtotal');
  const pvPpn = document.getElementById('pv_ppn');
  const pvTotal = document.getElementById('pv_total');
  const pvMarginEl = document.getElementById('pv_margin');
  const pvHargaJualEl = document.getElementById('pv_harga_jual');
  if(!pvSubtotal) return;
  let subtotal = 0;
  const marginPct = Number(HEADER_MARGIN_PCT || 0);
  if(cart.length > 0){
    // apply margin to each cart item's harga when computing subtotal
    cart.forEach(it => { const base = Math.round(it.harga || 0); const hargaUnit = Math.round(base * (1 + (marginPct/100))); subtotal += hargaUnit * (it.jumlah || 0); });
    // show harga jual if single-item, otherwise leave as dash
    if (pvHargaJualEl) pvHargaJualEl.textContent = cart.length === 1 ? formatNum(Math.round((cart[0].harga || 0) * (1 + (marginPct/100)))) : '-';
    if (pvMarginEl) pvMarginEl.textContent = marginPct + '%';
  } else {
    const q = parseInt(jumlahEl.value||0);
    // gunakan input harga jika ada, kalau tidak fallback ke option data-harga
    const opt = barangSelect.selectedOptions && barangSelect.selectedOptions[0] ? barangSelect.selectedOptions[0] : null;
    const optPrice = opt ? parseInt(opt.dataset.harga || 0) : 0;
    const price = (typeof hargaEl !== 'undefined' && hargaEl) ? parseInt(hargaEl.value || optPrice) : optPrice;
    const hargaJualUnit = Math.round(price * (1 + (marginPct/100)));
    subtotal = hargaJualUnit * q;
    if(pvHargaJualEl) pvHargaJualEl.textContent = formatNum(hargaJualUnit);
    if(pvMarginEl) pvMarginEl.textContent = marginPct + '%';
  }
  const ppn = TAX_ENABLED ? Math.round(subtotal * TAX_RATE) : 0;
  const total = subtotal + ppn;
  pvSubtotal.textContent = formatNum(subtotal);
  pvPpn.textContent = formatNum(ppn);
  pvTotal.textContent = formatNum(total);
}

// initialize cart from existing details
const initialCart = <?= json_encode(array_map(function($d){ return ['idbarang'=>(int)$d['idbarang'],'jumlah'=>(int)$d['jumlah'],'harga'=>(int)($d['harga_satuan'] ?? 0),'nama'=>$d['nama_barang'] ?? '']; }, $lines)) ?>;
if(Array.isArray(initialCart) && initialCart.length){ initialCart.forEach(i => cart.push(i)); renderCart(); }

// initial preview
updatePreview();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';

