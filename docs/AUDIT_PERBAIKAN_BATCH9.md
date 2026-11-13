# Audit dan Perbaikan Menjelang Rilis — SIMA Garmen

**Basis source:** `sima-garmen(2).zip` yang dikirim terakhir.<br>
**Acuan bisnis:** `Rancangan_SIMA_Garmen.md` versi 1.0 dan `Langkah_Backend_SIMA_Garmen.txt` versi 1.0.<br>
**Tujuan:** memperbaiki ketidaksesuaian yang ditemukan sebelum Batch 9 Tahap 8 (commit dan tag rilis).

## Prinsip perubahan

- Perubahan Batch 9 yang sudah ada pada source yang benar—command, scheduler, `IntegrityService`, `DemoDataSeeder`, notifikasi, skenario UAT, serta pengujian keamanan—dipertahankan.
- Perubahan dilakukan pada area yang tidak sesuai rancangan atau memiliki celah regresi.
- Tidak ada fitur Fase 2 yang ditambahkan.
- `PerformanceSeeder` dan `Batch9PerformanceTest` diselaraskan dengan kebutuhan uji beban **500 order dan 50.000 log produksi**, bukan hanya activity log.

## Perbaikan yang diterapkan

1. **Autoload model BOM lintas sistem operasi**<br>
   Referensi `App\Models\BOM` diseragamkan menjadi `App\Models\Bom`, mengikuti nama file dan class `Bom.php`. Ini menghindari kegagalan autoload pada filesystem yang case-sensitive.

2. **Stok opname dapat mengubah draft**<br>
   Endpoint `PUT` dan `PATCH /opname/{id}` ditambahkan. Update menggunakan transaksi dan row lock, hanya mengizinkan draft milik pembuat atau Admin, mengganti item draft secara utuh, menghitung ulang snapshot stok/selisih, dan mencatat audit log. Eager loading relasi pembuat/penyetuju juga disesuaikan dengan nama relasi model.

3. **PDF transaksi yang diminta rancangan**<br>
   Ditambahkan endpoint dan template PDF untuk:
    - `GET /work-order/{id}/cetak`
    - `GET /po/{id}/cetak`
    - `GET /penerimaan/{id}/cetak`
    - `GET /pengeluaran-bahan/{id}/cetak`

    Scope WO dan pengeluaran bahan untuk Supervisor tetap dibatasi ke tahap miliknya.

4. **Filter dan pengurutan order**<br>
   `GET /order` mendukung `tgl_dari`, `tgl_sampai`, `terlambat`, serta `sort` yang di-whitelist (`created_at`, `deadline`, `tgl_order`, `no_order`, dengan awalan `-` untuk descending). Validasi rentang tanggal dan batas `per_page` diterapkan.

5. **Audit log perubahan BOM**<br>
   Pembuatan, perubahan, penghapusan, dan penyalinan BOM dicatat melalui `Audit::log` di service dan transaksi yang sesuai.

6. **Pembatasan detail Work Order untuk Supervisor**<br>
   Detail WO menyaring `wo_tahap` dan `wo_bahan` ke tahap milik Supervisor, menyembunyikan counter tahap lain/hilir, serta tidak menampilkan packing kecuali Supervisor Finishing.

7. **Rumus `kirim_tersedia`**<br>
   Diperbaiki agar mengikuti rumus rancangan: `qty_packing - qty_terkirim - qty dalam koli pada surat jalan berstatus disiapkan`. Perhitungan tersedia di accessor model, detail WO, dan laporan WIP. Query agregat digunakan agar pengambilan detail WO tidak menghasilkan query tambahan untuk setiap item.

8. **Penerima alert deadline**<br>
   Alert deadline dikirim kepada seluruh user aktif ber-role Manajer dan Staf Order sesuai tabel notifikasi rancangan, bukan hanya pembuat order.

9. **Alert stok minimum**<br>
   Kondisi mengikuti aturan persis `stok tersedia <= stok minimum`, termasuk ketika minimum bernilai nol. Tes command disesuaikan untuk kondisi batas tersebut.

10. **Rekalkulasi counter dan penomoran**<br>
    Rekalkulasi mengunci baris `wo_items` sebelum membaca agregat log, serta membatasi agregasi ke item WO yang sedang diperiksa. Periode nomor dokumen memakai waktu aplikasi (`now()`) agar mengikuti zona waktu konfigurasi aplikasi.

11. **Seeder performa**<br>
    `PerformanceSeeder` disesuaikan dengan struktur tabel SIMA Garmen saat ini. Seeder hanya dapat dijalankan pada environment `local`/`testing`, membuat 500 order/WO/item WO dan 50.000 log produksi aktif, serta menghentikan diri bila data `ORD-PERF` sudah ada.

12. **Dokumentasi API dan regresi**<br>
    OpenAPI dan Postman diperbarui untuk route opname, PDF, serta parameter order. Ditambahkan `Batch9AuditFixesTest` berisi tujuh tes regresi untuk filter/order, opname, audit BOM, scope supervisor, rumus pengiriman, PDF, dan penerima alert deadline.

## File yang ditambah

- `resources/views/pdf/work-order.blade.php`
- `resources/views/pdf/purchase-order.blade.php`
- `resources/views/pdf/penerimaan-bahan.blade.php`
- `resources/views/pdf/pengeluaran-bahan.blade.php`
- `tests/Feature/Batch9AuditFixesTest.php`
- `docs/AUDIT_PERBAIKAN_BATCH9.md`

## File utama yang diperbarui

- `app/Console/Commands/DeadlineAlerts.php`
- `app/Console/Commands/StockAlerts.php`
- `app/Helpers/NumberingService.php`
- `app/Http/Controllers/Api/BomController.php`
- `app/Http/Controllers/Api/MaterialIssueController.php`
- `app/Http/Controllers/Api/OpnameController.php`
- `app/Http/Controllers/Api/OrderController.php`
- `app/Http/Controllers/Api/PenerimaanController.php`
- `app/Http/Controllers/Api/PoController.php`
- `app/Http/Controllers/Api/WorkOrderController.php`
- `app/Models/WoItem.php`
- `app/Services/BomService.php`
- `app/Services/IntegrityService.php`
- `app/Services/OpnameService.php`
- `app/Services/ReportService.php`
- `app/Services/WorkOrderService.php`
- `database/seeders/PerformanceSeeder.php`
- `tests/Feature/Batch9PerformanceTest.php`
- `routes/api.php`
- `docs/openapi.yaml`
- `docs/SIMA.postman_collection.json`
- `docs/CATATAN_API.md`
- `tests/Feature/Batch9CommandTest.php`
- `tests/Feature/Batch9PerformanceTest.php`
- Tes sebelumnya yang mengimpor model BOM juga diseragamkan kapitalisasinya.

## Validasi yang berhasil di lingkungan audit

| Pemeriksaan                                                                      |                                                                 Hasil |
| -------------------------------------------------------------------------------- | --------------------------------------------------------------------: |
| PHP syntax lint pada `app`, `bootstrap`, `config`, `database`, `routes`, `tests` |                                                     252 file, 0 error |
| Laravel bootstrap / `php artisan --version`                                      |                                              Laravel 13.40.0 berhasil |
| Route API                                                                        | 138 baris route; 144 operasi HTTP setelah menghitung method PUT/PATCH |
| Operasi OpenAPI                                                                  |                           144; cocok dengan seluruh 144 operasi route |
| `operationId` OpenAPI                                                            |                                                              144 unik |
| Referensi `$ref` OpenAPI                                                         |                              579; tidak ada yang rusak atau eksternal |
| Request Postman                                                                  |                                                                   159 |
| Method tes yang terdeteksi                                                       |                                                                   196 |

## Validasi runtime yang masih diperlukan

Pengujian PHPUnit terbaru **belum berhasil dijalankan di lingkungan audit ini**. PHP CLI yang tersedia tidak memiliki ekstensi `dom`, `mbstring`, dan `xmlwriter` yang diwajibkan PHPUnit. Karena itu, tujuh tes regresi baru dan perubahan test alert/performa belum boleh dianggap PASS berdasarkan pemeriksaan ini.

Log terminal yang dikirim sebelumnya menunjukkan **189 tes PASS (1.893 assertions, 91,89 detik)** pada source sebelum perbaikan audit ini. Hasil tersebut tetap dicatat sebagai bukti versi sebelumnya, bukan sebagai hasil dari source yang sudah diperbaiki.

Di komputer Laragon, setelah source ini diterapkan dan koneksi test database siap, jalankan:

```powershell
composer install
php artisan test
php artisan sima:check-integrity
php artisan route:list --path=api
```

`phpunit.xml` proyek memakai database MySQL terpisah bernama `sima_garmen_test`. Pastikan database tersebut memang database pengujian. Jangan menjalankan `migrate:fresh` terhadap database operasional/produksi.

Untuk uji beban manual berbasis seeder, gunakan database lokal khusus dan jalankan:

```powershell
php artisan db:seed --class=PerformanceSeeder
php artisan sima:check-integrity
```

Seeder performa tidak menghapus data yang sudah ada. Jangan jalankan pada database produksi atau database kerja yang datanya harus dipertahankan.

## Catatan arsitektur yang tersisa

Pengecekan otorisasi saat ini tetap tersebar di `FormRequest::authorize()`, pemeriksaan `hasRole()` pada controller, dan pemeriksaan kepemilikan di service. Perilaku role diuji oleh suite keamanan yang sudah ada, tetapi proyek belum memusatkan seluruh aturan tersebut ke kelas-kelas Laravel Policy seperti pola ideal di langkah pengerjaan. Pemindahan menyeluruh ke Policy belum dilakukan agar tidak mengubah kontrak atau memperluas risiko regresi pada tahap audit ini.

**Status:** perbaikan source dan validasi statis selesai. Rilis `v1.0.0` belum boleh ditandai sampai seluruh tes runtime pada Laragon, pemeriksaan integritas, dan uji beban pada database lokal selesai dan lulus.
