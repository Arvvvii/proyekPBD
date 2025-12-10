<?php
// Endpoint AJAX untuk memuat daftar barang yang masih outstanding (belum semua diterima) pada suatu pengadaan
// Mengembalikan JSON: [ { idbarang, nama_barang, ordered_qty, received_qty, outstanding_qty } ]
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
    $db = Database::getInstance();
    $idpengadaan = isset($_GET['idpengadaan']) ? (int)$_GET['idpengadaan'] : 0;
    if ($idpengadaan <= 0) { echo json_encode([]); exit; }

    // Query utama: ambil ordered qty, total received qty, dan outstanding
    $sql = "SELECT dp.idbarang,
                   b.nama AS nama_barang,
                   dp.jumlah AS ordered_qty,
                   dp.harga_satuan AS harga_satuan,
                   COALESCE( (
                     SELECT SUM(dtp.jumlah_terima)
                     FROM detail_penerimaan dtp
                     JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan
                     WHERE prm.idpengadaan = dp.idpengadaan AND dtp.barang_idbarang = dp.idbarang
                   ), 0) AS received_qty,
                   (dp.jumlah - COALESCE( (
                     SELECT SUM(dtp2.jumlah_terima)
                     FROM detail_penerimaan dtp2
                     JOIN penerimaan prm2 ON dtp2.idpenerimaan = prm2.idpenerimaan
                     WHERE prm2.idpengadaan = dp.idpengadaan AND dtp2.barang_idbarang = dp.idbarang
                   ), 0)) AS outstanding_qty
            FROM detail_pengadaan dp
            JOIN barang b ON b.idbarang = dp.idbarang
            WHERE dp.idpengadaan = ?
            HAVING outstanding_qty > 0
            ORDER BY b.nama";
    $rows = $db->fetchAll($sql, [ $idpengadaan ]);
    echo json_encode($rows);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}
