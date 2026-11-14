<?php

namespace Tests\Feature;

use App\Helpers\NumberingService;
use App\Models\Order;
use App\Models\Packing;
use App\Models\PengeluaranBahan;
use App\Models\Pengiriman;
use App\Models\ProduksiLog;
use App\Models\PurchaseOrder;
use App\Models\QcRepair;
use App\Models\StokOpname;
use App\Models\WoBahan;
use App\Models\WoItem;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SkenarioProduksi;
use Tests\TestCase;

/**
 * 22 skenario uji akhir (UAT) sesuai bagian 10 dokumen rancangan, seluruhnya lewat API.
 * Nama method memuat nomor skenario: test_skenario_NN_...
 *
 * Catatan skenario 19: balapan sungguhan antar proses tidak bisa ditiru di satu proses PHPUnit.
 * Yang diuji di sini adalah hasilnya (nomor unik dan berurutan). Penguncinya (insertOrIgnore +
 * lockForUpdate di NumberingService) diverifikasi lewat uji beban manual, lihat catatan di bawah.
 */
class Batch9UatScenarioTest extends TestCase
{
    use RefreshDatabase, SkenarioProduksi;

    // ------------------------------------------------------------------ pembantu

    /** @return array{0: float, 1: float} [stok, stok_dipesan] kain saat ini */
    private function stokKain(): array
    {
        $kain = $this->kain->fresh();

        return [(float) $kain->stok, (float) $kain->stok_dipesan];
    }

    private function statusOrder(Order $order): string
    {
        return $order->fresh()->status->value;
    }

    /** Order disetujui, kain keluar penuh, lalu cutting-sewing-finishing sebanyak $qty. */
    private function sampaiProduksi(int $qty): array
    {
        $order = $this->orderDisetujui($qty);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        $this->keluarkanKain($wo, $qty)->assertSuccessful();
        $this->produksiPenuh($wo, $item, $qty);

        return [$order, $wo, $item];
    }

    /** Sampai QC lolos penuh dan satu koli berisi seluruh qty (status siap_kirim). */
    private function sampaiSiapKirim(int $qty): array
    {
        [$order, $wo, $item] = $this->sampaiProduksi($qty);
        $this->inspeksi($wo, $item, $qty, $qty)->assertSuccessful();
        $this->buatKoli($wo, $item, $qty)->assertSuccessful();

        return [$order, $wo, $item];
    }

    /** Skenario QC: 100 pcs diperiksa, 90 lolos, 6 repair, 4 reject. */
    private function sampaiQcDenganRepair(): array
    {
        [$order, $wo, $item] = $this->sampaiProduksi(100);
        $this->inspeksi($wo, $item, 100, 90, 6, 4)->assertSuccessful();

        return [$order, $wo, $item];
    }

    private function terimaTanpaPo(float $qty)
    {
        return $this->api('gudang')->postJson('/api/v1/penerimaan', [
            'supplier_id' => $this->supplier->id,
            'tgl_terima' => now()->toDateString(),
            'alasan_tanpa_po' => 'Pembelian langsung untuk uji',
            'items' => [['bahan_id' => $this->kain->id, 'qty' => $qty, 'harga_satuan' => 1000]],
        ]);
    }

    private function jumlahNotifikasi($user, string $judul): int
    {
        return collect($this->judulNotifikasi($user))->filter(fn ($j) => $j === $judul)->count();
    }

    // ===================================================== 1-3 : alur normal

    public function test_skenario_01_order_disetujui_bahan_cukup_menjadi_siap_produksi(): void
    {
        $this->siapkanSkenario(stokKain: 100);

        $order = $this->orderDisetujui(100);

        $this->assertSame('siap_produksi', $this->statusOrder($order));
        $this->assertSame([100.0, 100.0], $this->stokKain());

        $wo = $this->woDari($order);
        $this->assertSame('aktif', $wo->status->value);
        $this->assertSame(3, DB::table('wo_tahap')->where('wo_id', $wo->id)->count());

        $item = $this->itemDari($wo);
        $this->assertSame(100, $item->qty_order);
        $this->assertSame(103, $item->qty_batas_potong); // 100 + 3% dibulatkan ke atas

        $woBahan = WoBahan::where('wo_id', $wo->id)->firstOrFail();
        $this->assertEquals(100, (float) $woBahan->qty_rencana);
        $this->assertEquals(100, (float) $woBahan->qty_reservasi);
        $this->assertEquals(0, (float) $woBahan->qty_keluar);

        $this->assertIntegritasBersih();
    }

    public function test_skenario_02_dari_bahan_keluar_sampai_packing_menjadi_siap_kirim(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $order = $this->orderDisetujui(100);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        $this->keluarkanKain($wo, 100)->assertSuccessful();
        $this->assertSame('produksi', $this->statusOrder($order));
        $this->assertSame([0.0, 0.0], $this->stokKain());

        $this->produksiPenuh($wo, $item, 100);
        $this->inspeksi($wo, $item, 100, 100)->assertSuccessful();
        $this->assertSame('produksi', $this->statusOrder($order), 'belum dipacking, belum siap kirim');

        $this->buatKoli($wo, $item, 100)->assertSuccessful();

        $this->assertSame('siap_kirim', $this->statusOrder($order));
        $this->assertSame('selesai', $wo->fresh()->status->value);

        $item = $item->fresh();
        $this->assertSame(
            [100, 0, 100, 0, 100, 0, 100, 100, 0, 0, 0, 100, 0],
            [
                $item->qty_cutting, $item->qty_cutting_reject, $item->qty_sewing, $item->qty_sewing_reject,
                $item->qty_finishing, $item->qty_finishing_reject, $item->qty_qc_diperiksa, $item->qty_qc_lolos,
                $item->qty_qc_reject, $item->qty_repair_open, $item->qty_repair_selesai, $item->qty_packing,
                $item->qty_terkirim,
            ],
        );

        $this->assertIntegritasBersih();
    }

    public function test_skenario_03_kirim_dua_koli_lalu_satu_koli_sisa(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        [$order, $wo, $item] = $this->sampaiProduksi(100);
        $this->inspeksi($wo, $item, 100, 100)->assertSuccessful();
        foreach ([40, 40, 20] as $qty) {
            $this->buatKoli($wo, $item, $qty)->assertSuccessful();
        }
        [$k1, $k2, $k3] = $this->koliIds($wo);
        $this->assertSame('siap_kirim', $this->statusOrder($order));

        $sj1 = $this->buatSuratJalan($order, [$k1, $k2])->assertCreated()->json('data.id');
        $this->kirimSuratJalan($sj1)->assertOk();
        $this->assertSame('dikirim_sebagian', $this->statusOrder($order));
        $this->assertSame(80, $item->fresh()->qty_terkirim);

        $sj2 = $this->buatSuratJalan($order, [$k3])->assertCreated()->json('data.id');
        $this->kirimSuratJalan($sj2)->assertOk();
        $this->assertSame('terkirim', $this->statusOrder($order));
        $this->assertSame(100, $item->fresh()->qty_terkirim);

        $this->assertIntegritasBersih();
    }

    // ===================================================== 4-6 : bahan kurang

    public function test_skenario_04_bahan_kurang_menunggu_bahan_dan_saran_po_30(): void
    {
        $this->siapkanSkenario(stokKain: 50);

        $order = $this->orderDisetujui(80);

        $this->assertSame('menunggu_bahan', $this->statusOrder($order));
        $this->assertSame([50.0, 50.0], $this->stokKain());

        $saran = $this->api('gudang')->getJson('/api/v1/kekurangan-bahan')->assertOk()->json('data');
        $this->assertCount(1, $saran);
        $this->assertEquals($this->kain->id, $saran[0]['bahan_id']);
        $this->assertEquals(30, $saran[0]['saran_qty_po']);

        $this->assertIntegritasBersih();
    }

    public function test_skenario_05_po_diterima_reservasi_dialokasi_ulang_dan_siap_produksi(): void
    {
        $this->siapkanSkenario(stokKain: 50);
        $order = $this->orderDisetujui(80);
        $this->assertSame('menunggu_bahan', $this->statusOrder($order));

        $poId = $this->api('gudang')->postJson('/api/v1/po/dari-kekurangan', [
            'supplier_id' => $this->supplier->id, 'tgl_po' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->api('gudang')->postJson("/api/v1/po/{$poId}/pesan")->assertOk();

        $po = PurchaseOrder::with('items')->findOrFail($poId);
        $this->assertEquals(30, (float) $po->items->first()->qty_pesan);

        $this->api('gudang')->postJson('/api/v1/penerimaan', [
            'po_id' => $poId,
            'supplier_id' => $this->supplier->id,
            'tgl_terima' => now()->toDateString(),
            'items' => [['po_item_id' => $po->items->first()->id, 'bahan_id' => $this->kain->id, 'qty' => 30, 'harga_satuan' => 1000]],
        ])->assertSuccessful();

        $this->assertSame('siap_produksi', $this->statusOrder($order));
        $this->assertSame([80.0, 80.0], $this->stokKain());
        $this->assertSame('diterima', $po->fresh()->status->value);
        $this->assertGreaterThanOrEqual(1, $this->jumlahNotifikasi($this->u['gudang'], 'WO siap diproduksi'));

        $this->assertIntegritasBersih();
    }

    public function test_skenario_06_dua_order_berebut_kain_urgent_didahulukan(): void
    {
        $this->siapkanSkenario(stokKain: 0);
        $biasa = $this->orderDisetujui(50, 'normal');
        $urgent = $this->orderDisetujui(50, 'urgent'); // disetujui belakangan, tetapi prioritas lebih tinggi
        $this->assertSame('menunggu_bahan', $this->statusOrder($biasa));
        $this->assertSame('menunggu_bahan', $this->statusOrder($urgent));

        $this->terimaTanpaPo(50)->assertSuccessful();

        $this->assertSame('siap_produksi', $this->statusOrder($urgent));
        $this->assertSame('menunggu_bahan', $this->statusOrder($biasa));
        $this->assertEquals(50, (float) WoBahan::where('wo_id', $this->woDari($urgent)->id)->value('qty_reservasi'));
        $this->assertEquals(0, (float) WoBahan::where('wo_id', $this->woDari($biasa)->id)->value('qty_reservasi'));
        $this->assertSame([50.0, 50.0], $this->stokKain());

        $this->assertIntegritasBersih();
    }

    public function test_skenario_06b_prioritas_sama_deadline_lebih_dekat_didahulukan(): void
    {
        $this->siapkanSkenario(stokKain: 0);
        $jauh = $this->orderDisetujui(50, 'normal', 20);
        $dekat = $this->orderDisetujui(50, 'normal', 5);

        $this->terimaTanpaPo(50)->assertSuccessful();

        $this->assertSame('siap_produksi', $this->statusOrder($dekat));
        $this->assertSame('menunggu_bahan', $this->statusOrder($jauh));

        $this->assertIntegritasBersih();
    }

    // ===================================================== 7-8 : batas produksi

    public function test_skenario_07_sewing_melebihi_hasil_cutting_ditolak(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $order = $this->orderDisetujui(100);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);
        $this->keluarkanKain($wo, 100)->assertSuccessful();

        $this->inputProduksi('cutting', $wo, $item, 50)->assertSuccessful();

        $this->inputProduksi('sewing', $wo, $item, 60)
            ->assertStatus(422)
            ->assertJsonPath('code', 'QTY_MELEBIHI_BATAS');
        $this->assertSame(0, $item->fresh()->qty_sewing);

        $this->inputProduksi('sewing', $wo, $item, 50)->assertSuccessful(); // tepat di batas diterima
        $this->inputProduksi('sewing', $wo, $item, 1)->assertStatus(422)->assertJsonPath('code', 'QTY_MELEBIHI_BATAS');

        $this->assertIntegritasBersih();
    }

    public function test_skenario_08_cutting_reject_lalu_tambah_potong_dan_kain_tambahan_butuh_persetujuan(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $this->tambahStok(10); // cadangan untuk kain tambahan
        $order = $this->orderDisetujui(100);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);
        $this->keluarkanKain($wo, 100)->assertSuccessful();

        // 100 baik + 3 reject = 103 = batas potong (100 + 3%)
        $this->inputProduksi('cutting', $wo, $item, 100, 3)->assertSuccessful();
        $this->inputProduksi('cutting', $wo, $item, 1)->assertStatus(422)->assertJsonPath('code', 'QTY_MELEBIHI_BATAS');

        $tambah = ['wo_item_id' => $item->id, 'qty' => 3, 'alasan' => 'Ganti potongan reject'];
        $this->api('spv_cutting')->postJson("/api/v1/work-order/{$wo->id}/tambah-potong", $tambah)->assertForbidden();
        $this->api('manajer')->postJson("/api/v1/work-order/{$wo->id}/tambah-potong", $tambah)->assertSuccessful();

        $this->assertSame(106, $item->fresh()->qty_batas_potong);
        $this->assertTrue(
            DB::table('activity_log')->where('aksi', 'produksi.tambah_potong')->where('alasan', 'Ganti potongan reject')->exists(),
            'alasan tambah potong harus tercatat di audit log',
        );
        $this->inputProduksi('cutting', $wo, $item, 3)->assertSuccessful();

        // Kain tambahan: melebihi reservasi, perlu alasan dan persetujuan Manajer.
        $woBahan = WoBahan::where('wo_id', $wo->id)->firstOrFail();
        $tambahKain = [
            'wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['cutting'], 'tipe' => 'keluar',
            'tanggal' => now()->toDateString(), 'alasan_lebih' => 'Kain tambahan untuk potongan ganti',
            'items' => [['wo_bahan_id' => $woBahan->id, 'qty' => 3]],
        ];
        $this->api('gudang')->postJson('/api/v1/pengeluaran-bahan', $tambahKain)->assertSuccessful();
        $dok = PengeluaranBahan::latest('id')->firstOrFail();
        $this->assertSame('menunggu', $dok->status_persetujuan);
        $this->assertSame([10.0, 0.0], $this->stokKain(), 'stok belum berkurang sebelum disetujui');

        $this->api('gudang')->postJson("/api/v1/pengeluaran-bahan/{$dok->id}/setujui")->assertForbidden();
        $this->api('manajer')->postJson("/api/v1/pengeluaran-bahan/{$dok->id}/setujui")->assertSuccessful();
        $this->assertSame([7.0, 0.0], $this->stokKain());

        $this->assertIntegritasBersih();
    }

    // ===================================================== 9-11 : QC, repair, tutup kurang

    public function test_skenario_09_qc_periksa_100_lolos_90_repair_6_reject_4(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        [, , $item] = $this->sampaiQcDenganRepair();

        $item = $item->fresh();
        $this->assertSame(100, $item->qty_qc_diperiksa);
        $this->assertSame(90, $item->qty_qc_lolos);
        $this->assertSame(4, $item->qty_qc_reject);
        $this->assertSame(6, $item->qty_repair_open);

        $repair = QcRepair::where('wo_item_id', $item->id)->firstOrFail();
        $this->assertSame(6, $repair->qty);
        $this->assertSame('open', $repair->status->value);
        $this->assertSame($this->tahapIds['sewing'], (int) $repair->tahap_tujuan_id);

        // antrean repair hanya tampil di supervisor tahap tujuan
        $this->assertCount(1, $this->api('spv_sewing')->getJson('/api/v1/repair?status=open')->assertOk()->json('data'));
        $this->assertCount(0, $this->api('spv_finishing')->getJson('/api/v1/repair?status=open')->assertOk()->json('data'));
        $this->assertGreaterThanOrEqual(1, $this->jumlahNotifikasi($this->u['spv_sewing'], 'Ada repair baru'));

        $this->assertIntegritasBersih();
    }

    public function test_skenario_10_repair_selesai_lalu_qc_ulang_total_lolos_96(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        [, $wo, $item] = $this->sampaiQcDenganRepair();
        $repair = QcRepair::where('wo_item_id', $item->id)->firstOrFail();

        $this->api('spv_sewing')->postJson("/api/v1/repair/{$repair->id}/selesai", ['qty_selesai' => 6])->assertSuccessful();

        $item = $item->fresh();
        $this->assertSame(0, $item->qty_repair_open);
        $this->assertSame(6, $item->qty_repair_selesai);
        // qc_tersedia = finishing + repair_selesai - diperiksa = 100 + 6 - 100
        $this->assertSame(6, $item->qty_finishing + $item->qty_repair_selesai - $item->qty_qc_diperiksa);

        $this->inspeksi($wo, $item, 6, 6)->assertSuccessful();

        $item = $item->fresh();
        $this->assertSame(96, $item->qty_qc_lolos);
        $this->assertSame(106, $item->qty_qc_diperiksa);
        $this->assertSame(0, $item->qty_finishing + $item->qty_repair_selesai - $item->qty_qc_diperiksa);

        $this->assertIntegritasBersih();
    }

    public function test_skenario_11_kekurangan_4_pcs_tutup_kurang_lalu_kirim_96(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        [$order, $wo, $item] = $this->sampaiQcDenganRepair();
        $alasan = ['alasan' => 'Reject 4 pcs, pelanggan setuju kurang'];

        // Selama repair masih open, tutup kurang ditolak.
        $this->api('manajer')->postJson("/api/v1/work-order/{$wo->id}/tutup-kurang", $alasan)
            ->assertStatus(422)->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        $repair = QcRepair::where('wo_item_id', $item->id)->firstOrFail();
        $this->api('spv_sewing')->postJson("/api/v1/repair/{$repair->id}/selesai", ['qty_selesai' => 6])->assertSuccessful();
        $this->inspeksi($wo, $item, 6, 6)->assertSuccessful();
        $this->buatKoli($wo, $item, 96)->assertSuccessful();
        $this->assertSame('produksi', $this->statusOrder($order), '96 < 100, belum otomatis selesai');

        $this->api('qc')->postJson("/api/v1/work-order/{$wo->id}/tutup-kurang", $alasan)->assertForbidden();
        $this->api('manajer')->postJson("/api/v1/work-order/{$wo->id}/tutup-kurang", $alasan)->assertSuccessful();

        $order = $order->fresh();
        $this->assertSame('siap_kirim', $order->status->value);
        $this->assertTrue($order->ditutup_kurang);
        $this->assertSame('ditutup_kurang', $wo->fresh()->status->value);

        $sj = $this->buatSuratJalan($order, $this->koliIds($wo))->assertCreated()->json('data.id');
        $this->kirimSuratJalan($sj)->assertOk();
        $this->assertSame(96, $item->fresh()->qty_terkirim);
        $this->assertSame('terkirim', $this->statusOrder($order));

        $this->assertIntegritasBersih();
    }

    // ===================================================== 12-13 : void produksi

    public function test_skenario_12_void_cutting_yang_sudah_dijahit_ditolak(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $order = $this->orderDisetujui(100);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        $logCutting = $this->inputProduksi('cutting', $wo, $item, 50)->assertSuccessful()->json('data.log_ids.0');
        $this->inputProduksi('sewing', $wo, $item, 30)->assertSuccessful();

        $this->api('spv_cutting')->postJson("/api/v1/produksi/log/{$logCutting}/void", ['alasan' => 'Salah input jumlah'])
            ->assertStatus(422)->assertJsonPath('code', 'VOID_DITOLAK_HILIR_NEGATIF');

        $this->assertSame(50, $item->fresh()->qty_cutting);
        $this->assertSame('aktif', ProduksiLog::findOrFail($logCutting)->status->value);

        $this->assertIntegritasBersih();
    }

    public function test_skenario_13_supervisor_void_input_sendiri_kurang_24_jam(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $order = $this->orderDisetujui(100);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        $this->inputProduksi('cutting', $wo, $item, 50)->assertSuccessful();
        $logSewing = $this->inputProduksi('sewing', $wo, $item, 30)->assertSuccessful()->json('data.log_ids.0');

        // supervisor tahap lain tidak berhak
        $this->api('spv_cutting')->postJson("/api/v1/produksi/log/{$logSewing}/void", ['alasan' => 'Bukan log saya'])->assertForbidden();

        $this->api('spv_sewing')->postJson("/api/v1/produksi/log/{$logSewing}/void", ['alasan' => 'Salah input jumlah'])->assertSuccessful();

        $this->assertSame(0, $item->fresh()->qty_sewing);
        $this->assertSame(50, $item->fresh()->qty_cutting);
        $this->assertSame('void', ProduksiLog::findOrFail($logSewing)->status->value);

        $this->inputProduksi('sewing', $wo, $item, 50)->assertSuccessful(); // input ulang setelah void

        $this->assertIntegritasBersih();
    }

    // ===================================================== 14-16 : revisi, batal, hentikan

    public function test_skenario_14_revisi_saat_menunggu_bahan_melepas_reservasi(): void
    {
        $this->siapkanSkenario(stokKain: 50);
        $order = $this->orderDisetujui(80);
        $this->assertSame('menunggu_bahan', $this->statusOrder($order));
        $this->assertSame([50.0, 50.0], $this->stokKain());
        $woLama = $this->woDari($order);

        $this->api('staf_order')->postJson("/api/v1/order/{$order->id}/revisi", ['alasan' => 'Qty berubah'])->assertForbidden();
        $this->api('manajer')->postJson("/api/v1/order/{$order->id}/revisi", ['alasan' => 'Qty berubah'])->assertSuccessful();

        $order = $order->fresh();
        $this->assertSame('draft', $order->status->value);
        $this->assertSame(1, (int) $order->revisi_ke);
        $this->assertSame('dibatalkan', $woLama->fresh()->status->value);
        $this->assertSame([50.0, 0.0], $this->stokKain());

        $this->assertIntegritasBersih();
    }

    public function test_skenario_15_batalkan_order_yang_bahannya_sudah_keluar_ditolak(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $order = $this->orderDisetujui(100);
        $this->keluarkanKain($this->woDari($order), 40)->assertSuccessful();

        $this->api('manajer')->postJson("/api/v1/order/{$order->id}/batalkan", ['alasan' => 'Pelanggan batal'])
            ->assertStatus(422)->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        $this->assertSame('produksi', $this->statusOrder($order));
        $this->assertSame('aktif', $this->woDari($order)->status->value);
        $this->assertSame([60.0, 60.0], $this->stokKain());

        $this->assertIntegritasBersih();
    }

    public function test_skenario_15b_order_menunggu_bahan_tidak_bisa_mengeluarkan_bahan_sehingga_tidak_bisa_lolos_batal(): void
    {
        $this->siapkanSkenario(stokKain: 50);
        $order = $this->orderDisetujui(80);
        $this->assertSame('menunggu_bahan', $this->statusOrder($order));

        // Celah yang ditutup di Batch 9: dulu pengeluaran parsial diterima dan order lalu bisa dibatalkan.
        $this->keluarkanKain($this->woDari($order), 50)->assertStatus(422)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->assertSame([50.0, 50.0], $this->stokKain());

        $this->api('manajer')->postJson("/api/v1/order/{$order->id}/batalkan", ['alasan' => 'Pelanggan batal'])->assertSuccessful();
        $this->assertSame('dibatalkan', $this->statusOrder($order));
        $this->assertSame([50.0, 0.0], $this->stokKain());

        $this->assertIntegritasBersih();
    }

    public function test_skenario_16_hentikan_produksi_lepas_reservasi_retur_dan_koli_tetap_bisa_dikirim(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $order = $this->orderDisetujui(100);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        $this->keluarkanKain($wo, 60)->assertSuccessful();
        $this->assertSame([40.0, 40.0], $this->stokKain()); // sisa reservasi 40 masih tertahan
        $this->produksiPenuh($wo, $item, 60);
        $this->inspeksi($wo, $item, 60, 60)->assertSuccessful();
        $this->buatKoli($wo, $item, 40)->assertSuccessful();

        $this->api('manajer')->postJson("/api/v1/order/{$order->id}/hentikan", ['alasan' => 'Pelanggan hentikan pesanan'])->assertSuccessful();

        $this->assertSame('dihentikan', $this->statusOrder($order));
        $this->assertSame('dihentikan', $wo->fresh()->status->value);
        $this->assertSame([40.0, 0.0], $this->stokKain(), 'sisa reservasi dilepas');

        // retur sisa kain (hanya boleh karena WO dihentikan)
        $woBahan = WoBahan::where('wo_id', $wo->id)->firstOrFail();
        $this->api('gudang')->postJson('/api/v1/pengeluaran-bahan', [
            'wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['cutting'], 'tipe' => 'retur',
            'tanggal' => now()->toDateString(), 'items' => [['wo_bahan_id' => $woBahan->id, 'qty' => 10]],
        ])->assertSuccessful();
        $this->assertSame([50.0, 0.0], $this->stokKain());

        // koli yang sudah dipacking tetap bisa dikirim
        $sj = $this->buatSuratJalan($order, $this->koliIds($wo))->assertCreated()->json('data.id');
        $this->kirimSuratJalan($sj)->assertOk();
        $this->assertSame(40, $item->fresh()->qty_terkirim);
        $this->assertSame('terkirim', Packing::where('wo_id', $wo->id)->firstOrFail()->status->value);

        $this->assertIntegritasBersih();
    }

    // ===================================================== 17-18 : opname, toleransi bahan

    public function test_skenario_17_opname_selisih_minus_5_ditolak_karena_di_bawah_reservasi(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $this->orderDisetujui(98); // stok_dipesan = 98

        $this->api('gudang')->postJson('/api/v1/opname', [
            'tanggal' => now()->toDateString(),
            'items' => [['bahan_id' => $this->kain->id, 'stok_fisik' => 95, 'alasan' => 'Kain rusak gudang']],
        ])->assertSuccessful();
        $opname = StokOpname::latest('id')->firstOrFail();

        $this->api('gudang')->postJson("/api/v1/opname/{$opname->id}/ajukan")->assertSuccessful();
        $this->api('gudang')->postJson("/api/v1/opname/{$opname->id}/setujui")->assertForbidden();
        $this->api('manajer')->postJson("/api/v1/opname/{$opname->id}/setujui")
            ->assertStatus(422)->assertJsonPath('code', 'STOK_DI_BAWAH_RESERVASI');

        $this->assertSame('diajukan', $opname->fresh()->status->value);
        $this->assertSame([100.0, 98.0], $this->stokKain());
        $this->assertSame(0, DB::table('stok_mutasi')->where('tipe', 'penyesuaian')->count());

        $this->assertIntegritasBersih();
    }

    public function test_skenario_18_pengeluaran_kain_melebihi_reservasi_3_persen_butuh_persetujuan_dan_di_atas_toleransi_ditolak(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $this->tambahStok(10);
        $order = $this->orderDisetujui(100);
        $wo = $this->woDari($order);
        $woBahan = WoBahan::where('wo_id', $wo->id)->firstOrFail();
        $buat = fn (float $qty, ?string $alasan) => $this->api('gudang')->postJson('/api/v1/pengeluaran-bahan', array_filter([
            'wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['cutting'], 'tipe' => 'keluar',
            'tanggal' => now()->toDateString(), 'alasan_lebih' => $alasan,
            'items' => [['wo_bahan_id' => $woBahan->id, 'qty' => $qty]],
        ], fn ($v) => $v !== null));

        // tanpa alasan: ditolak
        $buat(103, null)->assertStatus(422)->assertJsonPath('code', 'ALASAN_WAJIB');

        // 103 = 100 x 1,03 (dalam toleransi 5%): dibuat, menunggu persetujuan Manajer
        $buat(103, 'Salah potong, perlu kain ganti')->assertSuccessful();
        $dok = PengeluaranBahan::latest('id')->firstOrFail();
        $this->assertSame('menunggu', $dok->status_persetujuan);
        $this->assertSame([110.0, 100.0], $this->stokKain());

        $this->api('manajer')->postJson("/api/v1/pengeluaran-bahan/{$dok->id}/setujui")->assertSuccessful();
        $this->assertSame([7.0, 0.0], $this->stokKain());
        $this->assertEquals(103, (float) $woBahan->fresh()->qty_keluar);

        // total 106 > batas 105 (rencana 100 + 5%): ditolak
        $buat(3, 'Tambahan lagi di atas batas')->assertStatus(422)->assertJsonPath('code', 'MELEBIHI_TOLERANSI');
        $this->assertSame([7.0, 0.0], $this->stokKain());

        $this->assertIntegritasBersih();
    }

    // ===================================================== 19-21 : nomor, hak akses, pengiriman

    public function test_skenario_19_nomor_dokumen_tidak_kembar(): void
    {
        $this->siapkanSkenario(stokKain: 100);

        $nomor = [];
        for ($i = 0; $i < 6; $i++) {
            $nomor[] = $this->buatOrder(1)->no_order;
        }
        $this->assertCount(6, array_unique($nomor));

        $format = '/^ORD-'.date('ym').'-\d{4}$/';
        foreach ($nomor as $n) {
            $this->assertMatchesRegularExpression($format, $n);
        }
        $urut = array_map(fn ($n) => (int) substr($n, -4), $nomor);
        $this->assertSame(range($urut[0], $urut[0] + 5), $urut, 'urutan harus berlanjut tanpa lubang');

        // penomoran langsung: 20 nomor berturut-turut unik dan nomor_terakhir sama dengan jumlah
        $langsung = [];
        for ($i = 0; $i < 20; $i++) {
            $langsung[] = NumberingService::next('UAT');
        }
        $this->assertCount(20, array_unique($langsung));
        $this->assertSame(20, (int) DB::table('penomoran')->where('kode', 'UAT')->value('nomor_terakhir'));
    }

    public function test_skenario_20_supervisor_sewing_ke_tahap_cutting_lewat_url_langsung_403(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        $order = $this->orderDisetujui(100);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        $this->api('spv_sewing')->postJson('/api/v1/produksi/log', [
            'wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['cutting'], 'tanggal' => now()->toDateString(),
            'items' => [['wo_item_id' => $item->id, 'qty_hasil' => 10, 'qty_reject' => 0]],
        ])->assertForbidden();

        $this->api('spv_sewing')
            ->getJson("/api/v1/pengeluaran-bahan/bahan-wo?wo_id={$wo->id}&tahap_id={$this->tahapIds['cutting']}")
            ->assertForbidden();

        $this->assertSame(0, $item->fresh()->qty_cutting);
        $this->assertSame(0, ProduksiLog::count());
    }

    public function test_skenario_21_batal_pengiriman_setelah_dikirim_hanya_manajer_atau_admin(): void
    {
        $this->siapkanSkenario(stokKain: 100);
        [$order, $wo, $item] = $this->sampaiSiapKirim(10);
        $sj = $this->buatSuratJalan($order, $this->koliIds($wo))->assertCreated()->json('data.id');
        $this->kirimSuratJalan($sj)->assertOk();
        $this->assertSame('terkirim', $this->statusOrder($order));

        $this->api('gudang')->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Salah alamat tujuan'])
            ->assertForbidden();
        $this->assertSame('dikirim', Pengiriman::findOrFail($sj)->status->value);
        $this->assertSame(10, $item->fresh()->qty_terkirim);

        $this->api('manajer')->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Salah alamat tujuan'])->assertSuccessful();
        $this->assertSame('dibatalkan', Pengiriman::findOrFail($sj)->status->value);
        $this->assertSame(0, $item->fresh()->qty_terkirim);
        $this->assertSame('tersedia', Packing::where('wo_id', $wo->id)->first()->status->value);
        $this->assertSame('siap_kirim', $this->statusOrder($order));

        $this->assertIntegritasBersih();
    }

    // ===================================================== 22 : integritas akhir

    public function test_skenario_22_check_integrity_bersih_setelah_seluruh_alur_dan_mendeteksi_selisih(): void
    {
        $this->siapkanSkenario(stokKain: 500);

        // A: order lengkap sampai terkirim
        [$a, $woA, $itemA] = $this->sampaiSiapKirim(10);
        $this->kirimSuratJalan($this->buatSuratJalan($a, $this->koliIds($woA))->assertCreated()->json('data.id'))->assertOk();

        // B: reject + repair + QC ulang + tutup kurang + kirim parsial
        $b = $this->orderDisetujui(20);
        $woB = $this->woDari($b);
        $itemB = $this->itemDari($woB);
        $this->keluarkanKain($woB, 20)->assertSuccessful();
        $this->produksiPenuh($woB, $itemB, 20);
        $this->inspeksi($woB, $itemB, 20, 17, 2, 1)->assertSuccessful();
        $repair = QcRepair::where('wo_item_id', $itemB->id)->firstOrFail();
        $this->api('spv_sewing')->postJson("/api/v1/repair/{$repair->id}/selesai", ['qty_selesai' => 2])->assertSuccessful();
        $this->inspeksi($woB, $itemB, 2, 2)->assertSuccessful();
        $this->buatKoli($woB, $itemB, 19)->assertSuccessful();
        $this->api('manajer')->postJson("/api/v1/work-order/{$woB->id}/tutup-kurang", ['alasan' => 'Reject 1 pcs diterima pelanggan'])->assertSuccessful();

        // C: direvisi saat siap_produksi
        $c = $this->orderDisetujui(10);
        $this->api('manajer')->postJson("/api/v1/order/{$c->id}/revisi", ['alasan' => 'Warna diganti'])->assertSuccessful();

        // D: dihentikan setelah pengeluaran sebagian
        $d = $this->orderDisetujui(10);
        $woD = $this->woDari($d);
        $this->keluarkanKain($woD, 4)->assertSuccessful();
        $this->api('manajer')->postJson("/api/v1/order/{$d->id}/hentikan", ['alasan' => 'Pelanggan hentikan pesanan'])->assertSuccessful();

        // E: menunggu bahan karena stok habis dipesan
        $e = $this->orderDisetujui(400);
        $this->assertContains($this->statusOrder($e), ['menunggu_bahan', 'siap_produksi']);

        $this->assertIntegritasBersih();
        $this->assertSame(0, Artisan::call('sima:check-integrity', ['--tanpa-notifikasi' => true]));
        $this->assertSame(0, Artisan::call('sima:recalc-counters', ['--dry-run' => true]));

        // kontrol negatif: selisih sengaja dibuat harus terdeteksi
        DB::table('bahan')->where('id', $this->kain->id)->update(['stok' => DB::raw('stok + 5')]);
        $this->assertNotSame(0, Artisan::call('sima:check-integrity', ['--tanpa-notifikasi' => true]));
        DB::table('bahan')->where('id', $this->kain->id)->update(['stok' => DB::raw('stok - 5')]);
        $this->assertIntegritasBersih();
    }
}