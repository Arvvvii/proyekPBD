<?php
// Simple migration runner to add `status` column to `penjualan` and recreate v_penjualanheader.
// Run from project root: php tools/migrate_add_status.php

require_once __DIR__ . '/../config/Database.php';

echo "Running migration: add status to penjualan\n";
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Check if column exists
    $row = $db->fetch("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penjualan' AND COLUMN_NAME = 'status'");
    $exists = (int)($row['cnt'] ?? 0) > 0;
    if ($exists) {
        echo "Column 'status' already exists on 'penjualan'.\n";
    } else {
        echo "Adding column 'status' to 'penjualan'...\n";
        $db->execute("ALTER TABLE `penjualan` ADD COLUMN `status` CHAR(1) NOT NULL DEFAULT 'A'");
        echo "Added.\n";
    }

    // Ensure rows have value
    $db->execute("UPDATE `penjualan` SET `status` = 'A' WHERE `status` IS NULL");

    // Recreate view
    echo "Recreating view v_penjualanheader...\n";
    $createView = <<<SQL
CREATE OR REPLACE VIEW `v_penjualanheader` AS
SELECT
  pj.`idpenjualan` AS `idpenjualan`,
  pj.`created_at` AS `tanggal_penjualan`,
  u.`username` AS `kasir`,
  mp.`persen` AS `margin_persen`,
  pj.`total_nilai` AS `total_nilai`,
  pj.`status` AS `status`
FROM `penjualan` pj
JOIN `user` u ON u.`iduser` = pj.`iduser`
JOIN `margin_penjualan` mp ON mp.`idmargin_penjualan` = pj.`idmargin_penjualan`;
SQL;
    $db->execute($createView);
    echo "View recreated.\n";

    echo "Migration completed.\n";
    echo "Verify by visiting penjualan_add.php and pressing finalisasi — status should be set to 'F' and header locked.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
