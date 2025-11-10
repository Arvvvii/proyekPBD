<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
require_once __DIR__ . '/../../models/BaseModel.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

class BarangModel extends BaseModel { public function __construct(Database $db){ parent::__construct($db,'barang'); } }
$barangModel = new BarangModel($db);

$mode = $_GET['mode'] ?? 'aktif';
// Mode logic: default 'aktif'; 'archive' now shows ALL (aktif + nonaktif)
if ($mode==='archive') {
  $barang = $fetcher->barangAll();
  $currentViewLabel = 'Semua Data (Aktif & Non-Aktif)';
  $viewName = 'v_barangall';
} else {
  $barang = $fetcher->barangAktif();
  $currentViewLabel = 'Aktif';
  $viewName = 'v_barangaktif';
}
$satuan = $fetcher->satuanAktif();
$message = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  if ($action==='create') {
    $barangModel->insert([
      'jenis'=>$_POST['jenis'],
      'nama'=>trim($_POST['nama']),
      'idsatuan'=>(int)$_POST['idsatuan'],
      'status'=>(int)($_POST['status'] ?? 1),
      'harga'=>(int)$_POST['harga']
    ]);
    $message='Barang ditambah';
  } elseif ($action==='update') {
    $id=(int)$_POST['idbarang'];
    $data=[
      'jenis'=>$_POST['jenis'],
      'nama'=>trim($_POST['nama']),
      'idsatuan'=>(int)$_POST['idsatuan'],
      'status'=>(int)($_POST['status'] ?? 1),
      'harga'=>(int)$_POST['harga']
    ];
    $barangModel->update($id,$data,'idbarang');
    $message='Barang diupdate';
  } elseif ($action==='delete') {
    $id=(int)$_POST['idbarang'];
    $barangModel->delete($id,'idbarang');
    $message='Barang dihapus';
  }
  // reload list dengan mempertahankan mode tampilan
  $barang = ($mode==='archive') ? $fetcher->barangAll() : $fetcher->barangAktif();
}

$title='Master Barang';
ob_start();
?>
<h1>Master Barang</h1>
<div class="actions">
  <a class="btn secondary" href="?mode=aktif">Data Aktif</a>
  <a class="btn secondary" href="?mode=archive">Semua Data</a>
</div>
<?php if($message): ?><div class="card" style="background:#0b1220; padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Tambah Barang (Status otomatis Aktif)</h2>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="create">
    <label>Jenis<br><select name="jenis" required><option value="B">Bahan</option><option value="P">Produk</option></select></label>
    <label>Nama<br><input name="nama" required></label>
    <label>Satuan<br><select name="idsatuan" required>
      <option value="">-pilih-</option>
      <?php foreach($satuan as $s): ?>
        <option value="<?= $s['idsatuan'] ?>"><?= htmlspecialchars($s['nama_satuan']) ?></option>
      <?php endforeach; ?>
    </select></label>
    <input type="hidden" name="status" value="1">
    <label>Harga<br><input type="number" name="harga" required></label>
    <button class="btn">Simpan</button>
  </form>
</section>
<section>
  <h2>Daftar Barang (<?= $currentViewLabel ?> - <?= $viewName ?>)</h2>
  <table class="table">
    <thead><tr><th>ID</th><th>Jenis</th><th>Nama</th><th>Satuan</th><th>Harga</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
      <?php foreach($barang as $b): ?>
        <tr>
          <td><?= $b['idbarang'] ?></td>
          <td><?= htmlspecialchars($b['jenis']) ?></td>
          <td><?= htmlspecialchars($b['nama_barang']) ?></td>
          <td><?= htmlspecialchars($b['nama_satuan']) ?></td>
          <td><?= number_format($b['harga']) ?></td>
          <td><?= $b['status'] ? 'Aktif':'Nonaktif' ?></td>
          <td>
            <details>
              <summary>Edit/Hapus</summary>
              <form method="post" class="inline" style="margin-top:6px">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="idbarang" value="<?= $b['idbarang'] ?>">
                <select name="jenis"><option value="B" <?= $b['jenis']=='B'?'selected':'' ?>>B</option><option value="P" <?= $b['jenis']=='P'?'selected':'' ?>>P</option></select>
                <input name="nama" value="<?= htmlspecialchars($b['nama_barang']) ?>" required>
                <select name="idsatuan">
                  <?php foreach($satuan as $s): ?>
                    <option value="<?= $s['idsatuan'] ?>" <?= $s['idsatuan']==$b['idsatuan']?'selected':'' ?>><?= htmlspecialchars($s['nama_satuan']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="status" value="<?= $b['status'] ?>">
                <input type="number" name="harga" value="<?= $b['harga'] ?>" required>
                <button class="btn secondary">Update</button>
              </form>
              <form method="post" onsubmit="return confirm('Hapus barang ini?');" style="margin-top:6px">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="idbarang" value="<?= $b['idbarang'] ?>">
                <button class="btn danger">Delete</button>
              </form>
              <form method="post" style="margin-top:6px">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="idbarang" value="<?= $b['idbarang'] ?>">
                <input type="hidden" name="jenis" value="<?= htmlspecialchars($b['jenis']) ?>">
                <input type="hidden" name="nama" value="<?= htmlspecialchars($b['nama_barang']) ?>">
                <input type="hidden" name="idsatuan" value="<?= $b['idsatuan'] ?>">
                <input type="hidden" name="harga" value="<?= $b['harga'] ?>">
                <input type="hidden" name="status" value="<?= $b['status'] ? 0 : 1 ?>">
                <button class="btn secondary" title="<?= $b['status']?'Arsipkan':'Aktifkan' ?>"><?= $b['status']?'Arsipkan':'Aktifkan' ?></button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
