-- Patch: Aktifkan kembali perhitungan PPN pada penjualan
-- Date: 2025-11-10
-- DB: proyekpbd (MySQL 8)

DELIMITER $$

-- 1) Fungsi total penjualan menghitung subtotal, ppn dan total
DROP FUNCTION IF EXISTS FN_HitungTotalPenjualan $$
CREATE FUNCTION FN_HitungTotalPenjualan (p_idpenjualan INT)
RETURNS DECIMAL(18,2)
READS SQL DATA
BEGIN
    DECLARE v_subtotal DECIMAL(18,2) DEFAULT 0;
    DECLARE v_ppn DECIMAL(18,2) DEFAULT 0;
    DECLARE v_total DECIMAL(18,2) DEFAULT 0;
    SELECT COALESCE(SUM(sub_total),0) INTO v_subtotal
    FROM detail_penjualan WHERE penjualan_idpenjualan = p_idpenjualan;
    -- Gunakan nilai PPN 10% sebagai default; jika ingin lain, edit SP berikutnya
    SET v_ppn = ROUND(v_subtotal * 0.10, 0);
    SET v_total = v_subtotal + v_ppn;
    RETURN v_total;
END $$

-- 2) SP Update header: set subtotal, ppn dan total
DROP PROCEDURE IF EXISTS SP_UpdateHeaderPenjualan $$
CREATE PROCEDURE SP_UpdateHeaderPenjualan (IN p_idpenjualan INT)
BEGIN
    DECLARE v_subtotal DECIMAL(18,2) DEFAULT 0;
    DECLARE v_ppn DECIMAL(18,2) DEFAULT 0;
    DECLARE v_total DECIMAL(18,2) DEFAULT 0;
    SELECT COALESCE(SUM(sub_total),0) INTO v_subtotal
    FROM detail_penjualan WHERE penjualan_idpenjualan = p_idpenjualan;

    SET v_ppn = ROUND(v_subtotal * 0.10, 0);
    SET v_total = v_subtotal + v_ppn;

    UPDATE penjualan
    SET subtotal_nilai = v_subtotal,
        ppn = v_ppn,
        total_nilai = v_total
    WHERE idpenjualan = p_idpenjualan;
END $$

DELIMITER ;

-- Note: run this patch on the database where the `penjualan` table and `detail_penjualan` exist.
-- If your installation uses a different decimal/rounding policy, adjust the ROUND() and precision.
