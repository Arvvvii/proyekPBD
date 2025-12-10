<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

$idpenerimaan = isset($_GET['idpenerimaan']) ? (int)$_GET['idpenerimaan'] : 0;
$message = '';

if ($idpenerimaan <= 0) {
  // add-pages must be used with an existing header
  header('Location: ' . BASE_PATH . '/views/transaksi/penerimaan.php');
  exit;
}

$hdr = $db->fetch('SELECT p.*, pd.vendor_idvendor, v.nama_vendor FROM penerimaan p LEFT JOIN pengadaan pd ON p.idpengadaan = pd.idpengadaan LEFT JOIN vendor v ON pd.vendor_idvendor = v.idvendor WHERE p.idpenerimaan = ? LIMIT 1', [$idpenerimaan]);
if (!$hdr) { header('Location: ' . BASE_PATH . '/views/transaksi/penerimaan.php'); exit; }

$idpengadaan = (int)($hdr['idpengadaan'] ?? 0);
$hdr_status = strtoupper(trim($hdr['status'] ?? ''));
$isFinalized = ($hdr_status === 'F');

// POST: append details to existing header only, or finalize
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'delete_detail') {
    $idd = (int)($_POST['iddetail_penerimaan'] ?? 0);
    if ($idd <= 0) {
      $message = 'ID detail tidak valid.';
    } else {
      // refresh header status
      $hdrCurr = $db->fetch('SELECT status FROM penerimaan WHERE idpenerimaan = ? LIMIT 1', [$idpenerimaan]);
      $hdrStat = strtoupper(trim($hdrCurr['status'] ?? ''));
      if ($hdrStat === 'F') {
        $message = 'Penerimaan sudah dikunci. Tidak dapat menghapus detail.';
      } else {
        try {
          $db->beginTransaction();
          // delete the detail row
          $db->execute('DELETE FROM detail_penerimaan WHERE iddetail_penerimaan = ? AND idpenerimaan = ?', [$idd, $idpenerimaan]);
          // optionally update totals via SP or manual recalc; try SP first
          try { $db->callProcedure('SP_UpdatePenerimaanTotals', [$idpenerimaan]); } catch (Throwable $_sp) {
            // fallback: no-op; totals usually derived on report views
          }
          $db->commit();
          header('Location: ' . BASE_PATH . '/views/transaksi/penerimaan_add.php?idpenerimaan=' . urlencode($idpenerimaan));
          exit;
        } catch (Throwable $e) {
          try { $db->rollBack(); } catch (Throwable $_) {}
          $message = 'Gagal menghapus detail: ' . $e->getMessage();
        }
      }
    }
  }
  // AJAX add single item (called by client-side when user clicks "Tambah ke Daftar")
  if ($action === 'ajax_add_item') {
    // Expect AJAX POST and respond JSON
    header('Content-Type: application/json');
    $idbarang = (int)($_POST['idbarang'] ?? 0);
    $jumlah = (int)($_POST['jumlah'] ?? 0);
    $harga = (int)($_POST['harga'] ?? 0);
    if ($idbarang <= 0 || $jumlah < 0) {
      echo json_encode(['ok'=>false,'message'=>'Barang atau jumlah tidak valid.']); exit;
    }
    // Re-check header finalized
    $hdrCurr = $db->fetch('SELECT status, idpengadaan FROM penerimaan WHERE idpenerimaan = ? LIMIT 1', [$idpenerimaan]);
    $hdrStat = strtoupper(trim($hdrCurr['status'] ?? ''));
    $idpeng_check = (int)($hdrCurr['idpengadaan'] ?? $idpengadaan);
    if ($hdrStat === 'F') { echo json_encode(['ok'=>false,'message'=>'Penerimaan sudah dikunci.']); exit; }
    // Check outstanding for this barang in pengadaan
    $row = $db->fetch('SELECT dp.jumlah AS ordered_qty, COALESCE((SELECT SUM(dtp.jumlah_terima) FROM detail_penerimaan dtp JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan WHERE prm.idpengadaan = dp.idpengadaan AND dtp.barang_idbarang = dp.idbarang),0) AS received_qty FROM detail_pengadaan dp WHERE dp.idpengadaan = ? AND dp.idbarang = ? LIMIT 1', [$idpeng_check, $idbarang]);
    $orderedQty = (int)($row['ordered_qty'] ?? 0);
    $receivedQty = (int)($row['received_qty'] ?? 0);
    $outstanding = $orderedQty - $receivedQty;
    if ($outstanding <= 0) { echo json_encode(['ok'=>false,'message'=>'Barang ini sudah tidak memiliki sisa.']); exit; }
    if ($jumlah > $outstanding) { echo json_encode(['ok'=>false,'message'=>'Jumlah melebihi sisa: '.$outstanding]); exit; }
    try {
      $db->beginTransaction();
      $db->callProcedure('SP_InsertPenerimaan_Detail', [ $idpenerimaan, $idbarang, $jumlah, $harga ]);
      if ($harga > 0) {
        try { $db->execute('UPDATE barang SET harga = ? WHERE idbarang = ?', [$harga, $idbarang]); } catch (Throwable $_) { }
      }
      $db->commit();
      echo json_encode(['ok'=>true,'message'=>'Detail ditambahkan']);
      exit;
    } catch (Throwable $e) {
      try { $db->rollBack(); } catch (Throwable $_) {}
      echo json_encode(['ok'=>false,'message'=>'Gagal menambah detail: ' . $e->getMessage()]); exit;
    }
  }
  // AJAX delete single detail
  if ($action === 'ajax_delete_detail') {
    header('Content-Type: application/json');
    $idd = (int)($_POST['iddetail_penerimaan'] ?? 0);
    if ($idd <= 0) { echo json_encode(['ok'=>false,'message'=>'ID detail tidak valid']); exit; }
    // refresh header status
    $hdrCurr = $db->fetch('SELECT status FROM penerimaan WHERE idpenerimaan = ? LIMIT 1', [$idpenerimaan]);
    $hdrStat = strtoupper(trim($hdrCurr['status'] ?? ''));
    if ($hdrStat === 'F') { echo json_encode(['ok'=>false,'message'=>'Penerimaan sudah dikunci. Tidak dapat menghapus detail.']); exit; }
    try {
      $db->beginTransaction();
      $db->execute('DELETE FROM detail_penerimaan WHERE iddetail_penerimaan = ? AND idpenerimaan = ?', [$idd, $idpenerimaan]);
      try { $db->callProcedure('SP_UpdatePenerimaanTotals', [$idpenerimaan]); } catch (Throwable $_) {}
      $db->commit();
      echo json_encode(['ok'=>true,'message'=>'Detail dihapus']); exit;
    } catch (Throwable $e) {
      try { $db->rollBack(); } catch (Throwable $_) {}
      echo json_encode(['ok'=>false,'message'=>'Gagal menghapus detail: ' . $e->getMessage()]); exit;
    }
  }
  if ($action === 'finalize') {
    // Kunci penerimaan: set status = 'F'
    try {
      $db->beginTransaction();
      $db->execute('UPDATE penerimaan SET status = ? WHERE idpenerimaan = ?', ['F', $idpenerimaan]);
      $db->commit();
      header('Location: ' . BASE_PATH . '/views/transaksi/penerimaan_add.php?idpenerimaan=' . urlencode($idpenerimaan));
      exit;
    } catch (Throwable $e) {
      try { $db->rollBack(); } catch (Throwable $_) {}
      $message = 'Gagal mengunci penerimaan: ' . $e->getMessage();
    }
  }

  // If header already finalized, disallow adding
  $hdrPostStatus = strtoupper(trim($hdr['status'] ?? ''));
  if ($hdrPostStatus === 'F') {
    $message = 'Penerimaan ini sudah dikunci. Tidak dapat menambah detail.';
  } else {
    // proceed with items insertion
    $itemsJson = $_POST['items'] ?? null;
    $items = [];
    if ($itemsJson) {
      $decoded = json_decode($itemsJson, true);
      if (is_array($decoded)) $items = $decoded;
    } else {
      $idbarang = (int)($_POST['idbarang'] ?? 0);
      $jumlah = (int)($_POST['jumlah_terima'] ?? 0); // allow 0 but validated below
      $harga = (int)($_POST['harga_satuan_terima'] ?? 0);
      if ($idbarang > 0 && $jumlah >= 0) $items[] = ['idbarang'=>$idbarang,'jumlah'=>$jumlah,'harga'=>$harga];
    }
    if (empty($items)) {
      $message = 'Tidak ada item untuk ditambahkan.';
    } else {
      try {
        $db->beginTransaction();
        $inserted = 0;
        foreach ($items as $it) {
          $bid = (int)($it['idbarang'] ?? 0);
          $j = (int)($it['jumlah'] ?? 0);
          $h = (int)($it['harga'] ?? 0);
          if ($bid <= 0 || $j < 0) continue; // allow zero but skip invalid

          // Check outstanding qty for this pengadaan + barang
          $row = $db->fetch('SELECT dp.jumlah AS ordered_qty, COALESCE((SELECT SUM(dtp.jumlah_terima) FROM detail_penerimaan dtp JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan WHERE prm.idpengadaan = dp.idpengadaan AND dtp.barang_idbarang = dp.idbarang),0) AS received_qty FROM detail_pengadaan dp WHERE dp.idpengadaan = ? AND dp.idbarang = ? LIMIT 1', [$idpengadaan, $bid]);
          $orderedQty = (int)($row['ordered_qty'] ?? 0);
          $receivedQty = (int)($row['received_qty'] ?? 0);
          $outstanding = $orderedQty - $receivedQty;
          if ($outstanding <= 0) {
            throw new RuntimeException('Semua qty untuk barang ID ' . $bid . ' sudah diterima.');
          }
          // Allow zero-quantity receipt per business rule (some flows record 0 to indicate placeholder)
          if ($j < 0) {
            throw new RuntimeException('Jumlah terima tidak valid untuk barang ID ' . $bid . '.');
          }
          if ($j > $outstanding) {
            throw new RuntimeException('Qty terima (' . $j . ') melebihi sisa (' . $outstanding . ') untuk barang ID ' . $bid . '.');
          }

          // call SP to insert detail and let DB triggers handle kartu_stok
          $db->callProcedure('SP_InsertPenerimaan_Detail', [ $idpenerimaan, $bid, $j, $h ]);
          // Perbarui harga jual di master barang (harga disimpan TANPA PPN)
          if ($h > 0) {
            try {
              $db->execute('UPDATE barang SET harga = ? WHERE idbarang = ?', [$h, $bid]);
            } catch (Throwable $_upErr) {
              // jangan menggagalkan seluruh transaksi hanya karena update harga gagal
              error_log('Warning: gagal update harga barang ' . $bid . ': ' . $_upErr->getMessage());
            }
          }
          $inserted++;
        }
        if ($inserted > 0) {
          $db->commit();
          // PRG: redirect back to same page
          header('Location: ' . BASE_PATH . '/views/transaksi/penerimaan_add.php?idpenerimaan=' . urlencode($idpenerimaan));
          exit;
        } else {
          $db->rollBack();
          $message = 'Tidak ada baris yang disimpan.';
        }
      } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable $_) {}
        error_log('Penerimaan add error: ' . $e->getMessage());
        $message = 'Gagal menyimpan detail penerimaan: ' . preg_replace('/^SQLSTATE\[[^\]]+\]:\s*/','',$e->getMessage());
      }
    }
  }
  }

$title = 'Tambah Detail Penerimaan #' . $idpenerimaan;
// compute totals for ringkasan
$sumRow = $db->fetch("SELECT COALESCE(SUM(CASE WHEN jumlah_terima > 0 THEN jumlah_terima * harga_satuan_terima ELSE 0 END),0) AS subtotal FROM v_detailpenerimaanlengkap WHERE idpenerimaan = ?", [$idpenerimaan]);
$subtotal_val = (int)($sumRow['subtotal'] ?? 0);
$ppn_val = (int)round($subtotal_val * 0.11);
$total_val = $subtotal_val + $ppn_val;
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

<h1>Detail Penerimaan #<?= $idpenerimaan ?></h1>
<?php if($message): ?><div class="card" style="padding:10px;margin-bottom:12px"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<div class="txn-wrap">
  <div class="txn-left txn-panel">
    <h3>Ringkasan</h3>
    <div style="font-weight:600">Vendor: <?= htmlspecialchars($hdr['nama_vendor'] ?? '-') ?></div>
    <div style="margin-top:6px">Pengadaan: #<?= $idpengadaan ?></div>
    <div style="margin-top:8px">Tanggal: <?= htmlspecialchars($hdr['tanggal_terima'] ?? $hdr['created_at'] ?? '-') ?></div>
    <div style="margin-top:10px">Dibuat oleh: <?= htmlspecialchars($hdr['user_iduser'] ?? '-') ?> • Status: <?= htmlspecialchars($hdr['status'] ?? '-') ?></div>

    <div style="margin-top:12px;padding:12px;background:#fff;border-radius:6px;border:1px solid #f2f6f8">
      <div style="margin-bottom:8px">Subtotal: <strong>Rp <?= number_format($subtotal_val) ?></strong></div>
      <div style="margin-bottom:8px;background:#fff4e5;padding:8px;border-radius:6px">PPN (11%): <strong>Rp <?= number_format($ppn_val) ?></strong></div>
      <div style="margin-bottom:6px;background:#f2fff4;padding:8px;border-radius:6px">Total: <strong>Rp <?= number_format($total_val) ?></strong></div>
    </div>

    <div style="margin-top:12px">
      <a class="btn" href="<?= BASE_PATH ?>/views/transaksi/penerimaan.php">Kembali ke Penerimaan</a>
    </div>
  </div>

  <div class="txn-right txn-panel" style="min-width:420px">
    <h3>Tambah Detail</h3>
    <?php if($isFinalized): ?>
      <div style="padding:12px;border-radius:8px;background:#E9F7FF;border:1px solid #BFE6FF;color:#0B5FA8;margin-bottom:12px">Penerimaan ini telah difinalisasi. Tidak dapat menambah atau menghapus detail.</div>
    <?php else: ?>
      <div style="padding:6px 0;margin-bottom:8px">
        <form method="post" onsubmit="return confirm('Kunci penerimaan ini? Setelah dikunci tidak bisa ditambah lagi.');" style="display:inline-block;margin:0">
          <input type="hidden" name="action" value="finalize">
          <button class="btn secondary">Kunci Penerimaan</button>
        </form>
      </div>
    <?php endif; ?>

    <div style="margin-bottom:12px">
      <?php if(!$isFinalized): ?>
      <form id="formPenerimaanAdd" method="post">
        <label>Barang (dari PO)<br>
          <select id="barang_id" style="width:100%"></select>
        </label>
        <label>Jumlah Terima<br><input type="number" id="jumlah_terima" min="0"></label>
        <label>Harga Satuan Terima<br><input type="number" id="harga_satuan_terima" min="0"></label>
        <input type="hidden" name="items" id="items_input" value="">
        <div style="display:flex;gap:8px;margin-top:8px">
          <button type="button" id="addToCartBtn" class="btn">Tambah ke Daftar</button>
          <button type="submit" class="btn">Simpan Semua</button>
        </div>
      </form>
      <?php else: ?>
        <div style="color:#666;padding:8px;background:#fbfbfb;border:1px solid #eee;border-radius:6px">Form penambahan detail dinonaktifkan.</div>
      <?php endif; ?>
    </div>

    <h3>Daftar Detail</h3>
    <div id="detailList">
      <?php $lines = $db->fetchAll('SELECT * FROM v_detailpenerimaanlengkap WHERE idpenerimaan = ? ORDER BY iddetail_penerimaan DESC', [$idpenerimaan]) ?? []; ?>
      <?php if(count($lines)===0): ?>
        <div class="card">Belum ada detail untuk penerimaan ini.</div>
      <?php else: ?>
        <table class="table"><thead><tr><th>ID</th><th>BARANG</th><th class="numeric">JUMLAH</th><th class="numeric">HARGA</th><th>AKSI</th></tr></thead><tbody>
          <?php foreach($lines as $ln): ?>
            <tr><td><?= $ln['iddetail_penerimaan'] ?></td><td><?= htmlspecialchars($ln['nama_barang']) ?></td><td class="numeric"><?= $ln['jumlah_terima'] ?></td><td class="numeric"><?= number_format($ln['harga_satuan_terima']) ?></td><td><?= $isFinalized ? 'Terkunci' : '<form method="post" style="display:inline-block"><input type="hidden" name="action" value="delete_detail"><input type="hidden" name="iddetail_penerimaan" value="'.$ln['iddetail_penerimaan'].'"><button class="btn small danger" onclick="return confirm(\'Hapus detail ini?\')">Hapus</button></form>' ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const idpengadaan = <?= json_encode($idpengadaan) ?>;
const idpenerimaan = <?= json_encode($idpenerimaan) ?>;
const barangSelect = document.getElementById('barang_id');
const jumlahEl = document.getElementById('jumlah_terima');
const hargaEl = document.getElementById('harga_satuan_terima');
const itemsInput = document.getElementById('items_input');
const cart = [];
// If there is serialized cart data in the hidden input (e.g., after a reload), initialize the cart from it
if (itemsInput && itemsInput.value) {
  try {
    const parsed = JSON.parse(itemsInput.value);
    if (Array.isArray(parsed)) {
      parsed.forEach(p => { if (p && p.idbarang) cart.push({idbarang: p.idbarang, nama: p.nama || '', jumlah: p.jumlah || 0, harga: p.harga || 0}); });
    }
  } catch (e) { /* ignore parse errors */ }
}

async function loadBarang(){
  if (!barangSelect) return;
  // clear existing options and add placeholder
  barangSelect.innerHTML = '';
  barangSelect.appendChild(new Option('-- Pilih barang --', ''));
  try {
    const resp = await fetch('get_po_details.php?idpengadaan=' + encodeURIComponent(idpengadaan));
    const rows = await resp.json();
    if (!Array.isArray(rows) || rows.length === 0) {
      // no outstanding items
      barangSelect.innerHTML = '';
      const opt = new Option('Tidak ada barang tersisa dari PO', ''); opt.disabled = true; barangSelect.appendChild(opt);
      return;
    }
    // populate options
    rows.forEach((r, idx) => {
      const opt = document.createElement('option');
      opt.value = r.idbarang || '';
      opt.textContent = (r.nama_barang || 'Barang') + (typeof r.outstanding_qty !== 'undefined' ? ' (sisa: '+ (r.outstanding_qty||0) +')' : '');
      opt.dataset.harga = r.harga_satuan || 0;
      opt.dataset.sisa = r.outstanding_qty || 0;
      barangSelect.appendChild(opt);
      // prefill first available
      if (idx === 0) {
        try { hargaEl.value = parseInt(r.harga_satuan || 0); jumlahEl.max = parseInt(r.outstanding_qty || 0); } catch (e) {}
      }
    });
  } catch (e) {
    console.error('loadBarang error', e);
    barangSelect.innerHTML = '';
    const opt = new Option('Gagal memuat barang', ''); opt.disabled = true; barangSelect.appendChild(opt);
  }
}

function renderCart(){
  const el = document.getElementById('cartList');
  if(!el) return;
  if(cart.length===0){ el.className='card'; el.textContent='Belum ada item di daftar.'; if(itemsInput) itemsInput.value=''; return; }
  let html = '<table class="table"><thead><tr><th>Barang</th><th>Jumlah</th><th>Harga</th><th>Aksi</th></tr></thead><tbody>';
  cart.forEach((it,idx)=>{ html += `<tr><td>${it.nama}</td><td class="numeric">${it.jumlah}</td><td class="numeric">${it.harga}</td><td><button data-idx="${idx}" class="btn small">Hapus</button></td></tr>` });
  html += '</tbody></table>';
  el.innerHTML = html;
  el.querySelectorAll('button[data-idx]').forEach(b=> b.addEventListener('click', function(){ const i=parseInt(this.dataset.idx); if(!isNaN(i)){ cart.splice(i,1); renderCart(); } }));
  if(itemsInput) itemsInput.value = JSON.stringify(cart.map(c=>({idbarang:c.idbarang,jumlah:c.jumlah,harga:c.harga, nama:c.nama})));
}
const addToCartBtn = document.getElementById('addToCartBtn');
if (addToCartBtn) {
  addToCartBtn.addEventListener('click', async function(){
    const opt = barangSelect.selectedOptions && barangSelect.selectedOptions[0] ? barangSelect.selectedOptions[0] : null;
    if(!opt || !opt.value) return alert('Pilih barang terlebih dahulu');
    const idb = parseInt(opt.value);
    const nama = opt.textContent || '';
    const j = parseInt(jumlahEl.value||0);
    const h = parseInt(hargaEl.value||0);
    const sisa = parseInt(opt.dataset.sisa || opt.dataset.outstanding || 0);
    if(sisa <= 0) return alert('Barang ini tidak memiliki sisa yang dapat diterima.');
    if(isNaN(j) || j < 0) return alert('Jumlah tidak valid (harus >= 0)');
    // check against outstanding on server by attempting AJAX add
    try {
      const form = new URLSearchParams();
      form.append('action','ajax_add_item');
      form.append('idbarang', String(idb));
      form.append('jumlah', String(j));
      form.append('harga', String(h));
      const resp = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await resp.json();
      if (!data.ok) {
        showToast(data.message || 'Gagal menambah item', 'error');
        return;
      }
      // success: refresh detail list from server
      await loadDetails();
      // also reload pengadaan/stock info for selected option
      // reset inputs
      if (jumlahEl) jumlahEl.value = '';
      renderCart();
    } catch (e) {
      console.error(e);
      showToast('Gagal menambah item (ajax).', 'error');
    }
  });
}

if (barangSelect) barangSelect.addEventListener('change', function(){ const o=this.selectedOptions[0]; if(o){ const h=o.dataset.harga||''; const s=o.dataset.sisa||0; if(h!=='') hargaEl.value = parseInt(h)||0; if(jumlahEl) jumlahEl.max = parseInt(s)||0; } });

// on submit ensure items hidden input is populated
const formPenerimaanAdd = document.getElementById('formPenerimaanAdd');
if (formPenerimaanAdd) {
  formPenerimaanAdd.addEventListener('submit', function(){ if(cart.length>0 && itemsInput){ itemsInput.value = JSON.stringify(cart.map(c=>({idbarang:c.idbarang,jumlah:c.jumlah,harga:c.harga}))); } return true; });
}

// initial render from any existing cart data
renderCart();

loadBarang();
// Toast helper
function showToast(msg, type='info'){
  try{
    const containerId = 'toastContainer';
    let cont = document.getElementById(containerId);
    if(!cont){ cont = document.createElement('div'); cont.id = containerId; cont.style.position='fixed'; cont.style.right='20px'; cont.style.top='20px'; cont.style.zIndex=99999; document.body.appendChild(cont); }
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.marginBottom='8px'; t.style.padding='10px 14px'; t.style.borderRadius='8px'; t.style.minWidth='220px'; t.style.boxShadow='0 6px 18px rgba(0,0,0,0.08)'; t.style.color='#fff';
    if(type==='success'){ t.style.background='#2f9e44'; } else if(type==='error'){ t.style.background='#d63333'; } else { t.style.background='#0d6efd'; }
    cont.appendChild(t);
    setTimeout(()=>{ try{ t.style.transition='opacity 0.4s'; t.style.opacity='0'; setTimeout(()=> t.remove(),450);}catch(e){} }, 4000);
  }catch(e){ console.error('toast error',e); }
}
// load current detail list via AJAX and render into #detailList
async function loadDetails(){
  try{
    const resp = await fetch('get_penerimaan_detail.php?idpenerimaan=' + encodeURIComponent(idpenerimaan));
    const data = await resp.json();
    const wrapper = document.getElementById('detailList');
    if(!wrapper) return;
    if(!data){ wrapper.innerHTML = '<div class="card">Belum ada detail untuk penerimaan ini.</div>'; return; }
    if (data.error) { wrapper.innerHTML = '<div class="card" style="color:#a00">Terjadi kesalahan: '+String(data.error)+'</div>'; return; }
    const rows = data.rows || [];
    if (!Array.isArray(rows) || rows.length === 0) { wrapper.innerHTML = '<div class="card">Belum ada detail untuk penerimaan ini.</div>'; }
    else {
      let html = '<table class="table"><thead><tr><th>ID</th><th>BARANG</th><th class="numeric">JUMLAH</th><th class="numeric">HARGA</th><th>AKSI</th></tr></thead><tbody>';
      rows.forEach(r => {
        const id = r.iddetail_penerimaan || '';
        const nama = r.nama_barang || '';
        const jumlah = r.jumlah_terima || 0;
        const harga = (r.harga_satuan_terima || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        let actionHtml = '';
        // If the header is finalized, show a locked label instead of a delete button
        if (data.finalized) {
          actionHtml = '<span style="color:#666">Terkunci</span>';
        } else {
          actionHtml = `<button data-id="${id}" class="btn small danger ajax-delete-btn">Hapus</button>`;
        }
        html += `<tr><td>${id}</td><td>${nama}</td><td class="numeric">${jumlah}</td><td class="numeric">${harga}</td><td>${actionHtml}</td></tr>`;
      });
      html += '</tbody></table>';
      wrapper.innerHTML = html;
    }

    // Update left-side totals if provided
    if (data.totals) {
      const subtotalFormatted = 'Rp ' + Number(data.totals.subtotal || 0).toLocaleString();
      const ppnFormatted = 'Rp ' + Number(data.totals.ppn || 0).toLocaleString();
      const totalFormatted = 'Rp ' + Number(data.totals.total || 0).toLocaleString();
      document.querySelectorAll('.txn-left .txn-panel div').forEach(n => {
        if(n.textContent && n.textContent.includes('Subtotal')) n.innerHTML = 'Subtotal: <strong>' + subtotalFormatted + '</strong>';
        if(n.textContent && n.textContent.includes('PPN')) n.innerHTML = 'PPN (11%): <strong>' + ppnFormatted + '</strong>';
        if(n.textContent && n.textContent.includes('Total:')) n.innerHTML = 'Total: <strong>' + totalFormatted + '</strong>';
      });
    }
  }catch(e){ console.error('loadDetails error',e); showToast('Gagal memuat detail: '+(e.message||e),'error'); }
}
// initial load of details
loadDetails();
// delegate AJAX delete clicks
document.addEventListener('click', async function(e){
  const btn = e.target.closest && e.target.closest('.ajax-delete-btn');
  if (!btn) return;
  if (!confirm('Hapus detail ini?')) return;
  const id = btn.dataset.id;
  try {
    const form = new URLSearchParams(); form.append('action','ajax_delete_detail'); form.append('iddetail_penerimaan', String(id));
    const resp = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await resp.json();
    if (data.ok) {
      showToast(data.message || 'Detail dihapus', 'success');
      await loadDetails();
    } else {
      showToast(data.message || 'Gagal hapus', 'error');
    }
  } catch (err) { console.error(err); showToast('Gagal hapus (ajax)', 'error'); }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
?>
