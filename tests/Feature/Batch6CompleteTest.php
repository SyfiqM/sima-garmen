<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Order;
use App\Models\WorkOrder;
use App\Models\WoItem;
use App\Models\WoTahap;
use App\Models\QcRepair;
use App\Services\QcService;
use App\Services\PackingService;
use App\Services\WorkOrderService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

class Batch6CompleteTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $manajer;
    protected $supervisor;
    protected $qc;
    
    protected $wo;
    protected $woItem;

    protected $qcService;
    protected $packingService;
    protected $woService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Buat user sesuai role
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manajer = User::factory()->create(['role' => 'manajer']);
        $this->supervisor = User::factory()->create(['role' => 'supervisor']);
        $this->qc = User::factory()->create(['role' => 'qc']);
        
        // Setup data penomoran dummy agar NumberingService tidak error
        DB::table('penomoran')->insert([
            ['kode' => 'QC', 'periode' => date('Ym'), 'nomor_terakhir' => 0],
            ['kode' => 'PK', 'periode' => date('Ym'), 'nomor_terakhir' => 0],
            ['kode' => 'WO', 'periode' => date('Ym'), 'nomor_terakhir' => 0],
        ]);

        // Setup Order & WO
        $order = Order::create([
            'no_order' => 'ORD-TEST-06', 'pelanggan_id' => 1, 'tgl_order' => now(), 
            'deadline' => now()->addDays(7), 'status' => 'produksi', 'dibuat_oleh' => $this->admin->id
        ]);

        $this->wo = WorkOrder::create([
            'no_wo' => 'WO-TEST-06', 'order_id' => $order->id, 'revisi_ke' => 0, 
            'status' => 'aktif', 'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $this->admin->id
        ]);

        WoTahap::insert([
            ['wo_id' => $this->wo->id, 'tahap_id' => 1, 'status' => 'selesai'],
            ['wo_id' => $this->wo->id, 'tahap_id' => 2, 'status' => 'selesai'],
            ['wo_id' => $this->wo->id, 'tahap_id' => 3, 'status' => 'berjalan']
        ]);

        // Simulasi barang sudah selesai di finishing sebanyak 100 pcs dan siap di-QC
        $this->woItem = WoItem::create([
            'wo_id' => $this->wo->id, 'order_item_id' => 1, 'qty_order' => 100, 
            'qty_batas_potong' => 100, 
            'qty_finishing' => 100, // <-- qc_tersedia = 100
            'qty_qc_diperiksa' => 0,
            'qty_qc_lolos' => 0,
            'qty_qc_reject' => 0,
            'qty_repair_open' => 0,
            'qty_repair_selesai' => 0,
            'qty_packing' => 0
        ]);

        $this->woService = app(WorkOrderService::class);
        $this->qcService = app(QcService::class);
        $this->packingService = new PackingService($this->woService);
    }

    public function test_validasi_qc_menolak_input_jika_rumus_salah_atau_melebihi_tersedia()
    {
        // 1. Uji rumus lolos + repair + reject != diperiksa
        try {
            $this->qcService->catatInspeksi($this->wo, [
                ['wo_item_id' => $this->woItem->id, 'qty_diperiksa' => 50, 'qty_lolos' => 40, 'qty_repair' => 0, 'qty_reject' => 0]
            ], now()->format('Y-m-d'), $this->qc->id);
            $this->fail('Seharusnya gagal karena total tidak sama.');
        } catch (BusinessRuleException $e) {
            $this->assertEquals('VALIDASI_GAGAL', $e->getCodeBisnis());
        }

        // 2. Uji melebihi qc_tersedia (100)
        try {
            $this->qcService->catatInspeksi($this->wo, [
                ['wo_item_id' => $this->woItem->id, 'qty_diperiksa' => 105, 'qty_lolos' => 105, 'qty_repair' => 0, 'qty_reject' => 0]
            ], now()->format('Y-m-d'), $this->qc->id);
            $this->fail('Seharusnya gagal karena melebihi qty finishing.');
        } catch (BusinessRuleException $e) {
            $this->assertEquals('QTY_MELEBIHI_BATAS', $e->getCodeBisnis());
        }
    }

    public function test_alur_mulus_qc_lalu_packing_otomatis_selesai()
    {
        // 1. QC 100 lolos semua
        $this->qcService->catatInspeksi($this->wo, [
            ['wo_item_id' => $this->woItem->id, 'qty_diperiksa' => 100, 'qty_lolos' => 100, 'qty_repair' => 0, 'qty_reject' => 0]
        ], now()->format('Y-m-d'), $this->qc->id);

        // 2. Packing 100 pcs
        $this->packingService->buatKoli($this->wo, [
            ['wo_item_id' => $this->woItem->id, 'qty' => 100]
        ], now()->format('Y-m-d'), null, $this->supervisor->id);

        // 3. Pastikan WO otomatis selesai dan Order siap_kirim
        $this->assertDatabaseHas('work_orders', ['id' => $this->wo->id, 'status' => 'selesai']);
        $this->assertDatabaseHas('orders', ['id' => $this->wo->order_id, 'status' => 'siap_kirim']);
    }

    public function test_alur_kompleks_qc_repair_reject_lalu_tutup_kurang()
    {
        // Skenario UAT 9, 10, 11 (Rancangan SIMA Garmen)

        // 1. QC 100 pcs: 90 lolos, 6 repair, 4 reject
        $this->qcService->catatInspeksi($this->wo, [
            ['wo_item_id' => $this->woItem->id, 'qty_diperiksa' => 100, 'qty_lolos' => 90, 'qty_repair' => 6, 'qty_reject' => 4]
        ], now()->format('Y-m-d'), $this->qc->id);

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_qc_lolos' => 90, 'qty_repair_open' => 6, 'qty_qc_reject' => 4]);
        
        $repairDoc = QcRepair::where('wo_item_id', $this->woItem->id)->first();
        $this->assertNotNull($repairDoc);

        // 2. Coba tutup kurang saat repair masih gantung -> Harus ditolak
        try {
            $this->woService->tutupKurang($this->wo, 'Kurang karena reject', $this->manajer->id);
            $this->fail('Seharusnya gagal tutup kurang karena ada repair_open');
        } catch (BusinessRuleException $e) {}

        // 3. Supervisor menyelesaikan repair 6 pcs
        $this->qcService->selesaikanRepair($repairDoc->id, 6, $this->supervisor->id);
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_repair_open' => 0, 'qty_repair_selesai' => 6]);

        // 4. QC periksa ulang 6 pcs hasil repair: 6 lolos semua
        $this->qcService->catatInspeksi($this->wo, [
            ['wo_item_id' => $this->woItem->id, 'qty_diperiksa' => 6, 'qty_lolos' => 6, 'qty_repair' => 0, 'qty_reject' => 0]
        ], now()->format('Y-m-d'), $this->qc->id);
        
        // Total lolos sekarang harus 96
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_qc_lolos' => 96]);

        // 5. Packing 96 pcs
        $this->packingService->buatKoli($this->wo, [
            ['wo_item_id' => $this->woItem->id, 'qty' => 96]
        ], now()->format('Y-m-d'), null, $this->supervisor->id);

        // Karena target 100 tapi dipacking 96, status WO masih 'aktif'
        $this->assertDatabaseHas('work_orders', ['id' => $this->wo->id, 'status' => 'aktif']);

        // 6. Manajer Tutup Kurang karena 4 barang memang direject di awal
        $this->woService->tutupKurang($this->wo, '4 pcs reject final, tutup produksi', $this->manajer->id);

        // Verifikasi hasil akhir Tutup Kurang
        $this->assertDatabaseHas('work_orders', ['id' => $this->wo->id, 'status' => 'ditutup_kurang']);
        $this->assertDatabaseHas('orders', ['id' => $this->wo->order_id, 'status' => 'siap_kirim', 'ditutup_kurang' => 1]);
    }
}