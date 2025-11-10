# Catatan Presentasi Proyek PBD

Tanggal: 10 Nov 2025  
Basis Data: `proyekpbd` (MySQL 8)  
Focus: Implementasi View, Stored Procedure (SP), Function (FN), Trigger (TG) dalam modul Master & Transaksi.

---
## 1. Tujuan & Konsep
Proyek ini menekankan:
- Konsistensi akses READ via VIEW (abstraksi, keamanan, stabilitas struktur).
- Penggunaan SP untuk operasi multi-langkah (insert header + detail, perhitungan total).
- Trigger menjaga integritas stok secara otomatis (sisi server, mengurangi logic di PHP).
- Function untuk encapsulate kalkulasi (misal total penjualan) agar mudah dirawat.

---
## 2. Ringkasan Komponen DB
### Views Utama Master
| Domain | View Aktif | View All/Full | Keterangan |
|--------|------------|---------------|------------|
| Barang | `v_barangaktif` | `v_barangall` | Relasi barang + satuan; filter status=1 |
| Satuan | `v_satuanaktif` | `v_satuanall` | Daftar satuan; filter status=1 |
| Vendor | `v_vendoraktif` | `v_vendorall` | Daftar vendor aktif/nonaktif |
| User   | (langsung ke semua) | `v_userall` | User + role |
| Role   | `v_roleall` | (sama) | Semua role |
| Margin | `v_marginaktif` | `v_marginall` | Margin penjualan, single active |

### Views Transaksi
| Domain | View Header | View Detail | Keterangan |
|--------|-------------|-------------|------------|
| Pengadaan | `v_pengadaanheader` | `v_detailpengadaanlengkap` | Header + detail barang & satuan |
| Penerimaan | `v_penerimaanheader` | `v_detailpenerimaanlengkap` | Terkait pengadaan & barang diterima |
| Penjualan | `v_penjualanheader` | `v_detailpenjualanlengkap` | Header kasir + margin + total |
| Stok | `v_stoksummary` | (kartu stok per gerak) | Ringkas saldo terakhir per barang |

> Catatan: `v_kartustok` belum ada; kode menyiapkan fallback join.
> Retur belum memakai VIEW khusus (langsung JOIN).

### Stored Procedures
| Nama SP | Tujuan | Dipakai di Kode |
|---------|--------|-----------------|
| `sp_insert_penerimaan_lengkap` | Insert penerimaan + detail sekaligus | YA (`penerimaan.php`) |
| `sp_insert_penjualan_lengkap` | Insert penjualan + detail sekaligus | YA (`penjualan.php`) |
| `SP_UpdateHeaderPenjualan` | Re-kalkulasi subtotal, ppn, total penjualan | YA (dipanggil setelah insert detail) |
| `sp_insert_pengadaan_lengkap` | Insert pengadaan + detail sekaligus | Tersedia tapi belum dipakai (workflow dipecah) |
| `SP_GetStokSummary` | Ambil saldo stok terakhir | Tidak langsung (pakai view) |
| `sp_InsertDetailPengadaan` | (Dicoba oleh kode) | TIDAK ADA di dump (fallback ke INSERT biasa) |
| `SP_UpdatePengadaanTotals` | (Dicoba oleh kode) | TIDAK ADA di dump (fallback hitung manual) |

### Functions
| Nama FN | Tujuan | Dipakai |
|---------|--------|---------|
| `FN_HitungTotalPenjualan` | Hitung subtotal + ppn + total | YA (di dalam SP_UpdateHeaderPenjualan) |
| `FN_AmbilHargaBeliTerakhir` | Ambil harga beli terakhir | Belum dipanggil langsung |
| `FN_CekStok` | Ambil stok terakhir | Belum dipanggil langsung |
| `FN_HitungSubtotalDetail` | Perkalian sederhana | Belum dipanggil langsung |

### Triggers
| Nama Trigger | Tabel | Waktu | Fungsi |
|--------------|-------|-------|--------|
| `TR_AfterInsert_DetailPenerimaan` | `detail_penerimaan` | AFTER INSERT | Update kartu stok (masuk) |
| `TR_AfterInsert_DetailPenjualan` | `detail_penjualan` | AFTER INSERT | Validasi stok cukup + update kartu stok (keluar) |

---
## 3. Arsitektur Aplikasi (PHP)
- Layer akses DB: `config/Database.php` (PDO + helper `callProcedure`).
- Abstraksi Model generik: `models/BaseModel.php` (CRUD + enforce READ via view).
- Pengambilan data tampilan: `utilities/DataFetcher.php` (semua SELECT ke VIEW).
- Halaman Master & Transaksi di folder `views/` memanfaatkan DataFetcher atau query via view.
- Trigger & kalkulasi stok di-offload ke database -> aplikasi tetap ringan.

Diagram singkat alur penjualan:
```
Form Penjualan -> CALL sp_insert_penjualan_lengkap -> INSERT header & detail
               -> CALL SP_UpdateHeaderPenjualan -> FN_HitungTotalPenjualan
               -> Trigger (detail_penjualan) update kartu_stok (keluar) & validasi stok
               -> View v_penjualanheader & v_detailpenjualanlengkap untuk display
```

Diagram singkat alur penerimaan:
```
Form Penerimaan -> CALL sp_insert_penerimaan_lengkap -> INSERT header & detail
                 -> Trigger (detail_penerimaan) update kartu_stok (masuk)
                 -> View v_penerimaanheader & v_detailpenerimaanlengkap untuk display
```

---
## 4. Alur Demo (Step-by-Step)
1. Login (AuthController) – cek user via `user JOIN role` (bisa jelaskan konsep view di sini walau langsung join).
2. Buka Master Barang – tunjukkan label view (aktif vs arsip) di `barang.php`.
3. Tambah Barang – perhatikan tidak perlu query SELECT tabel langsung untuk listing (pakai view).
4. Pengadaan:
   - Buat header (INSERT langsung) – highlight rencana refactor ke SP penuh.
   - Tambah detail – jelaskan fallback mekanisme ketika SP belum tersedia.
   - Lihat header & detail via view.
5. Penerimaan:
   - Pilih pengadaan -> Tambah penerimaan -> Jelaskan trigger stok.
   - Tampilkan perubahan stok di Kartu Stok.
6. Penjualan:
   - Tambah penjualan -> SP + FN + Trigger bekerja.
   - Refresh header penjualan dan stok.
7. Retur:
   - Catat retur (belum view khusus) – jelaskan improvement.

---
## 5. Keunggulan Desain
- Separation of Concerns: logika perhitungan total di SP/FN, bukan bercampur di PHP.
- Data Integrity: Trigger mencegah stok negatif dan menjaga saldo otomatis.
- Maintainability: VIEW membuat query di aplikasi sangat sederhana (SELECT * FROM view_x).
- Extensibility: Mudah menambah kolom di tabel tanpa mengubah kode presentasi; cukup update VIEW.

---
## 6. Validasi & Observasi
Cara cek cepat di MySQL CLI / phpMyAdmin:
```sql
SHOW FULL TABLES WHERE Table_type='VIEW';
SHOW PROCEDURE STATUS WHERE Db='proyekpbd';
SHOW FUNCTION STATUS WHERE Db='proyekpbd';
SHOW TRIGGERS FROM proyekpbd;
SELECT * FROM v_penjualanheader LIMIT 5;
CALL sp_insert_penjualan_lengkap(1, 1, 1, 2, 16000);
CALL SP_UpdateHeaderPenjualan(5); -- misal id baru
```
Pastikan hak akses DEFINER dan SECURITY DEFINER tetap sesuai (default root@localhost).

---
## 7. Potensi Pertanyaan Dosen & Jawaban Singkat
| Pertanyaan | Jawaban Ringkas |
|------------|-----------------|
| Kenapa pakai VIEW? | Abstraksi, keamanan, stabilitas skema, memudahkan join kompleks. |
| Bedanya SP dan Trigger? | SP dipanggil eksplisit; Trigger otomatis jalan pada event tabel. |
| Bagaimana jaga stok? | Insert detail transaksi memicu trigger update kartu_stok + validasi stok. |
| Kenapa ada fallback di pengadaan? | Menjaga user flow walau SP belum dibuat; rencana konsolidasi. |
| Apa risiko tanpa trigger retur? | Stok tidak berkurang balik saat retur; perlu trigger atau prosedur tambahan. |
| Mengapa FN_HitungTotalPenjualan dipakai? | Konsisten hitung total + PPN di server side, menghindari duplikasi logic. |
| Apakah ini mencegah SQL Injection? | Parameterized PDO prepared statements digunakan untuk semua input. |

---
## 8. Rencana Perbaikan (Next Steps)
1. Tambahkan SP pengadaan detail (`sp_InsertDetailPengadaan`) dan total (`SP_UpdatePengadaanTotals`) atau ubah kode agar pakai `sp_insert_pengadaan_lengkap`.
2. Buat VIEW `v_kartustok` agar konsisten read-only ledger.
3. Buat VIEW untuk retur (header + detail) agar seragam.
4. Tambah trigger retur untuk mengembalikan stok (jenis transaksi 'R').
5. Index tambahan: periksa kebutuhan index di kolom filter umum (status, idbarang).
6. Audit hak akses: gunakan user DB khusus dengan hak SELECT/EXECUTE minimal.
7. Tambahkan logging transaksi (table log atau event sourcing ringan).

---
## 9. Cheat Commands (Opsional Demo Cepat)
```sql
-- Cek view penjualan
SELECT * FROM v_penjualanheader ORDER BY tanggal_penjualan DESC LIMIT 3;
-- Simulasi penjualan baru
CALL sp_insert_penjualan_lengkap(1, 1, 1, 3, 16000);
CALL SP_UpdateHeaderPenjualan(LAST_INSERT_ID());
-- Lihat detail
SELECT * FROM v_detailpenjualanlengkap ORDER BY iddetail_penjualan DESC LIMIT 3;
-- Cek stok ringkas
SELECT * FROM v_stoksummary;
```

---
## 10. Kesimpulan
Struktur ini menunjukkan pemisahan jelas antara lapisan presentasi (PHP), abstraksi data (VIEW), logika bisnis terpusat (SP/FN), dan integritas otomatis (Trigger). Perbaikan lanjutan akan memantapkan konsistensi dan audit.

Selamat presentasi! 👍
