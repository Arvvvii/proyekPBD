-- Patch: Definisi ulang SP_UpdateHargaJualBarang agar harga jual diset sama dengan harga beli (tanpa PPN)
-- Date: 2025-11-10
-- DB: proyekpbd (MySQL 8)

DELIMITER $$
DROP PROCEDURE IF EXISTS SP_UpdateHargaJualBarang $$
CREATE DEFINER=`root`@`localhost` PROCEDURE `SP_UpdateHargaJualBarang` (
    IN `p_idbarang` INT,
    IN `p_harga_beli_terbaru` INT
)
BEGIN
    -- Catatan:
    --  - Harga jual pada tabel `barang`.`harga` diset langsung = harga beli terbaru
    --  - Tidak menambahkan PPN di sini.
    --  - Jika kelak ingin memasukkan margin, lakukan penyesuaian terpisah (misal: via margin aktif).

    UPDATE barang
    SET harga = p_harga_beli_terbaru
    WHERE idbarang = p_idbarang;
END $$
DELIMITER ;

-- Opsional: varian dengan margin (tanpa PPN), jika dibutuhkan di masa depan
-- DELIMITER $$
-- DROP PROCEDURE IF EXISTS SP_UpdateHargaJualBarang_WithMargin $$
-- CREATE DEFINER=`root`@`localhost` PROCEDURE `SP_UpdateHargaJualBarang_WithMargin` (
--     IN `p_idbarang` INT,
--     IN `p_harga_beli_terbaru` INT,
--     IN `p_margin_persen` DOUBLE
-- )
-- BEGIN
--     DECLARE v_harga_jual INT;
--     SET v_harga_jual = ROUND(p_harga_beli_terbaru * (1 + (p_margin_persen/100)));
--     UPDATE barang SET harga = v_harga_jual WHERE idbarang = p_idbarang;
-- END $$
-- DELIMITER ;
