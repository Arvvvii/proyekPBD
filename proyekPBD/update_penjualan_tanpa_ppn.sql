-- Patch: Ubah perhitungan penjualan menjadi TANPA PPN
-- Date: 2025-11-10
-- DB: proyekpbd (MySQL 8)

DELIMITER $$

-- 1) Fungsi total penjualan hanya mengembalikan subtotal (tanpa PPN)
DROP FUNCTION IF EXISTS FN_HitungTotalPenjualan $$
CREATE FUNCTION FN_HitungTotalPenjualan (p_idpenjualan INT)
RETURNS INT
READS SQL DATA
BEGIN
    DECLARE v_subtotal INT;
    SELECT COALESCE(SUM(sub_total),0) INTO v_subtotal
    FROM detail_penjualan WHERE penjualan_idpenjualan = p_idpenjualan;
    RETURN v_subtotal; -- total = subtotal (TANPA PPN)
END $$

-- 2) SP Update header: set ppn=0 dan total=subtotal
DROP PROCEDURE IF EXISTS SP_UpdateHeaderPenjualan $$
CREATE PROCEDURE SP_UpdateHeaderPenjualan (IN p_idpenjualan INT)
BEGIN
    DECLARE v_subtotal INT;
    SELECT COALESCE(SUM(sub_total),0) INTO v_subtotal
    FROM detail_penjualan WHERE penjualan_idpenjualan = p_idpenjualan;

    UPDATE penjualan
    SET subtotal_nilai = v_subtotal,
        ppn = 0,
        total_nilai = v_subtotal
    WHERE idpenjualan = p_idpenjualan;
END $$

DELIMITER ;
