<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pelanggan;
use App\Models\Produk;
use App\Models\Tahap;
use App\Models\Ukuran;
use App\Models\User;
use App\Models\Warna;
use App\Models\WoItem;
use App\Models\WorkOrder;
use App\Models\WoTahap;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch5ProductionTest extends TestCase
{
    use RefreshDatabase;

    protected $supervisor;

    protected $supervisorSewing;

    protected $wo;

    protected $woItem;

    protected $productionService;

    protected array $tahapIds;

    protected function setUp(): void
    {
        parent::setUp();

        $pembuat = User::factory()->create();
        $this->tahapIds = [];

        foreach ([['cutting', 'Pemotongan', 1], ['sewing', 'Penjahitan', 2], ['finishing', 'Finishing', 3]] as [$kode, $nama, $urutan]) {
            $tahap = Tahap::create(['kode' => $kode, 'nama' => $nama, 'urutan' => $urutan]);
            $this->tahapIds[$kode] = $tahap->id;
        }

        $this->supervisor = User::factory()->create([
            'role' => 'supervisor',
            'tahap_id' => $this->tahapIds['cutting'],
        ]);
        $this->supervisorSewing = User::factory()->create([
            'role' => 'supervisor',
            'tahap_id' => $this->tahapIds['sewing'],
        ]);

        $pelanggan = Pelanggan::create([
            'kode' => 'PLG-001',
            'nama' => 'Pelanggan Tes',
            'alamat' => 'Alamat Tes',
        ]);
        $produk = Produk::create(['kode' => 'PRD-001', 'nama' => 'Produk Tes']);
        $warna = Warna::create(['nama' => 'Hitam']);
        $ukuran = Ukuran::create(['kode' => 'M']);

        $order = Order::create([
            'no_order' => 'ORD-001', 'pelanggan_id' => $pelanggan->id, 'tgl_order' => now(),
            'deadline' => now()->addDays(7), 'status' => 'siap_produksi', 'dibuat_oleh' => $pembuat->id,
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'produk_id' => $produk->id,
            'warna_id' => $warna->id,
            'ukuran_id' => $ukuran->id,
            'qty' => 100,
        ]);

        $this->wo = WorkOrder::create([
            'no_wo' => 'WO-001', 'order_id' => $order->id, 'revisi_ke' => 0,
            'status' => 'aktif', 'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $pembuat->id,
        ]);

        foreach ($this->tahapIds as $tahapId) {
            WoTahap::create(['wo_id' => $this->wo->id, 'tahap_id' => $tahapId, 'status' => 'menunggu']);
        }

        $this->woItem = WoItem::create([
            'wo_id' => $this->wo->id, 'order_item_id' => $orderItem->id, 'qty_order' => 100,
            'qty_batas_potong' => 105, // Toleransi 5 pcs
            'qty_cutting' => 0, 'qty_cutting_reject' => 0,
            'qty_sewing' => 0, 'qty_sewing_reject' => 0,
        ]);

        $this->productionService = new ProductionService;
    }

    public function test_input_produksi_sesuai_batas_berhasil()
    {
        // 1. Input Cutting 50 pcs (Batas 105)
        $this->productionService->catatProduksi($this->wo, $this->tahapIds['cutting'], [
            ['wo_item_id' => $this->woItem->id, 'qty_hasil' => 50, 'qty_reject' => 0],
        ], now()->format('Y-m-d'), $this->supervisor->id);

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_cutting' => 50]);
        $this->assertDatabaseHas('orders', ['id' => $this->wo->order_id, 'status' => 'produksi']);

        // 2. Input Sewing 40 pcs (Batas dari hasil cutting: 50)
        $this->productionService->catatProduksi($this->wo, $this->tahapIds['sewing'], [
            ['wo_item_id' => $this->woItem->id, 'qty_hasil' => 40, 'qty_reject' => 0],
        ], now()->format('Y-m-d'), $this->supervisorSewing->id);

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_sewing' => 40]);
    }

    public function test_input_produksi_melebihi_hilir_ditolak()
    {
        // Set hasil cutting = 50
        $this->woItem->update(['qty_cutting' => 50]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/Input melebihi batas ketersediaan/');

        // Coba jahit 60 pcs (padahal potongan baru ada 50)
        $this->productionService->catatProduksi($this->wo, $this->tahapIds['sewing'], [
            ['wo_item_id' => $this->woItem->id, 'qty_hasil' => 60, 'qty_reject' => 0],
        ], now()->format('Y-m-d'), $this->supervisorSewing->id);
    }

    public function test_supervisor_can_record_stage_output_through_api(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/produksi/log', [
                'wo_id' => $this->wo->id,
                'tahap_id' => $this->tahapIds['cutting'],
                'tanggal' => now()->toDateString(),
                'items' => [[
                    'wo_item_id' => $this->woItem->id,
                    'qty_hasil' => 25,
                    'qty_reject' => 0,
                ]],
            ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['log_ids']]);

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_cutting' => 25]);
        $this->assertDatabaseHas('orders', ['id' => $this->wo->order_id, 'status' => 'produksi']);
    }

    public function test_forbids_supervisor_from_recording_another_stage(): void
    {
        $this->actingAs($this->supervisorSewing, 'sanctum')
            ->postJson('/api/v1/produksi/log', [
                'wo_id' => $this->wo->id,
                'tahap_id' => $this->tahapIds['cutting'],
                'tanggal' => now()->toDateString(),
                'items' => [[
                    'wo_item_id' => $this->woItem->id,
                    'qty_hasil' => 25,
                    'qty_reject' => 0,
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('produksi_log', 0);
    }

    public function test_void_log_berhasil_dan_tercatat_di_audit()
    {
        $logIds = array_values((array) $this->productionService->catatProduksi($this->wo, $this->tahapIds['cutting'], [
            ['wo_item_id' => $this->woItem->id, 'qty_hasil' => 50, 'qty_reject' => 0],
        ], now()->format('Y-m-d'), $this->supervisor->id));

        $this->productionService->voidLog($logIds[0], 'Salah input jumlah', $this->supervisor->id);

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_cutting' => 0]);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'produksi.void', 'subjek_tipe' => 'ProduksiLog', 'subjek_id' => $logIds[0]]);
    }

    public function test_void_cutting_ditolak_jika_sudah_dijahit()
    {
        $logIds = array_values((array) $this->productionService->catatProduksi($this->wo, $this->tahapIds['cutting'], [
            ['wo_item_id' => $this->woItem->id, 'qty_hasil' => 50, 'qty_reject' => 0],
        ], now()->format('Y-m-d'), $this->supervisor->id));

        $this->productionService->catatProduksi($this->wo, $this->tahapIds['sewing'], [
            ['wo_item_id' => $this->woItem->id, 'qty_hasil' => 40, 'qty_reject' => 0],
        ], now()->format('Y-m-d'), $this->supervisorSewing->id);

        try {
            $this->productionService->voidLog($logIds[0], 'Salah input jumlah', $this->supervisor->id);
            $this->fail('Seharusnya ditolak karena hasil cutting sudah dijahit.');
        } catch (BusinessRuleException $e) {
            $this->assertEquals('VOID_DITOLAK_HILIR_NEGATIF', $e->getCodeBisnis());
        }

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_cutting' => 50]);
    }

    public function test_tambah_potong_tutup_dan_buka_tahap_oleh_manajer_tercatat_di_audit()
    {
        $manajer = User::factory()->create(['role' => 'manajer']);

        $item = $this->productionService->tambahPotong($this->woItem->id, 5, 'Reject cutting 5 pcs', $manajer->id);
        $this->assertEquals(110, $item->qty_batas_potong);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'produksi.tambah_potong', 'subjek_tipe' => 'WoItem', 'subjek_id' => $this->woItem->id]);

        $this->productionService->tutupTahap($this->wo->id, 'cutting', 'Sisa antrean tidak dipotong', $manajer->id);
        $this->assertDatabaseHas('wo_tahap', ['wo_id' => $this->wo->id, 'tahap_id' => $this->tahapIds['cutting'], 'status' => 'selesai']);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'produksi.tahap_tutup', 'subjek_tipe' => 'WorkOrder', 'subjek_id' => $this->wo->id]);

        $this->productionService->bukaTahap($this->wo->id, 'cutting', 'Perlu potong tambahan', $manajer->id);
        $this->assertDatabaseHas('wo_tahap', ['wo_id' => $this->wo->id, 'tahap_id' => $this->tahapIds['cutting'], 'status' => 'berjalan']);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'produksi.tahap_buka', 'subjek_tipe' => 'WoTahap']);
    }
}