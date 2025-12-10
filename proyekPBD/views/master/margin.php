<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
// session started centrally in views/template.php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';
require_once __DIR__ . '/../../models/BaseModel.php';
if(!isset($_SESSION['user_id'])) { header('Location: ' . BASE_PATH . '/login.php'); exit; }
$db = Database::getInstance();
class MarginModel extends BaseModel { public function __construct(Database $db){ parent::__construct($db,'margin_penjualan'); } }
$marginModel = new MarginModel($db);

$mode = $_GET['mode'] ?? 'aktif';
if ($mode==='archive') {
  $view = 'v_marginall';
  $archiveFilter = '';
  $currentViewLabel = 'Semua Data (Aktif & Non-Aktif)';
  $viewName = 'v_marginall';
} else {
  $view = 'v_marginaktif';
  $archiveFilter = '';
  $currentViewLabel = 'Aktif';
  $viewName = 'v_marginaktif';
}
$message='';

// Implementasi PRG & Single Active Margin
if($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  if($action==='create') {
    // Nonaktifkan semua margin aktif sebelumnya
    $db->execute('UPDATE margin_penjualan SET status=0 WHERE status=1');
    $marginModel->insert([
      'persen'=>(float)$_POST['persen'],
      'status'=>1,
      'iduser'=>(int)$_SESSION['user_id']
    ]);
    $_SESSION['flash_margin']='Margin aktif baru ditambahkan.';
    header('Location: ' . BASE_PATH . '/views/master/margin.php?mode=' . urlencode($mode));
    exit;
  } elseif($action==='update') {
    $id=(int)$_POST['idmargin_penjualan'];
    $newStatus=(int)$_POST['status'];
    // Jika akan mengaktifkan margin ini, nonaktifkan yang lain dulu
    if($newStatus===1) {
      $db->execute('UPDATE margin_penjualan SET status=0 WHERE status=1 AND idmargin_penjualan <> ?', [$id]);
    }
    $marginModel->update($id,[
      'persen'=>(float)$_POST['persen'],
      'status'=>$newStatus
    ],'idmargin_penjualan');
    $_SESSION['flash_margin']='Margin diperbarui.';
    header('Location: ' . BASE_PATH . '/views/master/margin.php?mode=' . urlencode($mode));
    exit;
  } elseif($action==='delete') {
    $id=(int)$_POST['idmargin_penjualan'];
    $marginModel->delete($id,'idmargin_penjualan');
    $_SESSION['flash_margin']='Margin dihapus.';
    header('Location: ' . BASE_PATH . '/views/master/margin.php?mode=' . urlencode($mode));
    exit;
  }
}

if(isset($_SESSION['flash_margin'])) {
  $message = $_SESSION['flash_margin'];
  unset($_SESSION['flash_margin']);
}

$list = $db->fetchAll("SELECT * FROM $view" . ($archiveFilter? $archiveFilter : '') . " ORDER BY created_at DESC");
$title='Master Margin Penjualan';
ob_start();
?>
<h1>Master Margin Penjualan</h1>
<div class="actions"><a class="btn secondary" href="?mode=aktif">Data Aktif</a><a class="btn secondary" href="?mode=archive">Semua Data</a></div>
<?php if($message): ?><div class="card" style="background:#0b1220;padding:10px;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<section>
  <h2>Tambah Margin (Status otomatis Aktif)</h2>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="create">
    <label>Persen (%)<br><input type="number" step="0.01" name="persen" required></label>
    <input type="hidden" name="status" value="1">
    <button class="btn">Simpan</button>
  </form>
</section>
<section>
  <h2>Daftar Margin (<?= $currentViewLabel ?> - <?= $viewName ?>)</h2>
  <table class="table"><thead><tr><th>ID</th><th>Persen</th><th>Status</th><th>Ditetapkan Oleh</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach($list as $m): ?>
      <tr>
        <td><?= $m['idmargin_penjualan'] ?></td>
        <td><?= (float)$m['persen'] ?>%</td>
        <td><?= $m['status'] ? 'Aktif':'Nonaktif' ?></td>
        <td><?= htmlspecialchars($m['ditetapkan_oleh'] ?? $m['iduser'] ?? '-') ?></td>
        <td><?= $m['created_at'] ?></td>
        <td>
          <details><summary>Edit/Hapus</summary>
            <form method="post" class="inline" style="margin-top:6px">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="idmargin_penjualan" value="<?= $m['idmargin_penjualan'] ?>">
              <input type="number" step="0.01" name="persen" value="<?= (float)$m['persen'] ?>" required>
              <input type="hidden" name="status" value="<?= $m['status'] ?>">
              <button class="btn secondary">Update</button>
            </form>
            <form method="post" style="margin-top:6px" onsubmit="return confirm('Hapus margin?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="idmargin_penjualan" value="<?= $m['idmargin_penjualan'] ?>">
              <button class="btn danger">Delete</button>
            </form>
            <form method="post" style="margin-top:6px">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="idmargin_penjualan" value="<?= $m['idmargin_penjualan'] ?>">
              <input type="hidden" name="persen" value="<?= (float)$m['persen'] ?>">
              <input type="hidden" name="status" value="<?= $m['status'] ? 0 : 1 ?>">
              <button class="btn secondary" title="<?= $m['status']?'Arsipkan':'Aktifkan' ?>"><?= $m['status']?'Arsipkan':'Aktifkan' ?></button>
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
