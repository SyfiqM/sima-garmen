<?php

namespace Database\Seeders;

use App\Models\JenisCacat;
use App\Models\Tahap;
use Illuminate\Database\Seeder;

class JenisCacatSeeder extends Seeder
{
    public function run(): void
    {
        $tahap = Tahap::pluck('id', 'kode')->mapWithKeys(fn ($id, $kode) => [$kode instanceof \BackedEnum ? $kode->value : $kode => $id]);

        // [kode, nama, kategori, tahap (null = semua tahap)]
        $data = [
            ['BLG', 'Bolong pada kain', 'kain', null],
            ['NDA', 'Noda pada kain', 'kain', null],
            ['PTM', 'Potongan miring', 'potongan', 'cutting'],
            ['UKR', 'Ukuran tidak sesuai', 'ukuran', null],
            ['JLP', 'Jahitan lompat', 'jahitan', 'sewing'],
            ['JMR', 'Jahitan miring', 'jahitan', 'sewing'],
            ['BNJ', 'Benang menjuntai', 'jahitan', 'finishing'],
            ['KNL', 'Kancing lepas', 'jahitan', 'finishing'],
            ['LBL', 'Label salah', 'lainnya', 'finishing'],
            ['NST', 'Noda setrika', 'kotor', 'finishing'],
            ['KTR', 'Kotor', 'kotor', null],
        ];

        foreach ($data as [$kode, $nama, $kategori, $kodeTahap]) {
            JenisCacat::firstOrCreate(['kode' => $kode], [
                'nama' => $nama,
                'kategori' => $kategori,
                'tahap_id' => $kodeTahap ? ($tahap[$kodeTahap] ?? null) : null,
                'is_active' => true,
            ]);
        }
    }
}
