<?php

namespace Database\Seeders;

use App\Models\Satuan;
use Illuminate\Database\Seeder;

class SatuanSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'm' => 'Meter', 'yd' => 'Yard', 'kg' => 'Kilogram', 'pcs' => 'Pcs',
            'roll' => 'Roll', 'cone' => 'Cone', 'lusin' => 'Lusin', 'set' => 'Set',
        ];

        foreach ($data as $kode => $nama) {
            Satuan::updateOrCreate(['kode' => $kode], ['nama' => $nama]);
        }
    }
}
