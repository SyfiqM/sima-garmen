<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Data awal sistem. Aman dijalankan berulang (tidak menimpa data yang sudah diubah). */
    public function run(): void
    {
        $this->call([
            TahapSeeder::class,
            SatuanSeeder::class,
            UkuranSeeder::class,
            JenisCacatSeeder::class,
            PengaturanSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
