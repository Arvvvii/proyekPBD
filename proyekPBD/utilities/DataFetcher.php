<?php
require_once __DIR__ . '/../config/Database.php';

class DataFetcher {
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function barangAktif(): array { return $this->db->fetchAll('SELECT * FROM v_barangaktif ORDER BY nama_barang'); }
    public function barangAll(): array { return $this->db->fetchAll('SELECT * FROM v_barangall ORDER BY nama_barang'); }
    public function barangNonAktif(): array { return $this->db->fetchAll('SELECT * FROM v_barangall WHERE status = 0 ORDER BY nama_barang'); }
    public function satuanAktif(): array { return $this->db->fetchAll('SELECT * FROM v_satuanaktif ORDER BY nama_satuan'); }
    public function roles(): array { return $this->db->fetchAll('SELECT * FROM v_roleall ORDER BY nama_role'); }
    public function users(): array { return $this->db->fetchAll('SELECT * FROM v_userall ORDER BY username'); }
    public function vendorsAktif(): array { return $this->db->fetchAll("SELECT * FROM v_vendoraktif ORDER BY nama_vendor"); }
    public function pengadaanHeader(): array { return $this->db->fetchAll("SELECT * FROM v_pengadaanheader ORDER BY tanggal_pengadaan DESC"); }
    public function penjualanHeader(): array { return $this->db->fetchAll("SELECT * FROM v_penjualanheader ORDER BY tanggal_penjualan DESC"); }
    public function penerimaanHeader(): array { return $this->db->fetchAll("SELECT * FROM v_penerimaanheader ORDER BY tanggal_terima DESC"); }
    public function stokSummary(): array { return $this->db->fetchAll('SELECT * FROM v_stoksummary ORDER BY nama_barang'); }

    // Ledger kartu stok: coba view v_kartustok jika tersedia, fallback join langsung
    public function kartuStokAll(): array {
        try {
            return $this->db->fetchAll('SELECT * FROM v_kartustok ORDER BY created_at DESC, idkartu_stok DESC');
        } catch (\Throwable $e) {
            return $this->db->fetchAll('SELECT ks.*, b.nama AS nama_barang FROM kartu_stok ks JOIN barang b ON ks.idbarang=b.idbarang ORDER BY ks.created_at DESC, ks.idkartu_stok DESC');
        }
    }

    // Detail penerimaan lengkap untuk memilih item pada retur
    public function detailPenerimaanByPenerimaan(int $idpenerimaan): array {
        return $this->db->fetchAll('SELECT * FROM v_detailpenerimaanlengkap WHERE idpenerimaan = ? ORDER BY iddetail_penerimaan DESC',[ $idpenerimaan ]);
    }
}
