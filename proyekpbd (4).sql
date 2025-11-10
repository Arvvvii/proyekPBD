-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 09, 2025 at 03:14 PM
-- Server version: 8.0.42
-- PHP Version: 8.2.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `proyekpbd`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `SP_GetStokSummary` ()   BEGIN
    -- Query ini mengambil saldo stok terakhir (tertinggi idkartu_stok) untuk setiap barang
    SELECT 
        ks.idbarang,
        b.nama AS nama_barang,
        s.nama_satuan,
        ks.stock AS stok_saat_ini,
        ks.created_at AS terakhir_diupdate
    FROM 
        kartu_stok ks
    JOIN 
        barang b ON ks.idbarang = b.idbarang
    JOIN 
        satuan s ON b.idsatuan = s.idsatuan
    WHERE 
        -- Subquery untuk mencari ID entri stok terbaru untuk setiap barang
        ks.idkartu_stok = (
            SELECT MAX(idkartu_stok) 
            FROM kartu_stok 
            WHERE idbarang = ks.idbarang
        )
    ORDER BY
        b.nama;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_insert_penerimaan_lengkap` (IN `p_idpengadaan` INT, IN `p_iduser_penerima` INT, IN `p_status_penerimaan` CHAR(1), IN `p_barang_idbarang` INT, IN `p_jumlah_terima` INT, IN `p_harga_satuan_terima` INT)   BEGIN
    DECLARE v_idpenerimaan INT;
    DECLARE v_sub_total_terima INT;
    SET v_sub_total_terima = p_jumlah_terima * p_harga_satuan_terima;

    INSERT INTO penerimaan (status, idpengadaan, iduser)
    VALUES (p_status_penerimaan, p_idpengadaan, p_iduser_penerima);
    SET v_idpenerimaan = LAST_INSERT_ID();

    INSERT INTO detail_penerimaan (idpenerimaan, barang_idbarang, jumlah_terima, harga_satuan_terima, sub_total_terima)
    VALUES (v_idpenerimaan, p_barang_idbarang, p_jumlah_terima, p_harga_satuan_terima, v_sub_total_terima);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_insert_pengadaan_lengkap` (IN `p_user_iduser` INT, IN `p_vendor_idvendor` INT, IN `p_status` CHAR(1), IN `p_subtotal_nilai` INT, IN `p_ppn` INT, IN `p_total_nilai` INT, IN `p_idbarang` INT, IN `p_jumlah_pesan` INT, IN `p_harga_satuan` INT)   BEGIN
    DECLARE v_idpengadaan INT;
    DECLARE v_sub_total_detail INT;
    SET v_sub_total_detail = p_jumlah_pesan * p_harga_satuan;

    -- Logika: Selalu INSERT Header baru karena bug multi-user
    INSERT INTO pengadaan (user_iduser, status, vendor_idvendor, subtotal_nilai, ppn, total_nilai) 
    VALUES (p_user_iduser, p_status, p_vendor_idvendor, p_subtotal_nilai, p_ppn, p_total_nilai);
    SET v_idpengadaan = LAST_INSERT_ID();

    INSERT INTO detail_pengadaan (idpengadaan, idbarang, jumlah, harga_satuan, sub_total)
    VALUES (v_idpengadaan, p_idbarang, p_jumlah_pesan, p_harga_satuan, v_sub_total_detail);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_insert_penjualan_lengkap` (IN `p_iduser_kasir` INT, IN `p_idmargin_penjualan` INT, IN `p_idbarang` INT, IN `p_jumlah_jual` INT, IN `p_harga_satuan_jual` INT)   BEGIN
    DECLARE v_idpenjualan INT;
    DECLARE v_sub_total_detail INT;
    SET v_sub_total_detail = p_jumlah_jual * p_harga_satuan_jual;

    -- Insert header hanya dengan data minimal (total=0)
    INSERT INTO penjualan (iduser, idmargin_penjualan) 
    VALUES (p_iduser_kasir, p_idmargin_penjualan);
    SET v_idpenjualan = LAST_INSERT_ID();

    INSERT INTO detail_penjualan (penjualan_idpenjualan, idbarang, jumlah, harga_satuan, sub_total)
    VALUES (v_idpenjualan, p_idbarang, p_jumlah_jual, p_harga_satuan_jual, v_sub_total_detail);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `SP_UpdateHeaderPenjualan` (IN `p_idpenjualan` INT)   BEGIN
    DECLARE v_total INT;
    DECLARE v_subtotal INT;
    DECLARE v_ppn_nilai INT;

    -- 1. Panggil FN untuk mendapatkan total akhir
    SET v_total = FN_HitungTotalPenjualan(p_idpenjualan);

    -- 2. Dapatkan subtotal dan PPN (hitung ulang)
    SELECT SUM(sub_total) INTO v_subtotal
    FROM detail_penjualan WHERE penjualan_idpenjualan = p_idpenjualan;
    SET v_ppn_nilai = ROUND(v_subtotal * 0.11);
    
    -- 3. UPDATE Header Penjualan
    UPDATE penjualan
    SET subtotal_nilai = v_subtotal, ppn = v_ppn_nilai, total_nilai = v_total
    WHERE idpenjualan = p_idpenjualan;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `FN_AmbilHargaBeliTerakhir` (`p_idbarang` INT) RETURNS INT READS SQL DATA BEGIN
    DECLARE v_harga_beli INT;
    SELECT harga_satuan_terima INTO v_harga_beli FROM detail_penerimaan dtp
    JOIN penerimaan prm ON dtp.idpenerimaan = prm.idpenerimaan
    WHERE dtp.barang_idbarang = p_idbarang ORDER BY prm.created_at DESC LIMIT 1;
    IF v_harga_beli IS NULL THEN SET v_harga_beli = 0; END IF;
    RETURN v_harga_beli;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `FN_CekStok` (`p_idbarang` INT) RETURNS INT READS SQL DATA BEGIN
    DECLARE v_stok_saat_ini INT;
    SELECT stock INTO v_stok_saat_ini FROM kartu_stok 
    WHERE idbarang = p_idbarang ORDER BY idkartu_stok DESC LIMIT 1;
    IF v_stok_saat_ini IS NULL THEN SET v_stok_saat_ini = 0; END IF;
    RETURN v_stok_saat_ini;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `FN_HitungSubtotalDetail` (`p_harga` INT, `p_jumlah` INT) RETURNS INT DETERMINISTIC BEGIN
    RETURN p_harga * p_jumlah;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `FN_HitungTotalPenjualan` (`p_idpenjualan` INT) RETURNS INT READS SQL DATA BEGIN
    DECLARE v_subtotal INT;
    DECLARE v_ppn_nilai INT;
    DECLARE v_total INT;

    SELECT SUM(sub_total) INTO v_subtotal
    FROM detail_penjualan WHERE penjualan_idpenjualan = p_idpenjualan;
    
    IF v_subtotal IS NULL THEN SET v_subtotal = 0; END IF;
    SET v_ppn_nilai = ROUND(v_subtotal * 0.11); 
    SET v_total = v_subtotal + v_ppn_nilai;
    
    RETURN v_total; -- Hanya mengembalikan nilai
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `barang`
--

CREATE TABLE `barang` (
  `idbarang` int NOT NULL,
  `jenis` char(1) DEFAULT NULL,
  `nama` varchar(45) DEFAULT NULL,
  `idsatuan` int DEFAULT NULL,
  `status` tinyint(1) DEFAULT NULL,
  `harga` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `barang`
--

INSERT INTO `barang` (`idbarang`, `jenis`, `nama`, `idsatuan`, `status`, `harga`) VALUES
(1, 'B', 'Beras Premium', 2, 1, 13000),
(2, 'B', 'Minyak Goreng', 3, 1, 18000),
(3, 'P', 'Sabun Mandi', 1, 1, 5000),
(4, 'P', 'Shampo Botol', 1, 1, 25000),
(5, 'B', 'Gula Pasir', 2, 1, 14000);

-- --------------------------------------------------------

--
-- Table structure for table `detail_penerimaan`
--

CREATE TABLE `detail_penerimaan` (
  `iddetail_penerimaan` int NOT NULL,
  `idpenerimaan` int DEFAULT NULL,
  `barang_idbarang` int DEFAULT NULL,
  `jumlah_terima` int DEFAULT NULL,
  `harga_satuan_terima` int DEFAULT NULL,
  `sub_total_terima` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `detail_penerimaan`
--

INSERT INTO `detail_penerimaan` (`iddetail_penerimaan`, `idpenerimaan`, `barang_idbarang`, `jumlah_terima`, `harga_satuan_terima`, `sub_total_terima`) VALUES
(3, 3, 1, 5, 11000, 55000),
(4, 4, 1, 5, 11000, 55000);

--
-- Triggers `detail_penerimaan`
--
DELIMITER $$
CREATE TRIGGER `TR_AfterInsert_DetailPenerimaan` AFTER INSERT ON `detail_penerimaan` FOR EACH ROW BEGIN
    DECLARE v_stok_lama INT;

    SELECT stock INTO v_stok_lama
    FROM kartu_stok WHERE idbarang = NEW.barang_idbarang ORDER BY idkartu_stok DESC LIMIT 1;
    
    IF v_stok_lama IS NULL THEN SET v_stok_lama = 0; END IF;

    INSERT INTO kartu_stok (jenis_transaksi, masuk, keluar, stock, idtransaksi, idbarang)
    VALUES ('P', NEW.jumlah_terima, 0, v_stok_lama + NEW.jumlah_terima, NEW.idpenerimaan, NEW.barang_idbarang);

END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `detail_pengadaan`
--

CREATE TABLE `detail_pengadaan` (
  `iddetail_pengadaan` int NOT NULL,
  `harga_satuan` int DEFAULT NULL,
  `jumlah` int DEFAULT NULL,
  `sub_total` int DEFAULT NULL,
  `idbarang` int DEFAULT NULL,
  `idpengadaan` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `detail_pengadaan`
--

INSERT INTO `detail_pengadaan` (`iddetail_pengadaan`, `harga_satuan`, `jumlah`, `sub_total`, `idbarang`, `idpengadaan`) VALUES
(4, 15000, 10, 150000, 1, 5),
(5, 4000, 50, 200000, 3, 7);

-- --------------------------------------------------------

--
-- Table structure for table `detail_penjualan`
--

CREATE TABLE `detail_penjualan` (
  `iddetail_penjualan` int NOT NULL,
  `harga_satuan` int DEFAULT NULL,
  `jumlah` int DEFAULT NULL,
  `sub_total` int DEFAULT NULL,
  `penjualan_idpenjualan` int DEFAULT NULL,
  `idbarang` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `detail_penjualan`
--

INSERT INTO `detail_penjualan` (`iddetail_penjualan`, `harga_satuan`, `jumlah`, `sub_total`, `penjualan_idpenjualan`, `idbarang`) VALUES
(1, 8000, 4, 32000, 1, 4),
(2, 15000, 5, 75000, 3, 1),
(3, 16000, 10, 160000, 4, 1);

--
-- Triggers `detail_penjualan`
--
DELIMITER $$
CREATE TRIGGER `TR_AfterInsert_DetailPenjualan` AFTER INSERT ON `detail_penjualan` FOR EACH ROW BEGIN
    DECLARE v_stok_lama INT;

    SELECT stock INTO v_stok_lama
    FROM kartu_stok WHERE idbarang = NEW.idbarang ORDER BY idkartu_stok DESC LIMIT 1;

    IF v_stok_lama IS NULL THEN SET v_stok_lama = 0; END IF;

    -- VALIDASI KRITIS
    IF v_stok_lama < NEW.jumlah THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stok tidak cukup untuk transaksi penjualan ini.';
    END IF;

    INSERT INTO kartu_stok (jenis_transaksi, masuk, keluar, stock, idtransaksi, idbarang)
    VALUES ('J', 0, NEW.jumlah, v_stok_lama - NEW.jumlah, NEW.penjualan_idpenjualan, NEW.idbarang);

END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `detail_retur`
--

CREATE TABLE `detail_retur` (
  `iddetail_retur` int NOT NULL,
  `jumlah` int DEFAULT NULL,
  `alasan` varchar(200) DEFAULT NULL,
  `idretur` int DEFAULT NULL,
  `iddetail_penerimaan` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kartu_stok`
--

CREATE TABLE `kartu_stok` (
  `idkartu_stok` int NOT NULL,
  `jenis_transaksi` char(1) DEFAULT NULL,
  `masuk` int DEFAULT NULL,
  `keluar` int DEFAULT NULL,
  `stock` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `idtransaksi` int DEFAULT NULL,
  `idbarang` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `kartu_stok`
--

INSERT INTO `kartu_stok` (`idkartu_stok`, `jenis_transaksi`, `masuk`, `keluar`, `stock`, `created_at`, `idtransaksi`, `idbarang`) VALUES
(1, 'P', 5, 0, 5, '2025-11-05 09:25:59', 3, 1),
(2, 'P', 5, 0, 10, '2025-11-05 09:26:48', 4, 1);

-- --------------------------------------------------------

--
-- Table structure for table `margin_penjualan`
--

CREATE TABLE `margin_penjualan` (
  `idmargin_penjualan` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `persen` double DEFAULT NULL,
  `status` tinyint(1) DEFAULT NULL,
  `iduser` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `penerimaan`
--

CREATE TABLE `penerimaan` (
  `idpenerimaan` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` char(1) DEFAULT NULL,
  `idpengadaan` int DEFAULT NULL,
  `iduser` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `penerimaan`
--

INSERT INTO `penerimaan` (`idpenerimaan`, `created_at`, `status`, `idpengadaan`, `iduser`) VALUES
(3, '2025-11-05 09:25:59', 'A', 5, 1),
(4, '2025-11-05 09:26:48', 'A', 5, 1);

-- --------------------------------------------------------

--
-- Table structure for table `pengadaan`
--

CREATE TABLE `pengadaan` (
  `idpengadaan` int NOT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `user_iduser` int DEFAULT NULL,
  `status` char(1) DEFAULT NULL,
  `vendor_idvendor` int DEFAULT NULL,
  `subtotal_nilai` int DEFAULT NULL,
  `ppn` int DEFAULT NULL,
  `total_nilai` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pengadaan`
--

INSERT INTO `pengadaan` (`idpengadaan`, `timestamp`, `user_iduser`, `status`, `vendor_idvendor`, `subtotal_nilai`, `ppn`, `total_nilai`) VALUES
(5, '2025-11-05 09:24:30', 1, 'P', 2, NULL, NULL, NULL),
(6, '2025-11-05 10:15:06', 1, 'P', 5, NULL, NULL, NULL),
(7, '2025-11-09 14:35:41', 1, 'A', 4, 200000, 22000, 222000);

-- --------------------------------------------------------

--
-- Table structure for table `penjualan`
--

CREATE TABLE `penjualan` (
  `idpenjualan` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal_nilai` int DEFAULT NULL,
  `ppn` int DEFAULT NULL,
  `total_nilai` int DEFAULT NULL,
  `iduser` int DEFAULT NULL,
  `idmargin_penjualan` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `penjualan`
--

INSERT INTO `penjualan` (`idpenjualan`, `created_at`, `subtotal_nilai`, `ppn`, `total_nilai`, `iduser`, `idmargin_penjualan`) VALUES
(1, '2025-11-02 12:03:51', 0, 0, 0, 1, NULL),
(3, '2025-11-04 08:08:52', 75000, 8250, 83250, 1, NULL),
(4, '2025-11-04 08:13:16', 160000, 17600, 177600, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `retur_barang`
--

CREATE TABLE `retur_barang` (
  `idretur` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `idpenerimaan` int DEFAULT NULL,
  `iduser` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `idrole` int NOT NULL,
  `nama_role` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `role`
--

INSERT INTO `role` (`idrole`, `nama_role`) VALUES
(1, 'super admin'),
(2, 'administrator');

-- --------------------------------------------------------

--
-- Table structure for table `satuan`
--

CREATE TABLE `satuan` (
  `idsatuan` int NOT NULL,
  `nama_satuan` varchar(45) DEFAULT NULL,
  `status` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `satuan`
--

INSERT INTO `satuan` (`idsatuan`, `nama_satuan`, `status`) VALUES
(1, 'Pcs', 1),
(2, 'Kg', 1),
(3, 'Liter', 1),
(4, 'Dus', 1),
(5, 'Pack', 1);

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `iduser` int NOT NULL,
  `username` varchar(45) DEFAULT NULL,
  `password` varchar(100) DEFAULT NULL,
  `idrole` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`iduser`, `username`, `password`, `idrole`) VALUES
(1, 'superadmin_arvi', '$2y$10$QGr3Kuz9t6LQ70ZZ.eTtL.HaMgAvK7g1.3H68KBtLop93p3upHq3W', 1),
(2, 'administrator_adel', '$2y$10$OlajffJeRGuB/oUrn.gHz.QSIamFtVa8M1hbzKQcSkbTOmDkSOWSO', 2);

-- --------------------------------------------------------

--
-- Table structure for table `vendor`
--

CREATE TABLE `vendor` (
  `idvendor` int NOT NULL,
  `nama_vendor` varchar(100) DEFAULT NULL,
  `badan_hukum` char(1) DEFAULT NULL,
  `status` char(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vendor`
--

INSERT INTO `vendor` (`idvendor`, `nama_vendor`, `badan_hukum`, `status`) VALUES
(1, 'PT. Sinar Makmur Sejahtera', '1', '1'),
(2, 'CV. Anugerah Abadi', '2', '1'),
(3, 'PT. Tirta Mandiri Jaya', '1', '1'),
(4, 'UD. Berkah Bersama', '3', '1'),
(5, 'CV. Gemilang Sentosa', '2', '1');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_barangaktif`
-- (See below for the actual view)
--
CREATE TABLE `v_barangaktif` (
`harga` int
,`idbarang` int
,`idsatuan` int
,`jenis` char(1)
,`nama_barang` varchar(45)
,`nama_satuan` varchar(45)
,`status` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_barangall`
-- (See below for the actual view)
--
CREATE TABLE `v_barangall` (
`harga` int
,`idbarang` int
,`idsatuan` int
,`jenis` char(1)
,`nama_barang` varchar(45)
,`nama_satuan` varchar(45)
,`status` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_detailpenerimaanlengkap`
-- (See below for the actual view)
--
CREATE TABLE `v_detailpenerimaanlengkap` (
`harga_satuan_terima` int
,`iddetail_penerimaan` int
,`idpenerimaan` int
,`jumlah_terima` int
,`nama_barang` varchar(45)
,`sub_total_terima` int
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_detailpengadaanlengkap`
-- (See below for the actual view)
--
CREATE TABLE `v_detailpengadaanlengkap` (
`harga_satuan` int
,`iddetail_pengadaan` int
,`idpengadaan` int
,`jumlah_pesan` int
,`nama_barang` varchar(45)
,`nama_satuan` varchar(45)
,`sub_total` int
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_detailpenjualanlengkap`
-- (See below for the actual view)
--
CREATE TABLE `v_detailpenjualanlengkap` (
`harga_satuan` int
,`iddetail_penjualan` int
,`idpenjualan` int
,`jumlah` int
,`nama_barang` varchar(45)
,`sub_total` int
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_marginaktif`
-- (See below for the actual view)
--
CREATE TABLE `v_marginaktif` (
`created_at` timestamp
,`ditetapkan_oleh` varchar(45)
,`idmargin_penjualan` int
,`persen` double
,`status` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_marginall`
-- (See below for the actual view)
--
CREATE TABLE `v_marginall` (
`created_at` timestamp
,`ditetapkan_oleh` varchar(45)
,`idmargin_penjualan` int
,`iduser` int
,`persen` double
,`status` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_penerimaanheader`
-- (See below for the actual view)
--
CREATE TABLE `v_penerimaanheader` (
`diterima_oleh` varchar(45)
,`idpenerimaan` int
,`idpengadaan` int
,`nama_vendor` varchar(100)
,`tanggal_terima` timestamp
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_pengadaanheader`
-- (See below for the actual view)
--
CREATE TABLE `v_pengadaanheader` (
`dibuat_oleh` varchar(45)
,`idpengadaan` int
,`nama_vendor` varchar(100)
,`status` char(1)
,`tanggal_pengadaan` timestamp
,`total_nilai` int
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_penjualanheader`
-- (See below for the actual view)
--
CREATE TABLE `v_penjualanheader` (
`idpenjualan` int
,`kasir` varchar(45)
,`margin_persen` double
,`tanggal_penjualan` timestamp
,`total_nilai` int
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_roleall`
-- (See below for the actual view)
--
CREATE TABLE `v_roleall` (
`idrole` int
,`nama_role` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_satuanaktif`
-- (See below for the actual view)
--
CREATE TABLE `v_satuanaktif` (
`idsatuan` int
,`nama_satuan` varchar(45)
,`status` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_satuanall`
-- (See below for the actual view)
--
CREATE TABLE `v_satuanall` (
`idsatuan` int
,`nama_satuan` varchar(45)
,`status` tinyint(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_stoksummary`
-- (See below for the actual view)
--
CREATE TABLE `v_stoksummary` (
`idbarang` int
,`nama_barang` varchar(45)
,`stok_saat_ini` int
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_userall`
-- (See below for the actual view)
--
CREATE TABLE `v_userall` (
`idrole` int
,`iduser` int
,`nama_role` varchar(100)
,`username` varchar(45)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_vendoraktif`
-- (See below for the actual view)
--
CREATE TABLE `v_vendoraktif` (
`badan_hukum` char(1)
,`idvendor` int
,`nama_vendor` varchar(100)
,`status` char(1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_vendorall`
-- (See below for the actual view)
--
CREATE TABLE `v_vendorall` (
`badan_hukum` char(1)
,`idvendor` int
,`nama_vendor` varchar(100)
,`status` char(1)
);

-- --------------------------------------------------------

--
-- Structure for view `v_barangaktif`
--
DROP TABLE IF EXISTS `v_barangaktif`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_barangaktif`  AS SELECT `v_barangall`.`idbarang` AS `idbarang`, `v_barangall`.`jenis` AS `jenis`, `v_barangall`.`nama_barang` AS `nama_barang`, `v_barangall`.`harga` AS `harga`, `v_barangall`.`idsatuan` AS `idsatuan`, `v_barangall`.`nama_satuan` AS `nama_satuan`, `v_barangall`.`status` AS `status` FROM `v_barangall` WHERE (`v_barangall`.`status` = 1)  ;

-- --------------------------------------------------------

--
-- Structure for view `v_barangall`
--
DROP TABLE IF EXISTS `v_barangall`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_barangall`  AS SELECT `b`.`idbarang` AS `idbarang`, `b`.`jenis` AS `jenis`, `b`.`nama` AS `nama_barang`, `b`.`harga` AS `harga`, `b`.`idsatuan` AS `idsatuan`, `s`.`nama_satuan` AS `nama_satuan`, `b`.`status` AS `status` FROM (`barang` `b` join `satuan` `s` on((`b`.`idsatuan` = `s`.`idsatuan`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_detailpenerimaanlengkap`
--
DROP TABLE IF EXISTS `v_detailpenerimaanlengkap`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_detailpenerimaanlengkap`  AS SELECT `dtp`.`iddetail_penerimaan` AS `iddetail_penerimaan`, `dtp`.`idpenerimaan` AS `idpenerimaan`, `b`.`nama` AS `nama_barang`, `dtp`.`jumlah_terima` AS `jumlah_terima`, `dtp`.`harga_satuan_terima` AS `harga_satuan_terima`, `dtp`.`sub_total_terima` AS `sub_total_terima` FROM (`detail_penerimaan` `dtp` join `barang` `b` on((`dtp`.`barang_idbarang` = `b`.`idbarang`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_detailpengadaanlengkap`
--
DROP TABLE IF EXISTS `v_detailpengadaanlengkap`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_detailpengadaanlengkap`  AS SELECT `dp`.`iddetail_pengadaan` AS `iddetail_pengadaan`, `dp`.`idpengadaan` AS `idpengadaan`, `b`.`nama` AS `nama_barang`, `s`.`nama_satuan` AS `nama_satuan`, `dp`.`jumlah` AS `jumlah_pesan`, `dp`.`harga_satuan` AS `harga_satuan`, `dp`.`sub_total` AS `sub_total` FROM ((`detail_pengadaan` `dp` join `barang` `b` on((`dp`.`idbarang` = `b`.`idbarang`))) join `satuan` `s` on((`b`.`idsatuan` = `s`.`idsatuan`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_detailpenjualanlengkap`
--
DROP TABLE IF EXISTS `v_detailpenjualanlengkap`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_detailpenjualanlengkap`  AS SELECT `dtj`.`iddetail_penjualan` AS `iddetail_penjualan`, `dtj`.`penjualan_idpenjualan` AS `idpenjualan`, `b`.`nama` AS `nama_barang`, `dtj`.`jumlah` AS `jumlah`, `dtj`.`harga_satuan` AS `harga_satuan`, `dtj`.`sub_total` AS `sub_total` FROM (`detail_penjualan` `dtj` join `barang` `b` on((`dtj`.`idbarang` = `b`.`idbarang`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_marginaktif`
--
DROP TABLE IF EXISTS `v_marginaktif`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_marginaktif`  AS SELECT `v_marginall`.`idmargin_penjualan` AS `idmargin_penjualan`, `v_marginall`.`persen` AS `persen`, `v_marginall`.`status` AS `status`, `v_marginall`.`created_at` AS `created_at`, `v_marginall`.`ditetapkan_oleh` AS `ditetapkan_oleh` FROM `v_marginall` WHERE (`v_marginall`.`status` = 1)  ;

-- --------------------------------------------------------

--
-- Structure for view `v_marginall`
--
DROP TABLE IF EXISTS `v_marginall`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_marginall`  AS SELECT `mp`.`idmargin_penjualan` AS `idmargin_penjualan`, `mp`.`persen` AS `persen`, `mp`.`status` AS `status`, `mp`.`iduser` AS `iduser`, `u`.`username` AS `ditetapkan_oleh`, `mp`.`created_at` AS `created_at` FROM (`margin_penjualan` `mp` join `user` `u` on((`mp`.`iduser` = `u`.`iduser`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_penerimaanheader`
--
DROP TABLE IF EXISTS `v_penerimaanheader`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_penerimaanheader`  AS SELECT `p`.`idpenerimaan` AS `idpenerimaan`, `p`.`created_at` AS `tanggal_terima`, `u`.`username` AS `diterima_oleh`, `p`.`idpengadaan` AS `idpengadaan`, `v`.`nama_vendor` AS `nama_vendor` FROM (((`penerimaan` `p` join `user` `u` on((`p`.`iduser` = `u`.`iduser`))) join `pengadaan` `pd` on((`p`.`idpengadaan` = `pd`.`idpengadaan`))) join `vendor` `v` on((`pd`.`vendor_idvendor` = `v`.`idvendor`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_pengadaanheader`
--
DROP TABLE IF EXISTS `v_pengadaanheader`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_pengadaanheader`  AS SELECT `p`.`idpengadaan` AS `idpengadaan`, `p`.`timestamp` AS `tanggal_pengadaan`, `v`.`nama_vendor` AS `nama_vendor`, `u`.`username` AS `dibuat_oleh`, `p`.`total_nilai` AS `total_nilai`, `p`.`status` AS `status` FROM ((`pengadaan` `p` join `vendor` `v` on((`p`.`vendor_idvendor` = `v`.`idvendor`))) join `user` `u` on((`p`.`user_iduser` = `u`.`iduser`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_penjualanheader`
--
DROP TABLE IF EXISTS `v_penjualanheader`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_penjualanheader`  AS SELECT `pj`.`idpenjualan` AS `idpenjualan`, `pj`.`created_at` AS `tanggal_penjualan`, `u`.`username` AS `kasir`, `mp`.`persen` AS `margin_persen`, `pj`.`total_nilai` AS `total_nilai` FROM ((`penjualan` `pj` join `user` `u` on((`pj`.`iduser` = `u`.`iduser`))) join `margin_penjualan` `mp` on((`pj`.`idmargin_penjualan` = `mp`.`idmargin_penjualan`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_roleall`
--
DROP TABLE IF EXISTS `v_roleall`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_roleall`  AS SELECT `role`.`idrole` AS `idrole`, `role`.`nama_role` AS `nama_role` FROM `role``role`  ;

-- --------------------------------------------------------

--
-- Structure for view `v_satuanaktif`
--
DROP TABLE IF EXISTS `v_satuanaktif`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_satuanaktif`  AS SELECT `v_satuanall`.`idsatuan` AS `idsatuan`, `v_satuanall`.`nama_satuan` AS `nama_satuan`, `v_satuanall`.`status` AS `status` FROM `v_satuanall` WHERE (`v_satuanall`.`status` = 1)  ;

-- --------------------------------------------------------

--
-- Structure for view `v_satuanall`
--
DROP TABLE IF EXISTS `v_satuanall`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_satuanall`  AS SELECT `satuan`.`idsatuan` AS `idsatuan`, `satuan`.`nama_satuan` AS `nama_satuan`, `satuan`.`status` AS `status` FROM `satuan``satuan`  ;

-- --------------------------------------------------------

--
-- Structure for view `v_stoksummary`
--
DROP TABLE IF EXISTS `v_stoksummary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_stoksummary`  AS SELECT `ks`.`idbarang` AS `idbarang`, `b`.`nama` AS `nama_barang`, `ks`.`stock` AS `stok_saat_ini` FROM (`kartu_stok` `ks` join `barang` `b` on((`ks`.`idbarang` = `b`.`idbarang`))) WHERE (`ks`.`idkartu_stok` = (select max(`kartu_stok`.`idkartu_stok`) from `kartu_stok` where (`kartu_stok`.`idbarang` = `ks`.`idbarang`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_userall`
--
DROP TABLE IF EXISTS `v_userall`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_userall`  AS SELECT `u`.`iduser` AS `iduser`, `u`.`username` AS `username`, `u`.`idrole` AS `idrole`, `r`.`nama_role` AS `nama_role` FROM (`user` `u` join `role` `r` on((`u`.`idrole` = `r`.`idrole`)))  ;

-- --------------------------------------------------------

--
-- Structure for view `v_vendoraktif`
--
DROP TABLE IF EXISTS `v_vendoraktif`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_vendoraktif`  AS SELECT `v_vendorall`.`idvendor` AS `idvendor`, `v_vendorall`.`nama_vendor` AS `nama_vendor`, `v_vendorall`.`badan_hukum` AS `badan_hukum`, `v_vendorall`.`status` AS `status` FROM `v_vendorall` WHERE (`v_vendorall`.`status` = '1')  ;

-- --------------------------------------------------------

--
-- Structure for view `v_vendorall`
--
DROP TABLE IF EXISTS `v_vendorall`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_vendorall`  AS SELECT `vendor`.`idvendor` AS `idvendor`, `vendor`.`nama_vendor` AS `nama_vendor`, `vendor`.`badan_hukum` AS `badan_hukum`, `vendor`.`status` AS `status` FROM `vendor``vendor`  ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `barang`
--
ALTER TABLE `barang`
  ADD PRIMARY KEY (`idbarang`),
  ADD KEY `idsatuan` (`idsatuan`);

--
-- Indexes for table `detail_penerimaan`
--
ALTER TABLE `detail_penerimaan`
  ADD PRIMARY KEY (`iddetail_penerimaan`),
  ADD KEY `idpenerimaan` (`idpenerimaan`),
  ADD KEY `barang_idbarang` (`barang_idbarang`);

--
-- Indexes for table `detail_pengadaan`
--
ALTER TABLE `detail_pengadaan`
  ADD PRIMARY KEY (`iddetail_pengadaan`),
  ADD KEY `idbarang` (`idbarang`),
  ADD KEY `fk_dp_idpengadaan` (`idpengadaan`);

--
-- Indexes for table `detail_penjualan`
--
ALTER TABLE `detail_penjualan`
  ADD PRIMARY KEY (`iddetail_penjualan`),
  ADD KEY `penjualan_idpenjualan` (`penjualan_idpenjualan`),
  ADD KEY `idbarang` (`idbarang`);

--
-- Indexes for table `detail_retur`
--
ALTER TABLE `detail_retur`
  ADD PRIMARY KEY (`iddetail_retur`),
  ADD KEY `idretur` (`idretur`),
  ADD KEY `fk_dtr_iddetailpenerimaan` (`iddetail_penerimaan`);

--
-- Indexes for table `kartu_stok`
--
ALTER TABLE `kartu_stok`
  ADD PRIMARY KEY (`idkartu_stok`),
  ADD KEY `idbarang` (`idbarang`);

--
-- Indexes for table `margin_penjualan`
--
ALTER TABLE `margin_penjualan`
  ADD PRIMARY KEY (`idmargin_penjualan`),
  ADD KEY `iduser` (`iduser`);

--
-- Indexes for table `penerimaan`
--
ALTER TABLE `penerimaan`
  ADD PRIMARY KEY (`idpenerimaan`),
  ADD KEY `iduser` (`iduser`),
  ADD KEY `fk_penerimaan_idpengadaan` (`idpengadaan`);

--
-- Indexes for table `pengadaan`
--
ALTER TABLE `pengadaan`
  ADD PRIMARY KEY (`idpengadaan`),
  ADD KEY `user_iduser` (`user_iduser`),
  ADD KEY `vendor_idvendor` (`vendor_idvendor`);

--
-- Indexes for table `penjualan`
--
ALTER TABLE `penjualan`
  ADD PRIMARY KEY (`idpenjualan`),
  ADD KEY `iduser` (`iduser`),
  ADD KEY `idmargin_penjualan` (`idmargin_penjualan`);

--
-- Indexes for table `retur_barang`
--
ALTER TABLE `retur_barang`
  ADD PRIMARY KEY (`idretur`),
  ADD KEY `iduser` (`iduser`),
  ADD KEY `fk_rb_idpenerimaan` (`idpenerimaan`);

--
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`idrole`);

--
-- Indexes for table `satuan`
--
ALTER TABLE `satuan`
  ADD PRIMARY KEY (`idsatuan`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`iduser`),
  ADD KEY `idrole` (`idrole`);

--
-- Indexes for table `vendor`
--
ALTER TABLE `vendor`
  ADD PRIMARY KEY (`idvendor`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `barang`
--
ALTER TABLE `barang`
  MODIFY `idbarang` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `detail_penerimaan`
--
ALTER TABLE `detail_penerimaan`
  MODIFY `iddetail_penerimaan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `detail_pengadaan`
--
ALTER TABLE `detail_pengadaan`
  MODIFY `iddetail_pengadaan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `detail_penjualan`
--
ALTER TABLE `detail_penjualan`
  MODIFY `iddetail_penjualan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `detail_retur`
--
ALTER TABLE `detail_retur`
  MODIFY `iddetail_retur` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kartu_stok`
--
ALTER TABLE `kartu_stok`
  MODIFY `idkartu_stok` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `margin_penjualan`
--
ALTER TABLE `margin_penjualan`
  MODIFY `idmargin_penjualan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `penerimaan`
--
ALTER TABLE `penerimaan`
  MODIFY `idpenerimaan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pengadaan`
--
ALTER TABLE `pengadaan`
  MODIFY `idpengadaan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `penjualan`
--
ALTER TABLE `penjualan`
  MODIFY `idpenjualan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `retur_barang`
--
ALTER TABLE `retur_barang`
  MODIFY `idretur` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `idrole` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `satuan`
--
ALTER TABLE `satuan`
  MODIFY `idsatuan` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `iduser` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `vendor`
--
ALTER TABLE `vendor`
  MODIFY `idvendor` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `barang`
--
ALTER TABLE `barang`
  ADD CONSTRAINT `barang_ibfk_1` FOREIGN KEY (`idsatuan`) REFERENCES `satuan` (`idsatuan`) ON DELETE SET NULL;

--
-- Constraints for table `detail_penerimaan`
--
ALTER TABLE `detail_penerimaan`
  ADD CONSTRAINT `detail_penerimaan_ibfk_2` FOREIGN KEY (`barang_idbarang`) REFERENCES `barang` (`idbarang`) ON DELETE SET NULL;

--
-- Constraints for table `detail_pengadaan`
--
ALTER TABLE `detail_pengadaan`
  ADD CONSTRAINT `detail_pengadaan_ibfk_1` FOREIGN KEY (`idbarang`) REFERENCES `barang` (`idbarang`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_dp_idpengadaan` FOREIGN KEY (`idpengadaan`) REFERENCES `pengadaan` (`idpengadaan`) ON DELETE SET NULL;

--
-- Constraints for table `detail_penjualan`
--
ALTER TABLE `detail_penjualan`
  ADD CONSTRAINT `detail_penjualan_ibfk_1` FOREIGN KEY (`penjualan_idpenjualan`) REFERENCES `penjualan` (`idpenjualan`) ON DELETE SET NULL,
  ADD CONSTRAINT `detail_penjualan_ibfk_2` FOREIGN KEY (`idbarang`) REFERENCES `barang` (`idbarang`) ON DELETE SET NULL;

--
-- Constraints for table `detail_retur`
--
ALTER TABLE `detail_retur`
  ADD CONSTRAINT `fk_dtr_iddetailpenerimaan` FOREIGN KEY (`iddetail_penerimaan`) REFERENCES `detail_penerimaan` (`iddetail_penerimaan`) ON DELETE SET NULL;

--
-- Constraints for table `kartu_stok`
--
ALTER TABLE `kartu_stok`
  ADD CONSTRAINT `kartu_stok_ibfk_1` FOREIGN KEY (`idbarang`) REFERENCES `barang` (`idbarang`) ON DELETE SET NULL;

--
-- Constraints for table `margin_penjualan`
--
ALTER TABLE `margin_penjualan`
  ADD CONSTRAINT `margin_penjualan_ibfk_1` FOREIGN KEY (`iduser`) REFERENCES `user` (`iduser`) ON DELETE SET NULL;

--
-- Constraints for table `penerimaan`
--
ALTER TABLE `penerimaan`
  ADD CONSTRAINT `fk_penerimaan_idpengadaan` FOREIGN KEY (`idpengadaan`) REFERENCES `pengadaan` (`idpengadaan`) ON DELETE SET NULL,
  ADD CONSTRAINT `penerimaan_ibfk_2` FOREIGN KEY (`iduser`) REFERENCES `user` (`iduser`) ON DELETE SET NULL;

--
-- Constraints for table `pengadaan`
--
ALTER TABLE `pengadaan`
  ADD CONSTRAINT `pengadaan_ibfk_1` FOREIGN KEY (`user_iduser`) REFERENCES `user` (`iduser`) ON DELETE SET NULL,
  ADD CONSTRAINT `pengadaan_ibfk_2` FOREIGN KEY (`vendor_idvendor`) REFERENCES `vendor` (`idvendor`) ON DELETE SET NULL;

--
-- Constraints for table `penjualan`
--
ALTER TABLE `penjualan`
  ADD CONSTRAINT `penjualan_ibfk_1` FOREIGN KEY (`iduser`) REFERENCES `user` (`iduser`) ON DELETE SET NULL,
  ADD CONSTRAINT `penjualan_ibfk_2` FOREIGN KEY (`idmargin_penjualan`) REFERENCES `margin_penjualan` (`idmargin_penjualan`) ON DELETE SET NULL;

--
-- Constraints for table `retur_barang`
--
ALTER TABLE `retur_barang`
  ADD CONSTRAINT `fk_rb_idpenerimaan` FOREIGN KEY (`idpenerimaan`) REFERENCES `penerimaan` (`idpenerimaan`) ON DELETE SET NULL,
  ADD CONSTRAINT `retur_barang_ibfk_2` FOREIGN KEY (`iduser`) REFERENCES `user` (`iduser`) ON DELETE SET NULL;

--
-- Constraints for table `user`
--
ALTER TABLE `user`
  ADD CONSTRAINT `user_ibfk_1` FOREIGN KEY (`idrole`) REFERENCES `role` (`idrole`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
