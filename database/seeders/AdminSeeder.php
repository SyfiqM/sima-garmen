<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /** Akun Admin awal. WAJIB ganti password setelah login pertama (menu Profil Saya). */
    public function run(): void
    {
        User::firstOrCreate(['username' => 'admin'], [
            'name' => 'Administrator',
            'email' => 'admin@sima.test',
            'password' => 'admin12345',
            'role' => Role::Admin,
            'is_active' => true,
        ]);
    }
}
