<?php
session_start();
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../models/BaseModel.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
class SatuanModel extends BaseModel { public function __construct(Database $db){ parent::__construct($db,'satuan'); } }
$satuanModel = new SatuanModel($db);

$mode = $_GET['mode'] ?? 'aktif';
if ($mode==='archive') {
  $view = 'v_satuanall'; // tampilkan semua (aktif + nonaktif)
  $archiveFilter = '';
  $currentViewLabel = 'Semua Data (Aktif & Non-Aktif)';
  $viewName = 'v_satuanall';
} else {
  $view = 'v_satuanaktif';
  $archiveFilter = '';
  $currentViewLabel = 'Aktif';
  $viewName = 'v_satuanaktif';
}
$message='';

if($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  if($action==='create') {
    $satuanModel->insert(['nama_satuan'=>trim($_POST['nama_satuan']),'status'=>(int)$_POST['status']]);
    $message='Satuan ditambah';
  } elseif($action==='update') {
    $id=(int)$_POST['idsatuan'];
    $satuanModel->update($id,['nama_satuan'=>trim($_POST['nama_satuan']),'status'=>(int)$_POST['status']],'idsatuan');
    $message='Satuan diupdate';
  } elseif($action==='delete') {
    $id=(int)$_POST['idsatuan'];
    $satuanModel->delete($id,'idsatuan');
    $message='Satuan dihapus';
  }
}

$list = $db->fetchAll("SELECT * FROM $view" . ($archiveFilter? $archiveFilter : '') . " ORDER BY nama_satuan");
$title='Master Satuan';
ob_start();
?>
<h1>Master Satuan</h1>
<div class="actions"><a class="btn secondary" href="?mode=aktif">Data Aktif</a><a class="btn secondary" href="?mode=archive">Semua Data</a></div>
<?php if($message): ?><div class="card" style="background:#0b1220;padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Tambah Satuan (Status otomatis Aktif)</h2>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="create">
    <label>Nama<br><input name="nama_satuan" required></label>
    <input type="hidden" name="status" value="1">
    <button class="btn">Simpan</button>
  </form>
</section>
<section>
  <h2>Daftar Satuan (<?= $currentViewLabel ?> - <?= $viewName ?>)</h2>
  <table class="table"><thead><tr><th>ID</th><th>Nama</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach($list as $s): ?>
      <tr>
        <td><?= $s['idsatuan'] ?></td>
        <td><?= htmlspecialchars($s['nama_satuan']) ?></td>
        <td><?= $s['status'] ? 'Aktif' : 'Nonaktif' ?></td>
        <td>
          <details><summary>Edit/Hapus</summary>
            <form method="post" class="inline" style="margin-top:6px">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="idsatuan" value="<?= $s['idsatuan'] ?>">
              <input name="nama_satuan" value="<?= htmlspecialchars($s['nama_satuan']) ?>" required>
              <input type="hidden" name="status" value="<?= $s['status'] ?>">
              <button class="btn secondary">Update</button>
            </form>
            <form method="post" style="margin-top:6px" onsubmit="return confirm('Hapus satuan?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="idsatuan" value="<?= $s['idsatuan'] ?>">
              <button class="btn danger">Delete</button>
            </form>
            <form method="post" style="margin-top:6px">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="idsatuan" value="<?= $s['idsatuan'] ?>">
              <input type="hidden" name="nama_satuan" value="<?= htmlspecialchars($s['nama_satuan']) ?>">
              <input type="hidden" name="status" value="<?= $s['status'] ? 0 : 1 ?>">
              <button class="btn secondary" title="<?= $s['status']?'Arsipkan':'Aktifkan' ?>"><?= $s['status']?'Arsipkan':'Aktifkan' ?></button>
            </form>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../views/template.php';
