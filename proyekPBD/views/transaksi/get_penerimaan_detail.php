<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/AppConfig.php';

header('Content-Type: application/json');
try {
    if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
    $db = Database::getInstance();
    $idpenerimaan = isset($_GET['idpenerimaan']) ? (int)$_GET['idpenerimaan'] : 0;
    if ($idpenerimaan <= 0) { echo json_encode([]); exit; }

        // Get rows: read directly from detail_penerimaan and join barang so we have barang_idbarang available
        $sql = "SELECT dtp.iddetail_penerimaan, dtp.idpenerimaan, dtp.barang_idbarang AS idbarang, b.nama AS nama_barang, dtp.jumlah_terima, dtp.harga_satuan_terima, dtp.sub_total_terima
            FROM detail_penerimaan dtp
            LEFT JOIN barang b ON dtp.barang_idbarang = b.idbarang
            WHERE dtp.idpenerimaan = ?
            ORDER BY dtp.iddetail_penerimaan";
        $rows = $db->fetchAll($sql, [ $idpenerimaan ]);
    // Compute totals excluding rows with jumlah_terima = 0
    $sumRow = $db->fetch("SELECT COALESCE(SUM(CASE WHEN jumlah_terima > 0 THEN jumlah_terima * harga_satuan_terima ELSE 0 END),0) AS subtotal FROM v_detailpenerimaanlengkap WHERE idpenerimaan = ?", [$idpenerimaan]);
    $subtotal = (int)($sumRow['subtotal'] ?? 0);
    $ppn = (int)round($subtotal * 0.11);
    $total = $subtotal + $ppn;
        // Also return whether the header is finalized (status = 'F') so client can disable actions
        $hdr = $db->fetch('SELECT status FROM penerimaan WHERE idpenerimaan = ? LIMIT 1', [$idpenerimaan]);
        $isFinal = (isset($hdr['status']) && strtoupper(trim($hdr['status'])) === 'F');
        echo json_encode(['rows'=>$rows,'totals'=>['subtotal'=>$subtotal,'ppn'=>$ppn,'total'=>$total],'finalized'=>$isFinal]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
