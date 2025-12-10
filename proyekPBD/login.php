<?php
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/AppConfig.php';
require_once __DIR__ . '/controllers/AuthController.php';

// Ensure session is started so AuthController can write to $_SESSION
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$auth = new AuthController(Database::getInstance());
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    try {
        if ($auth->login($username, $password)) {
      header('Location: ' . BASE_PATH . '/index.php');
            exit;
        } else {
            $error = 'Username atau password salah';
        }
    } catch (Throwable $e) {
        $error = 'Terjadi kesalahan: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Login - Inventory</title>
<style>
  body {font-family: system-ui, Arial, sans-serif; background: #0f172a; color:#e2e8f0; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:24px}
  .box {background:#111827; padding:32px; border-radius:12px; width:360px; box-shadow:0 10px 30px rgba(0,0,0,.3)}
  h1 {font-size:22px; margin:0 0 16px}
  .field {margin-bottom:12px}
  label {display:block; margin-bottom:6px; font-size:13px; color:#94a3b8}
  input {width:100%; padding:10px 12px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb}
  button {width:100%; padding:10px 12px; border:none; border-radius:8px; background:#2563eb; color:white; font-weight:600; cursor:pointer}
  .error {background:#7f1d1d; color:#fecaca; padding:8px 10px; border-radius:8px; margin-bottom:12px; font-size:13px}
</style>
</head>
<body>
  <form class="box" method="post">
    <h1>Login Inventory</h1>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required />
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required />
    </div>
    <button type="submit">Masuk</button>
  </form>
</body>
</html>
