<?php

namespace Tests\Feature;

use App\Models\JenisCacat;
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
use App\Services\QcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch6ApiTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected $manajer;

    protected $gudang;

    protected $qc;

    protected $supervisorFinishing;

    protected $supervisorSewing;

    protected $wo;

    protected $order;

    protected $orderItem;

    protected $woItem;

    protected $jenisCacat;

    protected array $tahapIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['cutting', 'Pemotongan', 1], ['sewing', 'Penjahitan', 2], ['finishing', 'Finishing', 3]] as [$kode, $nama, $urutan]) {
            $this->tahapIds[$kode] = Tahap::create(['kode' => $kode, 'nama' => $nama, 'urutan' => $urutan])->id;
        }

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manajer = User::factory()->create(['role' => 'manajer']);
        $this->gudang = User::factory()->create(['role' => 'gudang']);
        $this->qc = User::factory()->create(['role' => 'qc']);
        $this->supervisorFinishing = User::factory()->create(['role' => 'supervisor', 'tahap_id' => $this->tahapIds['finishing']]);
        $this->supervisorSewing = User::factory()->create(['role' => 'supervisor', 'tahap_id' => $this->tahapIds['sewing']]);

        $pelanggan = Pelanggan::create(['kode' => 'PLG-001', 'nama' => 'Pelanggan Tes', 'alamat' => 'Alamat Tes']);
        $produk = Produk::create(['kode' => 'PRD-001', 'nama' => 'Produk Tes']);
        $warna = Warna::create(['nama' => 'Hitam']);
        $ukuran = Ukuran::create(['kode' => 'M']);
        $this->jenisCacat = JenisCacat::create(['kode' => 'JH-01', 'nama' => 'Jahitan putus', 'kategori' => 'jahitan']);

        $this->order = Order::create([
            'no_order' => 'ORD-TEST-6A', 'pelanggan_id' => $pelanggan->id, 'tgl_order' => now(),
            'deadline' => now()->addDays(7), 'status' => 'produksi', 'dibuat_oleh' => $this->admin->id,
        ]);
        $this->orderItem = OrderItem::create([
            'order_id' => $this->order->id, 'produk_id' => $produk->id,
            'warna_id' => $warna->id, 'ukuran_id' => $ukuran->id, 'qty' => 100,
        ]);

        $this->wo = $this->buatWo('WO-TEST-6A');
        $this->woItem = $this->buatWoItem($this->wo);
    }

    private function buatWo(string $noWo): WorkOrder
    {
        $wo = WorkOrder::create([
            'no_wo' => $noWo, 'order_id' => $this->order->id, 'revisi_ke' => 0,
            'status' => 'aktif', 'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $this->admin->id,
        ]);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['cutting'], 'status' => 'selesai']);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['sewing'], 'status' => 'selesai']);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['finishing'], 'status' => 'berjalan']);

        return $wo;
    }

    private function buatWoItem(WorkOrder $wo): WoItem
    {
        return WoItem::create([
            'wo_id' => $wo->id, 'order_item_id' => $this->orderItem->id, 'qty_order' => 100,
            'qty_batas_potong' => 100, 'qty_cutting' => 100, 'qty_sewing' => 100, 'qty_finishing' => 100,
        ]);
    }

    private function inspeksiLolos(int $qty = 100)
    {
        return app(QcService::class)->catatInspeksi($this->wo, [
            ['wo_item_id' => $this->woItem->id, 'qty_diperiksa' => $qty, 'qty_lolos' => $qty, 'qty_repair' => 0, 'qty_reject' => 0],
        ], now()->format('Y-m-d'), $this->qc->id);
    }

    private function payloadInspeksi(): array
    {
        return [
            'wo_id' => $this->wo->id,
            'tanggal' => now()->format('Y-m-d'),
            'items' => [[
                'wo_item_id' => $this->woItem->id, 'qty_diperiksa' => 100, 'qty_lolos' => 90, 'qty_repair' => 6, 'qty_reject' => 4,
                'cacat' => [
                    ['jenis_cacat_id' => $this->jenisCacat->id, 'qty' => 6, 'tindakan' => 'repair'],
                    ['jenis_cacat_id' => $this->jenisCacat->id, 'qty' => 4, 'tindakan' => 'reject'],
                ],
            ]],
        ];
    }

    private function payloadKoli(int $qty, ?int $woId = null, ?int $woItemId = null): array
    {
        return [
            'wo_id' => $woId ?? $this->wo->id,
            'tanggal' => now()->format('Y-m-d'),
            'berat_kg' => 12.5,
            'items' => [['wo_item_id' => $woItemId ?? $this->woItem->id, 'qty' => $qty]],
        ];
    }

    public function test_qc_mencatat_inspeksi_lewat_api_dan_role_lain_ditolak(): void
    {
        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson('/api/v1/qc/inspeksi', $this->payloadInspeksi())
            ->assertForbidden();

        $this->actingAs($this->qc, 'sanctum')
            ->postJson('/api/v1/qc/inspeksi', $this->payloadInspeksi())
            ->assertCreated();

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_qc_lolos' => 90, 'qty_repair_open' => 6, 'qty_qc_reject' => 4]);
        $this->assertDatabaseHas('qc_repair', ['wo_item_id' => $this->woItem->id, 'qty' => 6, 'tahap_tujuan_id' => $this->tahapIds['sewing']]);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'qc.inspeksi', 'subjek_tipe' => 'QcInspeksi']);
        // Notifikasi repair ke supervisor tahap tujuan (sewing)
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->supervisorSewing->id]);
    }

    public function test_inspeksi_ditolak_422_jika_cacat_tidak_cocok(): void
    {
        $payload = $this->payloadInspeksi();
        $payload['items'][0]['cacat'] = [['jenis_cacat_id' => $this->jenisCacat->id, 'qty' => 3, 'tindakan' => 'repair']];

        $this->actingAs($this->qc, 'sanctum')
            ->postJson('/api/v1/qc/inspeksi', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('qc_inspeksi', 0);
    }

    public function test_antrean_qc_dan_riwayat_inspeksi(): void
    {
        $this->actingAs($this->qc, 'sanctum')
            ->getJson('/api/v1/qc/antrean')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->inspeksiLolos();

        $this->actingAs($this->qc, 'sanctum')->getJson('/api/v1/qc/antrean')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->qc, 'sanctum')->getJson('/api/v1/qc/inspeksi')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($this->gudang, 'sanctum')->getJson('/api/v1/qc/antrean')->assertForbidden();
    }

    public function test_repair_hanya_selesai_oleh_supervisor_tahap_tujuan(): void
    {
        $this->actingAs($this->qc, 'sanctum')->postJson('/api/v1/qc/inspeksi', $this->payloadInspeksi())->assertCreated();
        $repairId = \App\Models\QcRepair::where('wo_item_id', $this->woItem->id)->value('id');

        $this->actingAs($this->supervisorSewing, 'sanctum')
            ->getJson('/api/v1/repair')
            ->assertOk()
            ->assertJsonPath('total', 1);
        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->getJson('/api/v1/repair')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson("/api/v1/repair/{$repairId}/selesai", ['qty_selesai' => 6])
            ->assertForbidden();

        $this->actingAs($this->supervisorSewing, 'sanctum')
            ->postJson("/api/v1/repair/{$repairId}/selesai", ['qty_selesai' => 7])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QTY_MELEBIHI_BATAS');

        $this->actingAs($this->supervisorSewing, 'sanctum')
            ->postJson("/api/v1/repair/{$repairId}/selesai", ['qty_selesai' => 6])
            ->assertOk();

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_repair_open' => 0, 'qty_repair_selesai' => 6]);
    }

    public function test_packing_lewat_api_dan_otorisasi(): void
    {
        $this->inspeksiLolos(100);

        $this->actingAs($this->qc, 'sanctum')->postJson('/api/v1/packing', $this->payloadKoli(60))->assertForbidden();
        $this->actingAs($this->supervisorSewing, 'sanctum')->postJson('/api/v1/packing', $this->payloadKoli(60))->assertForbidden();

        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson('/api/v1/packing', $this->payloadKoli(60))
            ->assertCreated();
        $this->assertDatabaseHas('packing', ['wo_id' => $this->wo->id, 'urutan_koli' => 1, 'status' => 'tersedia']);
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_packing' => 60]);

        // Melebihi sisa packing_tersedia (40)
        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson('/api/v1/packing', $this->payloadKoli(41))
            ->assertStatus(422)
            ->assertJsonPath('code', 'QTY_MELEBIHI_BATAS');

        // Sisa 40 -> WO otomatis selesai
        $this->actingAs($this->supervisorFinishing, 'sanctum')->postJson('/api/v1/packing', $this->payloadKoli(40))->assertCreated();
        $this->assertDatabaseHas('work_orders', ['id' => $this->wo->id, 'status' => 'selesai']);
        $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'status' => 'siap_kirim']);

        $this->actingAs($this->gudang, 'sanctum')->getJson('/api/v1/packing')->assertOk()->assertJsonPath('total', 2);
        $this->actingAs($this->qc, 'sanctum')->getJson('/api/v1/packing')->assertForbidden();
    }

    public function test_packing_menolak_item_dari_wo_lain(): void
    {
        $this->inspeksiLolos(100);
        $woLain = $this->buatWo('WO-TEST-6B');
        $itemLain = $this->buatWoItem($woLain);

        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson('/api/v1/packing', $this->payloadKoli(10, $this->wo->id, $itemLain->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDASI_GAGAL');

        $this->assertDatabaseCount('packing', 0);
        $this->assertDatabaseHas('wo_items', ['id' => $itemLain->id, 'qty_packing' => 0]);
    }

    public function test_void_koli_hanya_saat_tersedia_dan_mengembalikan_counter(): void
    {
        $this->inspeksiLolos(100);
        $this->actingAs($this->supervisorFinishing, 'sanctum')->postJson('/api/v1/packing', $this->payloadKoli(60))->assertCreated();
        $koliId = \App\Models\Packing::where('wo_id', $this->wo->id)->value('id');

        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson("/api/v1/packing/{$koliId}/void", ['alasan' => 'abc'])
            ->assertUnprocessable();

        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson("/api/v1/packing/{$koliId}/void", ['alasan' => 'Salah hitung isi koli'])
            ->assertOk();
        $this->assertDatabaseHas('packing', ['id' => $koliId, 'status' => 'void']);
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_packing' => 0]);

        // Koli yang sudah masuk pengiriman tidak boleh di-void
        $this->actingAs($this->supervisorFinishing, 'sanctum')->postJson('/api/v1/packing', $this->payloadKoli(30))->assertCreated();
        $koli2 = \App\Models\Packing::where('wo_id', $this->wo->id)->where('status', 'tersedia')->value('id');
        \App\Models\Packing::whereKey($koli2)->update(['status' => 'dalam_pengiriman']);

        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson("/api/v1/packing/{$koli2}/void", ['alasan' => 'Salah hitung isi koli'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'KOLI_TIDAK_TERSEDIA');
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_packing' => 30]);
    }

    public function test_label_koli_menghasilkan_pdf(): void
    {
        $this->inspeksiLolos(100);
        $this->actingAs($this->supervisorFinishing, 'sanctum')->postJson('/api/v1/packing', $this->payloadKoli(50))->assertCreated();
        $koliId = \App\Models\Packing::where('wo_id', $this->wo->id)->value('id');

        $response = $this->actingAs($this->supervisorFinishing, 'sanctum')->get("/api/v1/packing/{$koliId}/label");
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_void_inspeksi_mengembalikan_counter_dan_menolak_jika_sudah_dikemas(): void
    {
        $qc1 = $this->inspeksiLolos(40);

        $this->actingAs($this->qc, 'sanctum')
            ->postJson("/api/v1/qc/inspeksi/{$qc1->id}/void", ['alasan' => 'Salah input jumlah'])
            ->assertOk();
        $this->assertDatabaseHas('qc_inspeksi', ['id' => $qc1->id, 'status' => 'void']);
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_qc_diperiksa' => 0, 'qty_qc_lolos' => 0]);

        // Inspeksi 100 lolos, lalu 60 dikemas -> void ditolak
        $qc2 = $this->inspeksiLolos(100);
        $this->actingAs($this->supervisorFinishing, 'sanctum')->postJson('/api/v1/packing', $this->payloadKoli(60))->assertCreated();

        $this->actingAs($this->manajer, 'sanctum')
            ->postJson("/api/v1/qc/inspeksi/{$qc2->id}/void", ['alasan' => 'Salah input jumlah'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VOID_DITOLAK_HILIR_NEGATIF');
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_qc_lolos' => 100]);
    }

    public function test_void_inspeksi_ditolak_jika_repair_sudah_dikerjakan(): void
    {
        $this->actingAs($this->qc, 'sanctum')->postJson('/api/v1/qc/inspeksi', $this->payloadInspeksi())->assertCreated();
        $qcId = \App\Models\QcInspeksi::value('id');
        $repairId = \App\Models\QcRepair::where('wo_item_id', $this->woItem->id)->value('id');
        $this->actingAs($this->supervisorSewing, 'sanctum')->postJson("/api/v1/repair/{$repairId}/selesai", ['qty_selesai' => 2])->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/qc/inspeksi/{$qcId}/void", ['alasan' => 'Salah input jumlah'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VOID_DITOLAK_HILIR_NEGATIF');

        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson("/api/v1/qc/inspeksi/{$qcId}/void", ['alasan' => 'Salah input jumlah'])
            ->assertForbidden();
    }

    public function test_tutup_kurang_lewat_api_hanya_manajer_atau_admin(): void
    {
        $this->inspeksiLolos(100);
        $this->actingAs($this->supervisorFinishing, 'sanctum')->postJson('/api/v1/packing', $this->payloadKoli(90))->assertCreated();

        $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson("/api/v1/work-order/{$this->wo->id}/tutup-kurang", ['alasan' => 'Sisa 10 pcs dibatalkan'])
            ->assertForbidden();

        $this->actingAs($this->manajer, 'sanctum')
            ->postJson("/api/v1/work-order/{$this->wo->id}/tutup-kurang", [])
            ->assertUnprocessable();

        $this->actingAs($this->manajer, 'sanctum')
            ->postJson("/api/v1/work-order/{$this->wo->id}/tutup-kurang", ['alasan' => 'Sisa 10 pcs dibatalkan'])
            ->assertOk();

        $this->assertDatabaseHas('work_orders', ['id' => $this->wo->id, 'status' => 'ditutup_kurang']);
        $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'status' => 'siap_kirim', 'ditutup_kurang' => 1]);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'wo.tutup_kurang', 'subjek_tipe' => 'WorkOrder', 'subjek_id' => $this->wo->id]);
    }

    public function test_daftar_dan_detail_work_order(): void
    {
        $this->actingAs($this->manajer, 'sanctum')->getJson('/api/v1/work-order')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($this->manajer, 'sanctum')
            ->getJson("/api/v1/work-order/{$this->wo->id}")
            ->assertOk()
            ->assertJsonPath('data.no_wo', 'WO-TEST-6A')
            ->assertJsonPath('data.wo_items.0.qc_tersedia', 100);

        $this->actingAs($this->supervisorFinishing, 'sanctum')->getJson('/api/v1/work-order')->assertOk();
        $this->actingAs($this->qc, 'sanctum')->getJson("/api/v1/work-order/{$this->wo->id}")->assertOk();
    }
}