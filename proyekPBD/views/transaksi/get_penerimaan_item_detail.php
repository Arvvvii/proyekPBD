<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';

header('Content-Type: application/json');
try {
    if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
    $db = Database::getInstance();
    $idpengadaan = isset($_GET['idpengadaan']) ? (int)$_GET['idpengadaan'] : 0;
    $idbarang = isset($_GET['idbarang']) ? (int)$_GET['idbarang'] : 0;
    if ($idpengadaan <= 0 || $idbarang <= 0) { echo json_encode([]); exit; }

    // Return all penerimaan lines for this barang under the given pengadaan
    $sql = "SELECT dtp.iddetail_penerimaan, prm.idpenerimaan, prm.tanggal_terima, dtp.jumlah_terima, dtp.harga_satuan_terima, dtp.sub_total_terima, b.harga AS latest_harga
            FROM detail_penerimaan dtp
            JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan
            JOIN barang b ON dtp.barang_idbarang = b.idbarang
            WHERE prm.idpengadaan = ? AND dtp.barang_idbarang = ?
            ORDER BY prm.tanggal_terima DESC, dtp.iddetail_penerimaan DESC";
    $rows = $db->fetchAll($sql, [ $idpengadaan, $idbarang ]);
    echo json_encode($rows);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
