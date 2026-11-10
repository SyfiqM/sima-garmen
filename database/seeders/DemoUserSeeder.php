<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Tahap;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    /**
     * HANYA UNTUK PENGEMBANGAN/DEMO. Jangan dijalankan di server produksi.
     * Jalankan: php artisan db:seed --class=DemoUserSeeder
     * Semua akun memakai password: password123
     */
    public function run(): void
    {
        $tahap = fn (string $kode) => Tahap::where('kode', $kode)->value('id');

        $data = [
            ['manajer', 'Manajer Produksi', Role::Manajer, null],
            ['order', 'Staf Order', Role::StafOrder, null],
            ['gudang', 'Staf Gudang', Role::Gudang, null],
            ['spv_cutting', 'Supervisor Pemotongan', Role::Supervisor, $tahap('cutting')],
            ['spv_sewing', 'Supervisor Penjahitan', Role::Supervisor, $tahap('sewing')],
            ['spv_finishing', 'Supervisor Finishing', Role::Supervisor, $tahap('finishing')],
            ['qc', 'Petugas QC', Role::Qc, null],
            ['pimpinan', 'Pimpinan', Role::Pimpinan, null],
        ];

        foreach ($data as [$username, $nama, $role, $tahapId]) {
            User::firstOrCreate(['username' => $username], [
                'name' => $nama,
                'email' => $username . '@sima.test',
                'password' => 'password123',
                'role' => $role,
                'tahap_id' => $tahapId,
                'is_active' => true,
            ]);
        }
    }
}
