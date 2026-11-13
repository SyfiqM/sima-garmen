<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StokOpname;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SkenarioProduksi;
use Tests\TestCase;

/**
 * Notifikasi bagian 4.15 terkirim pada peristiwanya dan ke penerima yang benar.
 * Pelaku aksi tidak ikut diberi notifikasi (karena itu memakai manajer2 dan gudang2).
 */
class Batch9NotificationTest extends TestCase
{
    use RefreshDatabase, SkenarioProduksi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkenario(stokKain: 100);
    }

    public function test_order_diajukan_memberi_notifikasi_ke_manajer(): void
    {
        $order = $this->buatOrder(10);
        $this->ajukan($order);

        $this->assertContains('Order diajukan', $this->judulNotifikasi($this->u['manajer']));
        $this->assertContains('Order diajukan', $this->judulNotifikasi($this->u['manajer2']));
        $this->assertNotContains('Order diajukan', $this->judulNotifikasi($this->u['gudang']));
    }

    public function test_order_disetujui_memberi_notifikasi_ke_pembuat_dan_gudang(): void
    {
        $order = $this->buatOrder(10);
        $this->ajukan($order);
        $order = $this->setujui($order);

        $this->assertEquals('siap_produksi', $order->status->value);
        $this->assertContains('Order disetujui', $this->judulNotifikasi($this->u['staf_order']));
        $this->assertContains('WO siap diproduksi', $this->judulNotifikasi($this->u['gudang']));
        $this->assertNotContains('Order menunggu bahan', $this->judulNotifikasi($this->u['gudang']));
    }

    public function test_order_ditolak_memberi_notifikasi_ke_pembuat(): void
    {
        $order = $this->buatOrder(10);
        $this->ajukan($order);

        $this->api('manajer')->postJson("/api/v1/order/{$order->id}/tolak", ['alasan' => 'Data pelanggan belum lengkap'])->assertOk();

        $this->assertContains('Order ditolak', $this->judulNotifikasi($this->u['staf_order']));
    }

    public function test_bahan_kurang_menunggu_bahan_lalu_otomatis_siap_produksi_setelah_po_diterima(): void
    {
        // UAT 4 dan 5: stok kain 50 m, kebutuhan 80 m
        $this->kain->refresh();
        $this->tambahStok(-50);
        $this->assertEquals(50, (float) $this->kain->fresh()->stok);

        $order = $this->buatOrder(80);
        $this->ajukan($order);
        $order = $this->setujui($order);

        $this->assertEquals('menunggu_bahan', $order->status->value);
        $this->assertDatabaseHas('wo_bahan', ['wo_id' => $this->woDari($order)->id, 'qty_rencana' => 80, 'qty_reservasi' => 50]);
        $this->assertContains('Order menunggu bahan', $this->judulNotifikasi($this->u['gudang']));
        $this->assertContains('Order menunggu bahan', $this->judulNotifikasi($this->u['manajer2']));

        // Halaman kekurangan bahan menyarankan PO 30 m
        $poId = $this->api('gudang')->postJson('/api/v1/po/dari-kekurangan', [
            'supplier_id' => $this->supplier->id, 'tgl_po' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('purchase_order_items', ['po_id' => $poId, 'bahan_id' => $this->kain->id, 'qty_pesan' => 30]);

        $this->api('gudang')->postJson("/api/v1/po/{$poId}/pesan")->assertOk();
        $poItem = PurchaseOrderItem::where('po_id', $poId)->firstOrFail();

        $this->api('gudang')->postJson('/api/v1/penerimaan', [
            'po_id' => $poId, 'supplier_id' => $this->supplier->id, 'tgl_terima' => now()->toDateString(),
            'items' => [['po_item_id' => $poItem->id, 'bahan_id' => $this->kain->id, 'qty' => 30]],
        ])->assertCreated();

        // Reservasi teralokasi ulang, status otomatis siap_produksi, notifikasi ke Gudang
        $this->assertDatabaseHas('wo_bahan', ['wo_id' => $this->woDari($order)->id, 'qty_reservasi' => 80]);
        $this->assertEquals('siap_produksi', $order->fresh()->status->value);
        $this->assertContains('WO siap diproduksi', $this->judulNotifikasi($this->u['gudang2']));
        $this->assertContains('PO diterima penuh', $this->judulNotifikasi($this->u['manajer']));
        $this->assertDatabaseHas('activity_log', ['aksi' => 'order.siap_produksi_otomatis', 'subjek_id' => $order->id]);

        $this->assertIntegritasBersih();
    }

    public function test_po_diterima_sebagian_lalu_penuh(): void
    {
        $poId = $this->api('gudang')->postJson('/api/v1/po', [
            'supplier_id' => $this->supplier->id, 'tgl_po' => now()->toDateString(),
            'items' => [['bahan_id' => $this->kain->id, 'qty_pesan' => 100, 'harga_satuan' => 5000]],
        ])->assertCreated()->json('data.id');
        $this->api('gudang')->postJson("/api/v1/po/{$poId}/pesan")->assertOk();
        $poItem = PurchaseOrderItem::where('po_id', $poId)->firstOrFail();

        $terima = fn (int $qty) => $this->api('gudang')->postJson('/api/v1/penerimaan', [
            'po_id' => $poId, 'supplier_id' => $this->supplier->id, 'tgl_terima' => now()->toDateString(),
            'items' => [['po_item_id' => $poItem->id, 'bahan_id' => $this->kain->id, 'qty' => $qty]],
        ])->assertCreated();

        $terima(60);
        $this->assertEquals('diterima_sebagian', PurchaseOrder::findOrFail($poId)->status->value);
        $this->assertContains('PO diterima sebagian', $this->judulNotifikasi($this->u['manajer']));
        $this->assertContains('PO diterima sebagian', $this->judulNotifikasi($this->u['gudang2']));

        $terima(40);
        $this->assertEquals('diterima', PurchaseOrder::findOrFail($poId)->status->value);
        $this->assertContains('PO diterima penuh', $this->judulNotifikasi($this->u['manajer']));
    }

    public function test_tahap_ditutup_memberi_notifikasi_ke_supervisor_tahap_berikutnya_dan_qc(): void
    {
        $order = $this->orderDisetujui(10);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);
        $this->keluarkanKain($wo, 10)->assertSuccessful();
        $this->produksiPenuh($wo, $item, 10);

        $this->api('manajer')->postJson("/api/v1/work-order/{$wo->id}/tahap/cutting/tutup", ['alasan' => 'Cutting selesai sesuai order'])->assertOk();
        $this->assertNotEmpty(array_filter($this->judulNotifikasi($this->u['spv_sewing']), fn ($j) => str_contains($j, 'ditutup')));
        $this->assertEmpty(array_filter($this->judulNotifikasi($this->u['spv_finishing']), fn ($j) => str_contains($j, 'ditutup')));

        $this->api('manajer')->postJson("/api/v1/work-order/{$wo->id}/tahap/sewing/tutup", ['alasan' => 'Sewing selesai sesuai order'])->assertOk();
        $this->assertNotEmpty(array_filter($this->judulNotifikasi($this->u['spv_finishing']), fn ($j) => str_contains($j, 'ditutup')));

        $this->api('manajer')->postJson("/api/v1/work-order/{$wo->id}/tahap/finishing/tutup", ['alasan' => 'Finishing selesai sesuai order'])->assertOk();
        $this->assertNotEmpty(array_filter($this->judulNotifikasi($this->u['qc']), fn ($j) => str_contains($j, 'ditutup')));
    }

    public function test_pengeluaran_melebihi_reservasi_butuh_persetujuan_dan_memberi_notifikasi_ke_manajer(): void
    {
        $order = $this->orderDisetujui(10);
        $wo = $this->woDari($order);

        // 10,3 m dari reservasi 10 m: lebih 3% (toleransi 5%)
        $this->keluarkanKainDenganAlasan($wo, 10.3)->assertSuccessful();

        $this->assertDatabaseHas('pengeluaran_bahan', ['wo_id' => $wo->id, 'status_persetujuan' => 'menunggu']);
        $this->assertContains('Pengeluaran butuh persetujuan', $this->judulNotifikasi($this->u['manajer']));
    }

    public function test_opname_diajukan_memberi_notifikasi_ke_manajer(): void
    {
        $this->api('gudang')->postJson('/api/v1/opname', [
    'tanggal' => now()->toDateString(),
    'items' => [
        [
            'bahan_id' => $this->kain->id,
            'stok_fisik' => 95,
            'alasan' => 'Selisih terdeteksi saat opname rutin' // Tambahkan field ini
        ]
    ],
])->assertCreated();
        $opname = StokOpname::latest('id')->firstOrFail();

        $this->api('gudang')->postJson("/api/v1/opname/{$opname->id}/ajukan")->assertOk();

        $this->assertContains('Opname diajukan', $this->judulNotifikasi($this->u['manajer']));
    }

    private function keluarkanKainDenganAlasan($wo, float $qty)
    {
        $woBahan = \App\Models\WoBahan::where('wo_id', $wo->id)->where('tahap_id', $this->tahapIds['cutting'])->firstOrFail();

        return $this->api('gudang')->postJson('/api/v1/pengeluaran-bahan', [
            'wo_id' => $wo->id,
            'tahap_id' => $this->tahapIds['cutting'],
            'tipe' => 'keluar',
            'tanggal' => now()->toDateString(),
            'alasan_lebih' => 'Kain tambahan karena potongan miring',
            'items' => [['wo_bahan_id' => $woBahan->id, 'qty' => $qty, 'alasan_lebih' => 'Kain tambahan karena potongan miring']],
        ]);
    }
}