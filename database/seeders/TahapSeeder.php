<?php

namespace Database\Seeders;

use App\Models\Tahap;
use Illuminate\Database\Seeder;

class TahapSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['cutting', 'Pemotongan', 1], ['sewing', 'Penjahitan', 2], ['finishing', 'Finishing', 3]] as [$kode, $nama, $urutan]) {
            Tahap::updateOrCreate(['kode' => $kode], ['nama' => $nama, 'urutan' => $urutan, 'is_active' => true]);
        }
    }
}
