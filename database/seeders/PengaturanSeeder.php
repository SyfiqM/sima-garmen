<?php

namespace Database\Seeders;

use App\Models\Pengaturan;
use Illuminate\Database\Seeder;

class PengaturanSeeder extends Seeder
{
    public function run(): void
    {
        // [key, nilai awal, deskripsi]
        $data = [
            ['nama_perusahaan', 'SIMA Garmen', 'Nama perusahaan (kop dokumen cetak)'],
            ['alamat_perusahaan', '', 'Alamat perusahaan (kop dokumen cetak)'],
            ['telepon_perusahaan', '', 'Telepon perusahaan (kop dokumen cetak)'],
            ['logo_path', '', 'Lokasi berkas logo perusahaan'],
            ['toleransi_potong_persen', '3', 'Toleransi potong lebih dari qty order (%)'],
            ['toleransi_bahan_persen', '5', 'Toleransi pengeluaran bahan melebihi rencana (%)'],
            ['toleransi_terima_po_persen', '10', 'Toleransi penerimaan bahan melebihi qty PO (%)'],
            ['default_waste_persen', '5', 'Waste default pada baris BOM baru (%)'],
            ['buffer_kirim_hari', '2', 'Selisih hari target WO dari deadline order'],
            ['deadline_alert_hari', '3', 'Peringatan deadline H-n'],
            ['backdate_hari', '3', 'Batas mundur tanggal input produksi (hari)'],
            ['batas_void_jam', '24', 'Batas jam pembuat boleh membatalkan inputnya sendiri'],
            ['maks_lampiran_mb', '5', 'Ukuran maksimal lampiran (MB)'],
        ];

        foreach ($data as [$key, $value, $deskripsi]) {
            Pengaturan::firstOrCreate(['key' => $key], ['value' => $value, 'deskripsi' => $deskripsi]);
        }
    }
}
