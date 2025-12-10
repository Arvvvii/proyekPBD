<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';

header('Content-Type: application/json');
try {
    if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
    $db = Database::getInstance();
    $idpengadaan = isset($_GET['idpengadaan']) ? (int)$_GET['idpengadaan'] : 0;
    if ($idpengadaan <= 0) { echo json_encode([]); exit; }

    // Aggregate received qty per barang for this pengadaan and include current barang.harga as latest price
    $sql = "SELECT dp.idbarang,
                   b.nama AS nama_barang,
                   SUM(COALESCE(dtp.jumlah_terima,0)) AS total_received_qty,
                   b.harga AS latest_harga,
                   MAX(prm.idpenerimaan) AS last_idpenerimaan
            FROM detail_pengadaan dp
            LEFT JOIN detail_penerimaan dtp ON dp.idbarang = dtp.barang_idbarang
            LEFT JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan AND prm.idpengadaan = dp.idpengadaan
            JOIN barang b ON dp.idbarang = b.idbarang
            WHERE dp.idpengadaan = ?
            GROUP BY dp.idbarang, b.nama, b.harga
            ORDER BY b.nama";
    $rows = $db->fetchAll($sql, [ $idpengadaan ]);
    echo json_encode($rows);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
