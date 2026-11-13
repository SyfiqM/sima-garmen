<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hak lihat sesuai matriks 3.2: Manajer dan Pimpinan melihat penerimaan bahan;
 * Pimpinan melihat pengeluaran bahan.
 */
class Batch4AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function sebagai(string $role): static
    {
        return $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
    }

    public function test_penerimaan_bahan_dapat_dilihat_manajer_gudang_pimpinan_dan_admin(): void
    {
        foreach (['admin', 'manajer', 'gudang', 'pimpinan'] as $role) {
            $this->sebagai($role)->getJson('/api/v1/penerimaan')->assertOk();
        }
    }

    public function test_penerimaan_bahan_ditolak_untuk_role_lain(): void
    {
        foreach (['staf_order', 'supervisor', 'qc'] as $role) {
            $this->sebagai($role)->getJson('/api/v1/penerimaan')->assertForbidden();
        }
    }

    public function test_pengeluaran_bahan_dapat_dilihat_pimpinan_tetapi_tidak_qc_atau_staf_order(): void
    {
        $this->sebagai('pimpinan')->getJson('/api/v1/pengeluaran-bahan')->assertOk();
        $this->sebagai('manajer')->getJson('/api/v1/pengeluaran-bahan')->assertOk();

        foreach (['staf_order', 'qc'] as $role) {
            $this->sebagai($role)->getJson('/api/v1/pengeluaran-bahan')->assertForbidden();
        }
    }
}