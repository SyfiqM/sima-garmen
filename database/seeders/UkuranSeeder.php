<?php

namespace Database\Seeders;

use App\Models\Ukuran;
use Illuminate\Database\Seeder;

class UkuranSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['S', 'M', 'L', 'XL', 'XXL'] as $i => $kode) {
            Ukuran::firstOrCreate(['kode' => $kode], ['urutan' => $i + 1, 'is_active' => true]);
        }
    }
}
