<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\Order;
use App\Models\PenerimaanBahan;
use App\Models\PenerimaanBahanItem;
use App\Models\PengeluaranBahan;
use App\Models\PengeluaranBahanItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StokOpname;
use App\Models\StokOpnameItem;
use App\Models\WoBahan;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SkenarioProduksi;
use Tests\TestCase;

class Batch9AuditFixesTest extends TestCase
{
    use RefreshDatabase, SkenarioProduksi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkenario(stokKain: 100);
    }

    public function test_order_index_supports_date_range_and_overdue_filter(): void
    {
        $terlambat = Order::create([
            'no_order' => 'ORD-FILTER-LATE', 'pelanggan_id' => $this->pelanggan->id,
            'tgl_order' => now()->subDays(5)->toDateString(), 'deadline' => now()->subDay()->toDateString(),
            'status' => 'produksi', 'dibuat_oleh' => $this->u['staf_order']->id,
        ]);
        Order::create([
            'no_order' => 'ORD-FILTER-FUTURE', 'pelanggan_id' => $this->pelanggan->id,
            'tgl_order' => now()->subDays(2)->toDateString(), 'deadline' => now()->addDay()->toDateString(),
            'status' => 'produksi', 'dibuat_oleh' => $this->u['staf_order']->id,
        ]);

        $this->api('staf_order')
            ->getJson('/api/v1/order?tgl_dari='.now()->subDays(6)->toDateString().'&tgl_sampai='.now()->subDays(4)->toDateString().'&terlambat=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $terlambat->id);

        $this->api('staf_order')
            ->getJson('/api/v1/order?terlambat=0')
            ->assertOk()
            ->assertJsonMissing(['no_order' => 'ORD-FILTER-LATE']);

        $this->api('staf_order')
            ->getJson('/api/v1/order?sort=deadline')
            ->assertOk()
            ->assertJsonPath('data.0.id', $terlambat->id);
    }

    public function test_stok_opname_draft_can_be_updated_only_before_submission(): void
    {
        $payload = [
            'tanggal' => now()->toDateString(),
            'catatan' => 'Hitung awal',
            'items' => [[
                'bahan_id' => $this->kain->id,
                'stok_fisik' => 98,
                'alasan' => 'Selisih pengukuran',
            ]],
        ];

        $created = $this->api('gudang')->postJson('/api/v1/opname', $payload)->assertCreated();
        $id = $created->json('data.id');

        $updated = $this->api('gudang')->putJson("/api/v1/opname/{$id}", [
            'tanggal' => now()->addDay()->toDateString(),
            'catatan' => 'Hitung ulang',
            'items' => [[
                'bahan_id' => $this->kain->id,
                'stok_fisik' => 95,
                'alasan' => 'Selisih hasil hitung',
            ]],
        ])->assertOk();

        $this->assertSame('Hitung ulang', $updated->json('data.catatan'));
        $this->api('gudang')->getJson('/api/v1/opname')->assertOk();
        $this->api('gudang')->getJson("/api/v1/opname/{$id}")->assertOk()
            ->assertJsonPath('data.dibuat_oleh', $this->u['gudang']->id);
        $this->assertDatabaseHas('stok_opname_items', [
            'opname_id' => $id,
            'bahan_id' => $this->kain->id,
            'stok_sistem' => 100,
            'stok_fisik' => 95,
            'selisih' => -5,
        ]);

        // PATCH juga didukung karena route resource Laravel menyediakan PUT|PATCH.
        $this->api('gudang')->patchJson("/api/v1/opname/{$id}", [
            'tanggal' => now()->toDateString(),
            'catatan' => 'Koreksi patch',
            'items' => [[
                'bahan_id' => $this->kain->id,
                'stok_fisik' => 96,
                'alasan' => 'Koreksi angka',
            ]],
        ])->assertOk()->assertJsonPath('data.catatan', 'Koreksi patch');

        // Pemilik lain tidak boleh mengubah draft sebelum diajukan.
        $gudangLain = \App\Models\User::factory()->create(['role' => 'gudang']);
        $this->actingAs($gudangLain, 'sanctum')
            ->putJson("/api/v1/opname/{$id}", $payload)
            ->assertForbidden();

        // Setelah diajukan, pemiliknya sendiri juga tidak boleh mengubah dokumen.
        $this->api('gudang')->postJson("/api/v1/opname/{$id}/ajukan")->assertOk();
        $this->api('gudang')->putJson("/api/v1/opname/{$id}", $payload)->assertStatus(409);
    }

    public function test_bom_create_update_and_delete_are_written_to_audit_log(): void
    {
        $bom = $this->api('manajer')->postJson("/api/v1/produk/{$this->produk->id}/bom", [
            'bahan_id' => $this->kain->id,
            'tahap_id' => $this->tahapIds['sewing'],
            'qty_per_pcs' => 0.25,
            'waste_persen' => 0,
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('activity_log', ['aksi' => 'bom.create', 'subjek_tipe' => 'Bom', 'subjek_id' => $bom['id']]);

        $this->api('manajer')->putJson("/api/v1/bom/{$bom['id']}", [
            'bahan_id' => $this->kain->id,
            'tahap_id' => $this->tahapIds['sewing'],
            'qty_per_pcs' => 0.5,
            'waste_persen' => 1,
        ])->assertOk();
        $this->assertDatabaseHas('activity_log', ['aksi' => 'bom.update', 'subjek_tipe' => 'Bom', 'subjek_id' => $bom['id']]);

        $this->api('manajer')->deleteJson("/api/v1/bom/{$bom['id']}")->assertNoContent();
        $this->assertDatabaseHas('activity_log', ['aksi' => 'bom.delete', 'subjek_tipe' => 'Bom', 'subjek_id' => $bom['id']]);
    }

    public function test_work_order_detail_hides_other_stage_counters_from_supervisor(): void
    {
        $order = $this->orderDisetujui(10);
        $wo = $this->woDari($order);

        $response = $this->api('spv_cutting')->getJson("/api/v1/work-order/{$wo->id}")->assertOk();
        $item = $response->json('data.wo_items.0');

        $this->assertArrayHasKey('qty_cutting', $item);
        $this->assertArrayNotHasKey('qty_sewing', $item);
        $this->assertArrayNotHasKey('qty_qc_lolos', $item);
        $this->assertSame([$this->tahapIds['cutting']], array_column($response->json('data.wo_bahan'), 'tahap_id'));
        $this->assertSame([$this->tahapIds['cutting']], array_column($response->json('data.wo_tahap'), 'tahap_id'));

        $this->api('spv_sewing')->getJson("/api/v1/work-order/{$wo->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.wo_items.0.qty_qc_lolos');
    }

    public function test_kirim_tersedia_excludes_koli_in_prepared_shipment(): void
    {
        $order = $this->orderDisetujui(15);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        $this->keluarkanKain($wo, 15)->assertCreated();
        $this->produksiPenuh($wo, $item, 15);
        $this->inspeksi($wo, $item, 10, 10)->assertCreated();

        $koliDisiapkan = $this->buatKoli($wo, $item, 4)->assertCreated()->json('data.id');
        $this->buatKoli($wo, $item, 6)->assertCreated();
        $this->buatSuratJalan($order, [$koliDisiapkan])->assertCreated();

        $detail = $this->api('admin')->getJson("/api/v1/work-order/{$wo->id}")->assertOk();
        $detail->assertJsonPath('data.wo_items.0.qty_packing', 10)
            ->assertJsonPath('data.wo_items.0.qty_terkirim', 0)
            ->assertJsonPath('data.wo_items.0.kirim_tersedia', 6);

        $this->api('admin')->getJson('/api/v1/laporan/wip?format=json')->assertOk()
            ->assertJsonPath('data.baris.0.kirim_tersedia', 6);
    }

    public function test_work_order_purchase_order_receipt_and_material_issue_pdfs_are_available(): void
    {
        $order = $this->orderDisetujui(10);
        $wo = $this->woDari($order);

        $this->api('admin')->getJson("/api/v1/work-order/{$wo->id}/cetak")
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $po = PurchaseOrder::create([
            'no_po' => 'PO-PDF-001', 'supplier_id' => $this->supplier->id,
            'tgl_po' => now()->toDateString(), 'status' => 'draft', 'dibuat_oleh' => $this->u['gudang']->id,
        ]);
        PurchaseOrderItem::create([
            'po_id' => $po->id, 'bahan_id' => $this->kain->id, 'qty_pesan' => 20,
            'qty_diterima' => 0, 'harga_satuan' => 15000,
        ]);
        $this->api('gudang')->getJson("/api/v1/po/{$po->id}/cetak")
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $penerimaan = PenerimaanBahan::create([
            'no_bm' => 'BM-PDF-001', 'supplier_id' => $this->supplier->id,
            'tgl_terima' => now()->toDateString(), 'status' => 'aktif', 'diterima_oleh' => $this->u['gudang']->id,
        ]);
        PenerimaanBahanItem::create([
            'penerimaan_id' => $penerimaan->id, 'bahan_id' => $this->kain->id,
            'qty' => 5, 'harga_satuan' => 15000,
        ]);
        $this->api('manajer')->getJson("/api/v1/penerimaan/{$penerimaan->id}/cetak")
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        $woBahan = WoBahan::where('wo_id', $wo->id)->firstOrFail();
        $pengeluaran = PengeluaranBahan::create([
            'no_pb' => 'PB-PDF-001', 'wo_id' => $wo->id, 'tahap_id' => $woBahan->tahap_id,
            'tipe' => 'keluar', 'tanggal' => now()->toDateString(), 'status' => 'aktif',
            'status_persetujuan' => 'tidak_perlu', 'dibuat_oleh' => $this->u['gudang']->id,
        ]);
        PengeluaranBahanItem::create([
            'pengeluaran_id' => $pengeluaran->id, 'wo_bahan_id' => $woBahan->id,
            'bahan_id' => $woBahan->bahan_id, 'qty' => 5,
        ]);
        $this->api('gudang')->getJson("/api/v1/pengeluaran-bahan/{$pengeluaran->id}/cetak")
            ->assertOk()->assertHeader('content-type', 'application/pdf');

        // Supervisor hanya boleh mencetak dokumen pengeluaran pada tahapnya sendiri.
        $this->api('spv_sewing')->getJson("/api/v1/pengeluaran-bahan/{$pengeluaran->id}/cetak")->assertNotFound();
    }

    public function test_deadline_alert_is_sent_to_all_active_staff_order_users(): void
    {
        $stafOrderKedua = \App\Models\User::factory()->create(['role' => 'staf_order']);
        Order::create([
            'no_order' => 'ORD-ALERT-ALL', 'pelanggan_id' => $this->pelanggan->id,
            'tgl_order' => now()->subDays(5)->toDateString(), 'deadline' => now()->addDays(2)->toDateString(),
            'status' => 'produksi', 'dibuat_oleh' => $this->u['staf_order']->id,
        ]);

        Artisan::call('sima:deadline-alerts');

        $this->assertSame(1, $stafOrderKedua->fresh()->notifications()->count());
        $this->assertSame(1, $this->u['staf_order']->fresh()->notifications()->count());
        $this->assertSame(1, $this->u['manajer']->fresh()->notifications()->count());
    }
}
