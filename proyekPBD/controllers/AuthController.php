<?php
require_once __DIR__ . '/../config/Database.php';

class AuthController {
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function login(string $username, string $password): bool {
        // Ambil user via view v_userall untuk juga mendapatkan role
        $sql = "SELECT u.iduser, u.username, u.password, r.nama_role FROM user u JOIN role r ON u.idrole = r.idrole WHERE u.username = ? LIMIT 1";
        $row = $this->db->fetch($sql, [$username]);
        if (!$row) { return false; }
        if (!password_verify($password, $row['password'])) { return false; }
        $_SESSION['user_id'] = $row['iduser'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role_name'] = $row['nama_role'];
        return true;
    }

    public function logout(): void {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
