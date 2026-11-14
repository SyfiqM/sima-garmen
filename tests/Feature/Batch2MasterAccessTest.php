<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\Bom;
use App\Models\Pelanggan;
use App\Models\Produk;
use App\Models\Satuan;
use App\Models\Tahap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch2MasterAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_forbids_qc_from_deactivating_a_customer(): void
    {
        /** @var User $qc */
        $qc = User::factory()->create(['role' => 'qc']);
        $pelanggan = Pelanggan::create(['kode' => 'PLG-01', 'nama' => 'Pelanggan', 'alamat' => '-']);

        $response = $this->actingAs($qc, 'sanctum')
            ->postJson("/api/v1/pelanggan/{$pelanggan->id}/nonaktifkan");

        $response->assertForbidden();
        $this->assertDatabaseHas('pelanggan', ['id' => $pelanggan->id, 'is_active' => true]);
    }

    public function test_forbids_supervisor_from_listing_customers(): void
    {
        /** @var User $supervisor */
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/pelanggan')
            ->assertForbidden();
    }

    public function test_forbids_staf_order_from_deleting_a_material(): void
    {
        /** @var User $stafOrder */
        $stafOrder = User::factory()->create(['role' => 'staf_order']);
        $satuan = Satuan::create(['kode' => 'MTR', 'nama' => 'Meter']);
        $bahan = Bahan::create([
            'kode' => 'BHN-01',
            'nama' => 'Kain',
            'jenis' => 'kain',
            'satuan_id' => $satuan->id,
        ]);

        $response = $this->actingAs($stafOrder, 'sanctum')
            ->deleteJson("/api/v1/bahan/{$bahan->id}");

        $response->assertForbidden();
        $this->assertModelExists($bahan);
    }

    public function test_forbids_staf_order_from_deleting_a_bom_row(): void
    {
        /** @var User $stafOrder */
        $stafOrder = User::factory()->create(['role' => 'staf_order']);
        $satuan = Satuan::create(['kode' => 'PCS', 'nama' => 'Pcs']);
        $bahan = Bahan::create(['kode' => 'BHN-02', 'nama' => 'Benang', 'jenis' => 'benang', 'satuan_id' => $satuan->id]);
        $produk = Produk::create(['kode' => 'PRD-02', 'nama' => 'Polo']);
        $tahap = Tahap::create(['kode' => 'cutting', 'nama' => 'Pemotongan', 'urutan' => 1]);
        $bom = Bom::create([
            'produk_id' => $produk->id,
            'bahan_id' => $bahan->id,
            'tahap_id' => $tahap->id,
            'qty_per_pcs' => 1,
            'waste_persen' => 0,
        ]);

        $this->actingAs($stafOrder, 'sanctum')
            ->deleteJson("/api/v1/bom/{$bom->id}")
            ->assertForbidden();

        $this->assertModelExists($bom);
    }
}
