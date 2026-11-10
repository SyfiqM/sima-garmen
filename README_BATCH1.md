# Batch 1: Fondasi SIMA Garmen

Isi: 41 migration, 39 model, 16 enum, login (username/email) dengan 7 role, layout dan menu per role,
notifikasi in-app, audit log, penomoran dokumen, pengaturan, seeder dasar, komponen UI, dan tes.

## Cara memasang (PowerShell, dari folder proyek)

1. Ekstrak `batch1.zip` ke folder proyek `sima-garmen`. Pilih **Replace/Timpa** bila ada file yang sama.
2. Edit `.env`:
   - APP_NAME="SIMA Garmen"
   - APP_LOCALE=id
   - APP_TIMEZONE=Asia/Jakarta   (bila baris ini belum ada, tambahkan)
   - DB_CONNECTION=mysql, DB_DATABASE=sima_garmen, DB_USERNAME=root, DB_PASSWORD=
3. Hapus sisa tes bawaan Breeze (sudah tidak cocok dengan sistem ini):
   Remove-Item -Recurse -Force tests\Feature\Auth -ErrorAction SilentlyContinue
   Remove-Item tests\Feature\ProfileTest.php, tests\Feature\ExampleTest.php -ErrorAction SilentlyContinue
4. Buat database tes (sekali saja):
   mysql -u root -e "CREATE DATABASE sima_garmen_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
5. Bangun ulang database dan isi data awal:
   php artisan config:clear
   php artisan migrate:fresh --seed
6. (Opsional, pengembangan) akun demo semua role, password: password123
   php artisan db:seed --class=DemoUserSeeder
7. Jalankan, di dua terminal terpisah:
   npm run dev
   php artisan serve
8. Buka http://127.0.0.1:8000 lalu masuk: username `admin`, password `admin12345` (segera ganti di Profil Saya).
9. Jalankan tes: php artisan test

## Akun demo (jika langkah 6 dijalankan)
admin, manajer, order, gudang, spv_cutting, spv_sewing, spv_finishing, qc, pimpinan

## Catatan
- Menu bertanda "segera" belum punya halaman. Aktif otomatis saat route-nya dibuat di batch berikutnya.
- Route modul baru cukup ditambahkan sebagai berkas di `routes/modules/` (dimuat otomatis di dalam grup auth).
- Pembatasan role di route: ->middleware(\App\Http\Middleware\EnsureRole::class . ':manajer,gudang') (Admin selalu lolos).
