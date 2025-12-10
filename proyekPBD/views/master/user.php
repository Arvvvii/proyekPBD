<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../utilities/DataFetcher.php';
require_once __DIR__ . '/../../models/BaseModel.php';

if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
$fetcher = new DataFetcher($db);

class UserModel extends BaseModel { public function __construct(Database $db){ parent::__construct($db,'user'); } }
$userModel = new UserModel($db);

$roles = $fetcher->roles();
$message = '';

// Handle CRUD actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $idrole = (int)$_POST['idrole'];
        $userModel->insert(['username'=>$username,'password'=>$password,'idrole'=>$idrole]);
        $message = 'User berhasil ditambah';
    } elseif ($action === 'update') {
        $iduser = (int)$_POST['iduser'];
        $username = trim($_POST['username']);
        $idrole = (int)$_POST['idrole'];
        $data = ['username'=>$username,'idrole'=>$idrole];
        if (!empty($_POST['password'])) { $data['password'] = password_hash($_POST['password'], PASSWORD_BCRYPT); }
        $userModel->update($iduser,$data,'iduser');
        $message = 'User berhasil diupdate';
    } elseif ($action === 'delete') {
        $iduser = (int)$_POST['iduser'];
        $userModel->delete($iduser,'iduser');
        $message = 'User berhasil dihapus';
    }
}

$users = $fetcher->users();
$title = 'Master User';
ob_start();
?>
<h1>Master User</h1>
<?php if ($message): ?><div class="card" style="background:#0b1220; padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Tambah / Edit User</h2>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="create">
    <div>
      <label>Username<br><input name="username" required></label>
    </div>
    <div>
      <label>Password<br><input name="password" type="password" required></label>
    </div>
    <div>
      <label>Role<br>
        <select name="idrole" required>
          <option value="">-pilih-</option>
          <?php foreach($roles as $r): ?>
            <option value="<?= $r['idrole'] ?>"><?= htmlspecialchars($r['nama_role']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <button class="btn" type="submit">Simpan</button>
  </form>
</section>

<section>
  <h2>Daftar User (v_userall - Semua Data)</h2>
  <table class="table">
    <thead><tr><th>ID</th><th>Username</th><th>Role</th><th>Aksi</th></tr></thead>
    <tbody>
      <?php foreach($users as $u): ?>
        <tr>
          <td><?= $u['iduser'] ?></td>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><?= htmlspecialchars($u['nama_role']) ?></td>
          <td>
            <details>
              <summary>Edit/Hapus</summary>
              <form method="post" class="inline" style="margin-top:6px">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="iduser" value="<?= $u['iduser'] ?>">
                <input name="username" value="<?= htmlspecialchars($u['username']) ?>" required>
                <input name="password" type="password" placeholder="(kosongkan jika tidak ganti)">
                <select name="idrole" required>
                  <?php foreach($roles as $r): ?>
                    <option value="<?= $r['idrole'] ?>" <?= $r['idrole']==$u['idrole']?'selected':'' ?>><?= htmlspecialchars($r['nama_role']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn secondary">Update</button>
              </form>
              <form method="post" onsubmit="return confirm('Hapus user ini?');" style="margin-top:6px">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="iduser" value="<?= $u['iduser'] ?>">
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
