# Catatan API SIMA Garmen

Panduan ringkas untuk frontend. Rincian endpoint ada di `openapi.yaml` (144 operasi). Koleksi Postman memuat 159 request, termasuk 16 jenis laporan dengan URL konkret.

- Base URL lokal: `http://127.0.0.1:8000/api/v1`
- Autentikasi: `Authorization: Bearer <token>` (Sanctum). Endpoint publik: `POST /auth/login`, `GET /enum`, `GET /pengaturan-umum`.
- Kirim `Accept: application/json`. Tanggal memakai format `YYYY-MM-DD`; zona waktu bisnis Asia/Jakarta.
- Akun awal: `admin` / `admin12345` (ganti segera). Akun demo semua role memakai password `password123`.
- Environment Postman menyediakan `base_url`, `login`, `password`, dan `token`. Request Login menyimpan token otomatis; ganti `login` dan `password` untuk menguji role lain.

## 1. Format respons

**Objek tunggal**

```json
{ "data": { "id": 1, "no_sj": "SJ-2611-0001", "status": "disiapkan" } }
```

**Daftar terpaginasi.** Memakai paginator Laravel yang **datar**, bukan `data/links/meta`:

```json
{
    "current_page": 1,
    "data": [{ "...": "..." }],
    "first_page_url": "...",
    "from": 1,
    "last_page": 3,
    "last_page_url": "...",
    "links": [{ "url": null, "label": "&laquo; Previous", "active": false }],
    "next_page_url": "...",
    "path": "...",
    "per_page": 25,
    "prev_page_url": null,
    "to": 25,
    "total": 61
}
```

Gunakan `?page=` dan `?per_page=` (maks 100). Daftar order juga menerima `tgl_dari`, `tgl_sampai`, `terlambat=1|0`, dan `sort` (`created_at`, `-created_at`, `deadline`, `-deadline`, `tgl_order`, `-tgl_order`, `no_order`, `-no_order`; default `-created_at`). Beberapa daftar tidak terpaginasi dan hanya mengembalikan `{ "data": [ ... ] }`: `GET /qc/antrean`, `GET /order/{id}/koli-tersedia`, `GET /produksi/antrean`.

**Status dan label.** Semua status (order, WO, koli, pengiriman, dan seterusnya) dikirim sebagai **string nilai**, misalnya `"dikirim_sebagian"`. Label tampilan diambil dari `GET /enum`, yang berisi `[{ "value": "...", "label": "..." }]` per jenis enum. Warna badge ditentukan frontend.

**Error**

| Jenis                 | Bentuk                                                               |
| --------------------- | -------------------------------------------------------------------- |
| Aturan bisnis         | `{ "message": "...", "code": "KODE" }`                               |
| Validasi 422          | `{ "message": "Validasi gagal.", "errors": { "field": ["pesan"] } }` |
| 401 / 403 / 404 / 429 | `{ "message": "..." }`                                               |

Tampilkan `message` kepada pengguna dan gunakan `code` untuk logika tampilan.

## 2. Kode error bisnis

| Kode                         | HTTP      | Arti                                                                             |
| ---------------------------- | --------- | -------------------------------------------------------------------------------- |
| `STATUS_TIDAK_VALID`         | 422 / 409 | Status dokumen tidak memungkinkan aksi; 409 dipakai pada konflik status tertentu |
| `TIDAK_ADA_HAK`              | 403       | Role atau kepemilikan tidak cukup                                                |
| `VALIDASI_GAGAL`             | 422       | Aturan bisnis pada data dilanggar (rumus QC, item bukan milik WO, dan lain-lain) |
| `ALASAN_WAJIB`               | 422       | Aksi butuh alasan minimal 5 karakter                                             |
| `QTY_MELEBIHI_BATAS`         | 422       | Qty melebihi yang tersedia pada tahap itu                                        |
| `STOK_TIDAK_CUKUP`           | 422       | Stok fisik tersedia tidak cukup                                                  |
| `STOK_DI_BAWAH_RESERVASI`    | 422 / 409 | Perubahan akan membuat stok lebih kecil dari reservasi berjalan                  |
| `BAHAN_TAHAP_BELUM_LENGKAP`  | 422       | Reservasi bahan untuk tahap tersebut belum terpenuhi                             |
| `TAHAP_SUDAH_DITUTUP`        | 422       | Tahap sudah ditutup                                                              |
| `VOID_DITOLAK_HILIR_NEGATIF` | 422       | Void membuat ketersediaan atau counter proses hilir menjadi negatif              |
| `BOM_BELUM_LENGKAP`          | 422       | BOM cutting produk belum lengkap saat persetujuan                                |
| `MELEBIHI_TOLERANSI`         | 422       | Pengeluaran bahan melampaui toleransi yang diizinkan                             |
| `DATA_SUDAH_DIPAKAI`         | 409       | Master atau kombinasi BOM sudah dipakai / sudah terdaftar                        |
| `KOLI_TIDAK_TERSEDIA`        | 422       | Koli tidak berstatus tersedia                                                    |
| `SUDAH_ADA_PENGELUARAN`      | 422 / 409 | Aksi tidak dapat dilakukan karena penerimaan/pengeluaran terkait sudah ada       |
| `DATA_KOSONG`                | 422       | Data sumber yang diperlukan tidak tersedia                                       |
| `AKSI_TIDAK_VALID`           | 400       | Aksi tidak dikenal                                                               |
| `TIDAK_DITEMUKAN`            | 404       | Berkas atau data turunan tidak ada                                               |
| `TERLALU_BANYAK_PERCOBAAN`   | 429       | Batas percobaan login tercapai; perhatikan header `Retry-After`                  |

Kode `BELUM_ADA_PENGELUARAN` tercantum di kontrak awal, tetapi belum ditemukan sebagai kode yang dikeluarkan oleh implementasi saat ini. Jangan jadikan kode tersebut sebagai dependensi frontend sebelum benar-benar ditambahkan ke backend.

## 3. Hak akses ringkas

Kolom: A = admin, M = manajer, SO = staf_order, G = gudang, SV = supervisor, QC = qc, P = pimpinan. L = hanya lihat. Frontend boleh menyembunyikan tombol sesuai role, tetapi backend selalu memeriksa dan membalas 403.

| Fungsi                                                          |  A  |      M      |        SO         |  G  |        SV        |             QC              |  P  |
| --------------------------------------------------------------- | :-: | :---------: | :---------------: | :-: | :--------------: | :-------------------------: | :-: |
| Buat, ubah, ajukan order                                        |  ✔  |      ✔      | ✔ (milik sendiri) |  –  |        –         |              –              |  –  |
| Lihat order                                                     |  ✔  |      ✔      |         ✔         |  L  |        –         |              L              |  L  |
| Setujui, tolak, revisi, batalkan, hentikan, deadline, prioritas |  ✔  |      ✔      |         –         |  –  |        –         |              –              |  –  |
| Lihat WO                                                        |  ✔  |      ✔      |         L         |  L  |   L (tahapnya)   | L (yang sudah di finishing) |  L  |
| Tutup kurang WO                                                 |  ✔  |      ✔      |         –         |  –  |        –         |              –              |  –  |
| PO (buat, pesan, batal)                                         |  ✔  |      L      |         –         |  ✔  |        –         |              –              |  L  |
| Penerimaan bahan                                                |  ✔  |      L      |         –         |  ✔  |        –         |              –              |  L  |
| Pengeluaran dan retur bahan                                     |  ✔  |      L      |         –         |  ✔  |   L (tahapnya)   |              –              |  L  |
| Setujui pengeluaran melebihi toleransi                          |  ✔  |      ✔      |         –         |  –  |        –         |              –              |  –  |
| Buat dan ajukan opname                                          |  ✔  |      –      |         –         |  ✔  |        –         |              –              |  –  |
| Setujui atau tolak opname                                       |  ✔  |      ✔      |         –         |  –  |        –         |              –              |  –  |
| Input produksi, tutup tahap                                     |  ✔  | tutup tahap |         –         |  –  |   ✔ (tahapnya)   |              –              |  –  |
| Buka tahap, tambah potong                                       |  ✔  |      ✔      |         –         |  –  |        –         |              –              |  –  |
| Inspeksi QC                                                     |  ✔  |      –      |         –         |  –  |        –         |              ✔              |  –  |
| Selesaikan repair                                               |  ✔  |      –      |         –         |  –  | ✔ (tahap tujuan) |              –              |  –  |
| Packing (koli)                                                  |  ✔  |      –      |         –         |  –  |  ✔ (finishing)   |              –              |  –  |
| Buat dan kirim surat jalan                                      |  ✔  |      L      |         L         |  ✔  |        –         |              –              |  L  |
| Konfirmasi diterima                                             |  ✔  |      –      |         ✔         |  ✔  |        –         |              –              |  –  |
| Batal pengiriman sebelum dikirim                                |  ✔  |      –      |         –         |  ✔  |        –         |              –              |  –  |
| Batal pengiriman setelah dikirim                                |  ✔  |      ✔      |         –         |  –  |        –         |              –              |  –  |

## 4. Alur contoh: dari order sampai terkirim

1. `POST /auth/login` lalu simpan `data.token`. Muat `GET /me`, `GET /menu`, `GET /enum`.
2. Staf Order: `POST /order` (draft) lalu `POST /order/{id}/ajukan`.
3. Manajer/Admin: `POST /order/{id}/setujui`. Hasilnya WO, snapshot BOM, dan reservasi bahan. Status order `siap_produksi` atau `menunggu_bahan`.
4. Bila `menunggu_bahan`: `GET /kekurangan-bahan`, Gudang `POST /po/dari-kekurangan`, `POST /po/{id}/pesan`, lalu `POST /penerimaan`. Reservasi dialokasikan ulang otomatis.
5. Gudang: `GET /pengeluaran-bahan/bahan-wo?wo_id=&tahap_id=` lalu `POST /pengeluaran-bahan` per tahap. Melebihi reservasi berstatus `menunggu` dan perlu `POST /pengeluaran-bahan/{id}/setujui`.
6. Supervisor: `GET /produksi/antrean` lalu `POST /produksi/log` untuk cutting, sewing, finishing (masing-masing hanya tahap miliknya). Status order menjadi `produksi`.
7. QC: `GET /qc/antrean` lalu `POST /qc/inspeksi`. Pcs repair menjadi antrean `GET /repair`; supervisor tahap tujuan memakai `POST /repair/{id}/selesai`, dan pcs kembali ke antrean QC.
8. Supervisor finishing: `POST /packing` membuat koli dari pcs lolos QC. `GET /packing/{id}/label` mencetak label. Bila seluruh qty order sudah dikemas, WO otomatis `selesai` dan order `siap_kirim`. Bila sebagian tidak akan diproduksi: Manajer `POST /work-order/{id}/tutup-kurang`.
9. Gudang: `GET /order/{id}/koli-tersedia`, `POST /pengiriman` (SJ `disiapkan`), lalu `POST /pengiriman/{id}/kirim`. `GET /pengiriman/{id}/cetak` mencetak surat jalan.
10. Staf Order atau Gudang: `POST /pengiriman/{id}/diterima` (bukti opsional, `multipart/form-data`).

Pengiriman parsial diperbolehkan kapan saja selama ada koli tersedia, bahkan saat produksi belum selesai.

## 5. Status order

Status ditetapkan oleh aksi pengguna sampai `produksi`, kemudian diturunkan sistem dari produksi dan pengiriman:

| Kondisi                                                                                              | Status order                                                    |
| ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| Produksi belum selesai (WO aktif)                                                                    | tetap `produksi`, walaupun sudah ada pengiriman parsial         |
| WO selesai atau ditutup kurang, belum ada koli terkirim                                              | `siap_kirim`                                                    |
| WO selesai atau ditutup kurang, sebagian koli terkirim                                               | `dikirim_sebagian`                                              |
| WO selesai atau ditutup kurang, tidak ada koli tersedia atau dalam pengiriman, dan ada koli terkirim | `terkirim`                                                      |
| Order `dihentikan`                                                                                   | tidak berubah, walaupun koli yang sudah ada tetap boleh dikirim |

Pembatalan pengiriman setelah dikirim dapat menurunkan status (misalnya `terkirim` menjadi `siap_kirim`). Progres kirim per item ada di `wo_items[]` pada `GET /work-order/{id}`.

## 6. Counter item WO

`GET /work-order/{id}` mengembalikan counter dan nilai turunan sesuai cakupan role. Supervisor hanya menerima data tahap miliknya; Supervisor Finishing juga menerima data packing:

| Field                                                                                                         | Arti                                             |
| ------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| `qty_order`, `qty_batas_potong`                                                                               | Pesanan dan batas potong                         |
| `qty_cutting`, `qty_sewing`, `qty_finishing`                                                                  | Hasil per tahap                                  |
| `qty_qc_diperiksa`, `qty_qc_lolos`, `qty_qc_reject`                                                           | Hasil inspeksi                                   |
| `qty_repair_open`, `qty_repair_selesai`                                                                       | Repair berjalan dan selesai                      |
| `qty_packing`, `qty_terkirim`                                                                                 | Dikemas dan terkirim                             |
| `cutting_boleh`, `sewing_tersedia`, `finishing_tersedia`, `qc_tersedia`, `packing_tersedia`, `kirim_tersedia` | Batas yang boleh diinput pada langkah berikutnya |

`kirim_tersedia` dihitung sebagai `qty_packing - qty_terkirim - qty dalam koli pada pengiriman berstatus disiapkan`; jadi koli yang sudah terikat pada surat jalan draft tidak dapat dipilih lagi. Frontend sebaiknya membatasi input qty sesuai nilai turunan ini. Backend tetap menolak dengan `QTY_MELEBIHI_BATAS`.

## 7. Berkas dan PDF

- Lampiran order: `POST /order/{id}/lampiran` (`multipart/form-data`, field `file`): jpg, jpeg, png, pdf, xlsx.
- Bukti terima: `POST /pengiriman/{id}/diterima` (`multipart/form-data`, field `bukti`): jpg, jpeg, png, pdf.
- Batas ukuran memakai pengaturan `maks_lampiran_mb` (default 5 MB). Berkas disimpan di disk privat.
- Unduhan (lampiran, bukti terima, PDF) membutuhkan header `Authorization`, jadi unduh sebagai **blob** lalu buat tautan sementara. PDF yang tersedia: `GET /order/{id}/cetak`, `GET /work-order/{id}/cetak`, `GET /po/{id}/cetak`, `GET /penerimaan/{id}/cetak`, `GET /pengeluaran-bahan/{id}/cetak`, `GET /packing/{id}/label`, `GET /pengiriman/{id}/cetak`, dan `GET /laporan/{jenis}?format=pdf`.

## 8. Perbedaan dari kontrak awal

Hal-hal berikut berbeda dari rencana di dokumen langkah dan perlu diperhatikan frontend:

1. Status dikirim sebagai string, bukan objek `{value, label, color}`. Label dari `GET /enum`.
2. Paginasi berbentuk datar (lihat bagian 1).
3. Kode `STATUS_TIDAK_VALID` memakai HTTP 422 atau 409 sesuai konteks konflik. Gunakan `code`, bukan status HTTP saja, untuk membedakan aturan bisnis.
4. `PUT` dan `PATCH` sama-sama tersedia untuk update resource `bahan`, `produk`, `pengguna`, `order`, `po`, dan `opname` karena memakai `Route::apiResource`. Payload dan aturan validasinya sama.
5. `PUT` dan `PATCH /opname/{id}` hanya dapat mengubah draft milik pembuat atau Admin. Snapshot stok sistem dan selisih dihitung ulang saat draft disimpan. Perubahan BOM, termasuk salin BOM, dicatat pada audit log.

## 9. Perubahan sejak versi sebelumnya (Batch 5 ke Batch 7)

- Baru (Batch 6): `qc/antrean`, `qc/inspeksi`, `qc/inspeksi/{id}/void`, `repair`, `repair/{id}/selesai`, `packing`, `packing/{id}`, `packing/{id}/void`, `packing/{id}/label`, `work-order` (daftar dan detail), `work-order/{id}/tutup-kurang`.
- Baru (Batch 7): `order/{id}/koli-tersedia`, `pengiriman` (daftar, buat, detail), `pengiriman/{id}/kirim`, `/diterima`, `/batal`, `/cetak`, `/bukti`.
- Inspeksi QC kini mewajibkan rincian cacat per tindakan; total cacat repair harus sama dengan `qty_repair` dan total cacat reject dengan `qty_reject`.
- `POST /qc/inspeksi` menerima `tahap_tujuan_id` per item (sewing atau finishing, default sewing).
- Penerimaan bahan dan pengeluaran bahan kini dapat dilihat Manajer dan Pimpinan sesuai matriks (sebelumnya terblokir).
- Pesan error aksi bisnis kini seragam berbentuk `{ message, code }` dengan kode HTTP yang benar (sebelumnya error bisnis bisa menjadi 500).

## 10. Dashboard dan laporan (Batch 8)

**Dashboard** `GET /dashboard`: isi mengikuti role pemanggil. Baca field `data.tipe`: `eksekutif` (admin, manajer, pimpinan), `staf_order`, `gudang`, `supervisor`, `qc`. Daftar di dashboard dibatasi 10-15 baris. Reject rate dashboard dihitung untuk bulan berjalan (`periode_bulan`).

**Laporan**

- `GET /laporan` : jenis laporan yang boleh dibuka user (`jenis`, `label`).
- `GET /laporan/{jenis}?format=json|pdf|xlsx&tgl_dari=&tgl_sampai=` : default `json`, periode default bulan berjalan. Dibatasi 30 permintaan per menit per user (429).
- Jenis: `order`, `progres-wo`, `produksi-harian`, `wip`, `reject`, `repair`, `stok-bahan`, `kartu-stok`, `kebutuhan-vs-stok`, `pemakaian-bahan`, `pembelian`, `penerimaan`, `pengiriman`, `on-time-delivery`, `opname`, `audit-log`.
- Laporan snapshot (`wip`, `stok-bahan`, `kebutuhan-vs-stok`) tidak memakai periode (`periode: null`).
- Filter tambahan: `status`, `pelanggan_id`, `supplier_id`, `bahan_id` (wajib untuk `kartu-stok`), `produk_id`, `tahap_id`, `line_id`, `user_id`, `aksi`, `subjek_tipe`, `jenis_bahan`, `di_bawah_minimum=1`, `kelompok=tahap|produk|line|jenis_cacat` (khusus `reject`).
- Bentuk JSON: `{ data: { jenis, judul, periode, kolom[{key,label,tipe}], ringkasan[{label,nilai,tipe}], baris[] }, meta: { current_page, per_page, total, last_page, terpotong } }`. Paginasi memakai `page` dan `per_page` (maks 500). Tipe kolom: `text, int, dec, money, pct, date, datetime, bool, status`. Status dikirim sebagai nilai mentah.
- PDF dan Excel diunduh sebagai blob dengan header Authorization. PDF dibatasi 1.500 baris, selebihnya gunakan Excel. Batas data 20.000 baris (`meta.terpotong`).

Hak akses: admin, manajer, pimpinan semua (audit-log hanya admin dan manajer); staf_order: order, pengiriman; gudang: stok-bahan, kartu-stok, kebutuhan-vs-stok, pemakaian-bahan, pembelian, penerimaan, pengiriman, opname; supervisor: progres-wo, produksi-harian, wip, reject, repair (hanya tahapnya, kolom tahap lain dan hilir tidak dikirim); qc: reject, repair.

Definisi angka: kekurangan (progres-wo) = max(0, qty_order - lolos QC); kelebihan = max(0, lolos QC - qty_order); reject rate QC memakai hasil = qty lolos; on-time delivery memakai tanggal kirim terakhir (SJ dikirim/diterima) <= deadline; laporan progres-wo dan pemakaian-bahan difilter menurut `tgl_order`.

## 11. Akun demo, seeder, dan persiapan lokal

### Akun

| Username        | Role / cakupan                          | Password      |
| --------------- | --------------------------------------- | ------------- |
| `admin`         | Admin awal (`AdminSeeder`)              | `admin12345`  |
| `manajer`       | Manajer Produksi                        | `password123` |
| `order`         | Staf Order                              | `password123` |
| `gudang`        | Staf Gudang                             | `password123` |
| `spv_cutting`   | Supervisor Cutting                      | `password123` |
| `spv_sewing`    | Supervisor Sewing                       | `password123` |
| `spv_finishing` | Supervisor Finishing (termasuk packing) | `password123` |
| `qc`            | Quality Control                         | `password123` |
| `pimpinan`      | Pimpinan (read-only)                    | `password123` |

`DatabaseSeeder` membuat akun Admin awal. `DemoUserSeeder` membuat delapan akun demo selain Admin. `DemoDataSeeder` juga memanggil `DemoUserSeeder` secara otomatis.

### Menyiapkan data demo

Jalankan pada database pengembangan/demo yang boleh dikosongkan:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoDataSeeder
```

Perintah pertama **menghapus seluruh tabel dan data** sebelum menjalankan seeder dasar. Jangan jalankan pada database produksi. `DemoDataSeeder` bersifat khusus pengembangan/demo, berhenti bila data demo sudah ada, dan membentuk data melalui service aplikasi. Isinya mencakup 5 pelanggan, 5 supplier, 8 warna, 20 bahan, 8 produk dengan BOM (termasuk variasi ukuran/warna), 3 PO, dan 6 order berstatus berbeda. Enam order mencakup `draft`, `diajukan`, `menunggu_bahan`, `produksi` (dengan reject dan repair), `siap_kirim`, serta `terkirim` dengan pengiriman parsial.

### Environment dan Postman

1. Salin `.env.example` menjadi `.env`, sesuaikan koneksi MySQL lokal, lalu jalankan `php artisan key:generate`.
2. Project saat ini mensyaratkan **PHP 8.3 atau lebih baru** (`composer.json`) dan rancangan database menargetkan **MySQL 8**.
3. `FILESYSTEM_DISK=local` mengarah ke `storage/app/private`; jangan ubah ke disk `public` untuk lampiran order atau bukti terima.
4. Sesuaikan `allowed_origins` di `config/cors.php` dengan alamat frontend. Untuk produksi, gunakan hanya origin HTTPS frontend yang benar; jangan gunakan wildcard.
5. Import `SIMA.postman_collection.json` dan `SIMA.postman_environment.json`. Default `login/password` adalah akun Admin awal. Jalankan request Login untuk mengisi `token` otomatis. Ganti kedua variabel itu untuk menguji role lain.
6. ID resource di environment Postman (misalnya `order_id`, `wo_id`, `bahan_id`) merupakan nilai awal/contoh. Sesuaikan dengan ID yang benar di database aktif atau jalankan request pembuatan yang menyimpan ID otomatis.
7. Untuk pengujian beban pada database lokal khusus, jalankan `php artisan db:seed --class=PerformanceSeeder`. Seeder ini membuat 500 order/WO dan 50.000 log produksi; data tidak dihapus otomatis. Jangan jalankan pada database produksi atau database kerja yang datanya harus dipertahankan.

Token Sanctum berlaku **720 menit** sesuai `config/sanctum.php`. Untuk produksi, set `APP_ENV=production`, `APP_DEBUG=false`, kredensial database yang benar, HTTPS, origin CORS frontend produksi, serta siapkan scheduler dan queue worker sesuai dokumen langkah backend. Jangan jalankan `DemoUserSeeder` atau `DemoDataSeeder` di produksi.
