<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pelanggan;
use App\Models\Produk;
use App\Models\Satuan;
use App\Models\Tahap;
use App\Models\Ukuran;
use App\Models\User;
use App\Models\Warna;
use App\Models\WoBahan;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch5MaterialIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_issue_consumes_stock_and_reservation(): void
    {
        $fixture = $this->createFixture();
        $response = $this->actingAs($fixture['gudang'], 'sanctum')->postJson('/api/v1/pengeluaran-bahan', [
            'wo_id' => $fixture['workOrder']->id,
            'tahap_id' => $fixture['stage']->id,
            'tipe' => 'keluar',
            'tanggal' => now()->toDateString(),
            'items' => [['wo_bahan_id' => $fixture['woBahan']->id, 'qty' => 6]],
        ]);

        $response->assertCreated()->assertJsonPath('data.status_persetujuan', 'tidak_perlu');
        $this->assertDatabaseHas('bahan', ['id' => $fixture['material']->id, 'stok' => 44, 'stok_dipesan' => 4]);
        $this->assertDatabaseHas('wo_bahan', ['id' => $fixture['woBahan']->id, 'qty_keluar' => 6]);
        $this->assertDatabaseHas('stok_mutasi', ['bahan_id' => $fixture['material']->id, 'tipe' => 'keluar', 'qty' => -6]);
    }

    public function test_over_reserved_issue_waits_for_manager_approval(): void
    {
        $fixture = $this->createFixture();
        $response = $this->actingAs($fixture['gudang'], 'sanctum')->postJson('/api/v1/pengeluaran-bahan', [
            'wo_id' => $fixture['workOrder']->id,
            'tahap_id' => $fixture['stage']->id,
            'tipe' => 'keluar',
            'tanggal' => now()->toDateString(),
            'alasan_lebih' => 'Tambahan bahan untuk koreksi potong',
            'items' => [['wo_bahan_id' => $fixture['woBahan']->id, 'qty' => 10.5]],
        ]);

        $response->assertCreated()->assertJsonPath('data.status_persetujuan', 'menunggu');
        $issueId = $response->json('data.id');
        $this->assertDatabaseHas('bahan', ['id' => $fixture['material']->id, 'stok' => 50, 'stok_dipesan' => 10]);

        $this->actingAs($fixture['manager'], 'sanctum')
            ->postJson("/api/v1/pengeluaran-bahan/{$issueId}/setujui")
            ->assertOk()
            ->assertJsonPath('data.status_persetujuan', 'disetujui');

        $this->assertDatabaseHas('bahan', ['id' => $fixture['material']->id, 'stok' => 39.5, 'stok_dipesan' => 0]);
        $this->assertDatabaseHas('wo_bahan', ['id' => $fixture['woBahan']->id, 'qty_keluar' => 10.5]);
    }

    public function test_void_issue_reverses_stock_and_restores_reservation(): void
    {
        $fixture = $this->createFixture();
        $issueResponse = $this->actingAs($fixture['gudang'], 'sanctum')->postJson('/api/v1/pengeluaran-bahan', [
            'wo_id' => $fixture['workOrder']->id,
            'tahap_id' => $fixture['stage']->id,
            'tipe' => 'keluar',
            'tanggal' => now()->toDateString(),
            'items' => [['wo_bahan_id' => $fixture['woBahan']->id, 'qty' => 6]],
        ]);
        $issueResponse->assertCreated();

        $issueId = $issueResponse->json('data.id');
        $this->actingAs($fixture['gudang'], 'sanctum')
            ->postJson("/api/v1/pengeluaran-bahan/{$issueId}/void", ['alasan' => 'Salah catat jumlah'])
            ->assertOk();

        $this->assertDatabaseHas('pengeluaran_bahan', ['id' => $issueId, 'status' => 'void']);
        $this->assertDatabaseHas('bahan', ['id' => $fixture['material']->id, 'stok' => 50, 'stok_dipesan' => 10]);
        $this->assertDatabaseHas('wo_bahan', ['id' => $fixture['woBahan']->id, 'qty_keluar' => 0]);
        $this->assertDatabaseHas('stok_mutasi', ['bahan_id' => $fixture['material']->id, 'tipe' => 'koreksi', 'qty' => 6]);
    }

    private function createFixture(): array
    {
        $gudang = User::factory()->create(['role' => 'gudang']);
        $manager = User::factory()->create(['role' => 'manajer']);
        $creator = User::factory()->create(['role' => 'admin']);
        $stage = Tahap::create(['kode' => 'cutting', 'nama' => 'Pemotongan', 'urutan' => 1]);
        $unit = Satuan::create(['kode' => 'MTR', 'nama' => 'Meter']);
        $material = Bahan::create([
            'kode' => 'BHN-01', 'nama' => 'Kain', 'jenis' => 'kain', 'satuan_id' => $unit->id,
            'stok' => 50, 'stok_dipesan' => 10,
        ]);
        $customer = Pelanggan::create(['kode' => 'PLG-01', 'nama' => 'Pelanggan', 'alamat' => '-']);
        $product = Produk::create(['kode' => 'PRD-01', 'nama' => 'Kaos']);
        $color = Warna::create(['nama' => 'Hitam']);
        $size = Ukuran::create(['kode' => 'M']);
        $order = Order::create([
            'no_order' => 'ORD-01', 'pelanggan_id' => $customer->id, 'tgl_order' => now(),
            'deadline' => now()->addDays(7), 'status' => 'siap_produksi', 'dibuat_oleh' => $creator->id,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id, 'produk_id' => $product->id, 'warna_id' => $color->id,
            'ukuran_id' => $size->id, 'qty' => 10,
        ]);
        $workOrder = WorkOrder::create([
            'no_wo' => 'WO-01', 'order_id' => $order->id, 'status' => 'aktif',
            'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $creator->id,
        ]);
        $woBahan = WoBahan::create([
            'wo_id' => $workOrder->id, 'bahan_id' => $material->id, 'tahap_id' => $stage->id,
            'qty_rencana' => 10, 'qty_reservasi' => 10,
        ]);

        return compact('gudang', 'manager', 'stage', 'material', 'workOrder', 'woBahan');
    }
}
