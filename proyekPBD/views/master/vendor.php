<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../models/BaseModel.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
class VendorModel extends BaseModel { public function __construct(Database $db){ parent::__construct($db,'vendor'); } }
$vendorModel = new VendorModel($db);

$mode = $_GET['mode'] ?? 'aktif';
if ($mode==='archive') {
  $view='v_vendorall';
  $archiveFilter='';
  $currentViewLabel='Semua Data (Aktif & Non-Aktif)';
  $viewName='v_vendorall';
} else {
  $view='v_vendoraktif';
  $archiveFilter='';
  $currentViewLabel='Aktif';
  $viewName='v_vendoraktif';
}
$message='';

if($_SERVER['REQUEST_METHOD']==='POST') {
  $action=$_POST['action']??'';
  if($action==='create') {
    $vendorModel->insert(['nama_vendor'=>trim($_POST['nama_vendor']),'badan_hukum'=>$_POST['badan_hukum'],'status'=>$_POST['status']]);
    $message='Vendor ditambah';
  } elseif($action==='update') {
    $id=(int)$_POST['idvendor'];
    $vendorModel->update($id,['nama_vendor'=>trim($_POST['nama_vendor']),'badan_hukum'=>$_POST['badan_hukum'],'status'=>$_POST['status']],'idvendor');
    $message='Vendor diupdate';
  } elseif($action==='delete') {
    $id=(int)$_POST['idvendor'];
    $vendorModel->delete($id,'idvendor');
    $message='Vendor dihapus';
  }
}

$list = $db->fetchAll("SELECT * FROM $view" . ($archiveFilter? $archiveFilter : '') . " ORDER BY nama_vendor");
$title='Master Vendor';
ob_start();
?>
<h1>Master Vendor</h1>
<div class="actions"><a class="btn secondary" href="?mode=aktif">Data Aktif</a><a class="btn secondary" href="?mode=archive">Semua Data</a></div>
<?php if($message): ?><div class="card" style="background:#0b1220;padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Tambah Vendor (Status otomatis Aktif)</h2>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="create">
    <label>Nama Vendor<br><input name="nama_vendor" required></label>
    <label>Badan Hukum<br><select name="badan_hukum"><option value="1">PT</option><option value="2">CV</option><option value="3">UD</option></select></label>
    <input type="hidden" name="status" value="1">
    <button class="btn">Simpan</button>
  </form>
</section>
<section>
  <h2>Daftar Vendor (<?= $currentViewLabel ?> - <?= $viewName ?>)</h2>
  <table class="table"><thead><tr><th>ID</th><th>Nama Vendor</th><th>Badan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach($list as $v): ?>
      <tr>
        <td><?= $v['idvendor'] ?></td>
        <td><?= htmlspecialchars($v['nama_vendor']) ?></td>
        <td><?= htmlspecialchars($v['badan_hukum']) ?></td>
        <td><?= $v['status']=='1'?'Aktif':'Nonaktif' ?></td>
        <td>
          <details><summary>Edit/Hapus</summary>
            <form method="post" class="inline" style="margin-top:6px">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="idvendor" value="<?= $v['idvendor'] ?>">
              <input name="nama_vendor" value="<?= htmlspecialchars($v['nama_vendor']) ?>" required>
              <select name="badan_hukum"><option value="1" <?= $v['badan_hukum']=='1'?'selected':'' ?>>PT</option><option value="2" <?= $v['badan_hukum']=='2'?'selected':'' ?>>CV</option><option value="3" <?= $v['badan_hukum']=='3'?'selected':'' ?>>UD</option></select>
              <input type="hidden" name="status" value="<?= $v['status'] ?>">
              <button class="btn secondary">Update</button>
            </form>
            <form method="post" style="margin-top:6px" onsubmit="return confirm('Hapus vendor?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="idvendor" value="<?= $v['idvendor'] ?>">
              <button class="btn danger">Delete</button>
            </form>
            <form method="post" style="margin-top:6px">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="idvendor" value="<?= $v['idvendor'] ?>">
              <input type="hidden" name="nama_vendor" value="<?= htmlspecialchars($v['nama_vendor']) ?>">
              <input type="hidden" name="badan_hukum" value="<?= $v['badan_hukum'] ?>">
              <input type="hidden" name="status" value="<?= $v['status']=='1' ? 0 : 1 ?>">
              <button class="btn secondary" title="<?= $v['status']=='1'?'Arsipkan':'Aktifkan' ?>"><?= $v['status']=='1'?'Arsipkan':'Aktifkan' ?></button>
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
