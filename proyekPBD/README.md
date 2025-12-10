# Inventory Management (PHP OOP)

## Ringkas
Aplikasi manajemen inventory berbasis PHP native dengan OOP, memakai View, Stored Procedure, Function, dan Trigger sesuai skema database `proyekpbd`.

## Struktur Folder
```
proyekPBD/
├── index.php
├── login.php
├── logout.php
├── config/
│   ├── AppConfig.php
│   └── Database.php
├── controllers/
│   └── AuthController.php
├── models/
│   └── BaseModel.php
├── utilities/
│   └── DataFetcher.php
├── views/
│   ├── template.php
│   ├── master/
│   │   ├── user.php
│   │   └── barang.php
│   └── transaksi/
│       ├── penjualan.php
│       └── penerimaan.php
└── README.md
```

## Fitur
- Login & Session (password hash BCrypt)
- Dashboard dengan statistik dan ringkasan stok (view `v_stoksummary`)
- CRUD User & Barang (SELECT via view, INSERT/UPDATE/DELETE langsung ke tabel)
- Transaksi Penerimaan & Penjualan single-page (SP: `sp_insert_penerimaan_lengkap`, `sp_insert_penjualan_lengkap`, update header via `SP_UpdateHeaderPenjualan`)
- Integrasi Views penuh untuk semua SELECT
- Penghitungan total penjualan memakai Function + Stored Procedure

## Database
Import file dump: `proyekpbd (4).sql` ke MySQL.
Pastikan user root tanpa password (atau set env DB_PASS).

## Konfigurasi Koneksi
Atur environment (opsional):
```
DB_HOST=localhost
DB_NAME=proyekpbd
DB_USER=root
DB_PASS=
```
Laragon umumnya sudah sesuai.

## Kredensial Awal
Gunakan salah satu:
- superadmin_arvi / (password sesuai hash di dump; jika tidak tahu, buat user baru lewat form User)
- administrator_adel / (sama catatan di atas)

Jika lupa password: jalankan UPDATE di MySQL:
```
UPDATE user SET password = '$2y$10$QGr3Kuz9t6LQ70ZZ.eTtL.HaMgAvK7g1.3H68KBtLop93p3upHq3W' WHERE username='superadmin_arvi';
```
(Hash ini contoh; ganti sesuai kebutuhan.)

## Alur Penjualan
1. Isi form detail (margin opsional) -> SP insert header + detail.
2. Sistem memanggil `SP_UpdateHeaderPenjualan` untuk menghitung subtotal / PPN / total.
3. Trigger otomatis mengurangi stok (`TR_AfterInsert_DetailPenjualan`).

## Alur Penerimaan
1. Pilih pengadaan (header) + barang + jumlah + harga.
2. SP `sp_insert_penerimaan_lengkap` membuat header + detail.
3. Trigger penerimaan menambah stok (`TR_AfterInsert_DetailPenerimaan`).

## Catatan Pengembangan
- Semua SELECT harus tetap memakai view; jangan query langsung tabel untuk listing.
- Sanitasi tambahan (CSRF, validation granular) bisa ditambahkan sebagai next step.
- Untuk produksi: pindah kredensial ke file `.env` atau environment server.

## Next Steps (Opsional)
- Logging aktivitas user.
- Pagination & pencarian server-side.
- Unit test dengan Pest / PHPUnit.

## Lisensi
Internal use.
