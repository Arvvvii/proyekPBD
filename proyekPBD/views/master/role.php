<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
// session started centrally in views/template.php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../models/BaseModel.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();

class RoleModel extends BaseModel { public function __construct(Database $db){ parent::__construct($db,'role'); } }
$roleModel = new RoleModel($db);
$message='';

if($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  if($action==='create') {
    $roleModel->insert(['nama_role'=>trim($_POST['nama_role'])]);
    $message='Role ditambah';
  } elseif($action==='update') {
    $id=(int)$_POST['idrole'];
    $roleModel->update($id,['nama_role'=>trim($_POST['nama_role'])],'idrole');
    $message='Role diupdate';
  } elseif($action==='delete') {
    $id=(int)$_POST['idrole'];
    $roleModel->delete($id,'idrole');
    $message='Role dihapus';
  }
}

$roles = $db->fetchAll('SELECT * FROM v_roleall ORDER BY nama_role');
$title='Master Role';
ob_start();
?>
<h1>Master Role</h1>
<?php if($message): ?><div class="card" style="background:#0b1220;padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Tambah Role</h2>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="create">
    <label>Nama Role<br><input name="nama_role" required></label>
    <button class="btn">Simpan</button>
  </form>
</section>
<section>
  <h2>Daftar Role (v_roleall)</h2>
  <table class="table" id="roleTable">
    <thead><tr><th>ID</th><th>Nama Role</th><th>Aksi</th></tr></thead>
    <tbody>
      <?php foreach($roles as $r): ?>
        <tr>
          <td><?= $r['idrole'] ?></td>
          <td><?= htmlspecialchars($r['nama_role']) ?></td>
          <td>
            <details>
              <summary>Edit/Hapus</summary>
              <form method="post" class="inline" style="margin-top:6px">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="idrole" value="<?= $r['idrole'] ?>">
                <input name="nama_role" value="<?= htmlspecialchars($r['nama_role']) ?>" required>
                <button class="btn secondary">Update</button>
              </form>
              <form method="post" style="margin-top:6px" onsubmit="return confirm('Hapus role?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="idrole" value="<?= $r['idrole'] ?>">
                <button class="btn danger">Delete</button>
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
