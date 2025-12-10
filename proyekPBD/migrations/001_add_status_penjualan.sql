-- Migration: add status column to penjualan and update v_penjualanheader view
-- Run this file against your database (e.g., via mysql CLI or phpMyAdmin)

ALTER TABLE `penjualan`
  ADD COLUMN IF NOT EXISTS `status` CHAR(1) NOT NULL DEFAULT 'A';

-- Ensure existing rows have a status
UPDATE `penjualan` SET `status` = 'A' WHERE `status` IS NULL;

-- Recreate view to include status column
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

-- End of migration
