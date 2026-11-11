# Catatan API SIMA Garmen

Base URL lokal: `http://127.0.0.1:8000/api/v1`  
Autentikasi: `Authorization: Bearer <token>` kecuali endpoint yang ditandai publik.  
Kirim `Accept: application/json`; tanggal memakai ISO 8601 dan zona waktu bisnis Asia/Jakarta.

## Format

- Respons tunggal memakai `{ "data": ... }`.
- Daftar memakai paginasi Laravel dengan `data`, `links`, dan `meta`.
- Validasi memakai `message` dan `errors`; aturan bisnis dapat menambahkan `code`.
- Qty bahan maksimal tiga desimal. Pengeluaran/produksi diproses dalam transaksi dan memakai row lock.

## Kode Error Bisnis

`STATUS_TIDAK_VALID`, `STOK_TIDAK_CUKUP`, `STOK_DI_BAWAH_RESERVASI`, `QTY_MELEBIHI_BATAS`, `BAHAN_TAHAP_BELUM_LENGKAP`, `TAHAP_SUDAH_DITUTUP`, `VOID_DITOLAK_HILIR_NEGATIF`, `BOM_BELUM_LENGKAP`, `ALASAN_WAJIB`, `MELEBIHI_TOLERANSI`, `DATA_SUDAH_DIPAKAI`, `TIDAK_ADA_HAK`.

## Alur Batch 1B–5

1. Login melalui `POST /auth/login`; simpan `data.token` sebagai Bearer token.
2. Muat `GET /me`, `GET /menu`, dan `GET /enum` untuk identitas, menu role, dan enum.
3. Kelola master serta BOM; pratinjau BOM melalui `GET /produk/{id}/bom/pratinjau?ukuran={id}&warna={id}&qty=100`.
4. Buat order draft, ajukan, lalu Admin/Manajer menyetujui. Persetujuan membuat WO dan snapshot BOM.
5. Periksa stok dan kekurangan; Gudang membuat PO, memesan, dan mencatat penerimaan. Penerimaan tanpa PO wajib menyertakan `alasan_tanpa_po`.
6. Gudang mencatat pengeluaran melalui `POST /pengeluaran-bahan`. Pengeluaran lebih reservasi berstatus `menunggu`; Manajer/Admin menyetujuinya lewat `POST /pengeluaran-bahan/{id}/setujui`.
7. Supervisor memakai `GET /produksi/antrean` lalu `POST /produksi/log`. Supervisor hanya dapat menginput tahap pada `users.tahap_id`.

## Endpoint Batch 5

- `GET /pengeluaran-bahan/bahan-wo?wo_id={id}&tahap_id={id}` menampilkan sisa bahan tahap.
- `POST /pengeluaran-bahan/{id}/void` membalik transaksi dengan alasan minimal 5 karakter.
- `POST /produksi/log/{id}/void` membalik counter bila tidak membuat ketersediaan hilir negatif.
- `POST /work-order/{id}/tahap/{kode}/tutup` menutup tahap; alasan wajib bila masih ada antrean.
- `POST /work-order/{id}/tahap/{kode}/buka` dan `POST /work-order/{id}/tambah-potong` khusus Admin/Manajer dengan alasan.

## Berkas

Lampiran order diunggah sebagai `multipart/form-data` ke `POST /order/{id}/lampiran`. Format yang diterima JPG, PNG, PDF, dan XLSX; berkas disimpan di disk privat. Unduh memakai `GET /order/{id}/lampiran/{lampiran}/unduh` dengan token.

Dokumen OpenAPI dan koleksi/environment Postman tersedia di direktori `docs`.
