<?php

namespace Tests\Feature;

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
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Batch 8: dashboard per role dan 16 laporan.
 *
 * Data uji dibuat sekali di setUp() dan semua angka KPI di bawah dihitung manual:
 *
 *  WO A (order A, produksi, qty 100): cutting 90 baik + 10 reject, sewing 60, finishing 0
 *      -> progres cutting 90%, sewing 60%, finishing 0%
 *      -> antrean sewing = 90 - 60 = 30, antrean finishing = 60 - 0 = 60, sisa boleh potong = 103 - 100 = 3
 *  Log produksi: cutting hari ini 90/10 reject (rate 10%), sewing hari ini 40 + kemarin 20 (+ 1 log void 999 yang diabaikan)
 *  QC: diperiksa 20 -> lolos 12, repair 6 (open 4 setelah 2 selesai), reject 2 -> reject rate 2 / (12+2) = 14,29%
 *  On-time delivery: 3 order terkirim bulan ini, 2 tepat waktu -> 66,67%
 */
class Batch8ReportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,User> */
    protected array $u = [];

    /** @var array<string,int> */
    protected array $tahap = [];

    protected int $woItemId;

    protected int $bahanK1;

    protected int $bahanK2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['cutting', 'Pemotongan', 1], ['sewing', 'Penjahitan', 2], ['finishing', 'Finishing', 3]] as [$kode, $nama, $urutan]) {
            $this->tahap[$kode] = Tahap::create(['kode' => $kode, 'nama' => $nama, 'urutan' => $urutan])->id;
        }

        foreach (['admin', 'manajer', 'staf_order', 'gudang', 'qc', 'pimpinan'] as $role) {
            $this->u[$role] = User::factory()->create(['role' => $role]);
        }
        $this->u['spv_sewing'] = User::factory()->create(['role' => 'supervisor', 'tahap_id' => $this->tahap['sewing']]);
        $this->u['spv_tanpa_tahap'] = User::factory()->create(['role' => 'supervisor', 'tahap_id' => null]);

        $this->buatDataUji();
    }

    // ------------------------------------------------------------------
    // Data uji
    // ------------------------------------------------------------------

    protected function ins(string $table, array $data, bool $timestamps = true): int
    {
        if ($timestamps) {
            $data += ['created_at' => now(), 'updated_at' => now()];
        }

        return DB::table($table)->insertGetId($data);
    }

    protected function buatOrder(string $no, string $status, int $deadlineOffset, string $pembuat = 'admin'): Order
    {
        $order = Order::create([
            'no_order' => $no,
            'pelanggan_id' => $this->pelangganId,
            'tgl_order' => today()->toDateString(),
            'deadline' => today()->addDays($deadlineOffset)->toDateString(),
            'status' => $status,
            'dibuat_oleh' => $this->u[$pembuat]->id,
            'alamat_kirim' => 'Jl. Tujuan 1',
        ]);
        DB::table('orders')->where('id', $order->id)->update(['total_qty' => 100]);

        return $order;
    }

    protected int $pelangganId;

    protected function buatDataUji(): void
    {
        $hari = today()->toDateString();
        $kemarin = today()->subDay()->toDateString();

        $this->pelangganId = Pelanggan::create(['kode' => 'PLG-1', 'nama' => 'Pelanggan Tes', 'alamat' => 'Alamat'])->id;
        $produk = Produk::create(['kode' => 'PRD-1', 'nama' => 'Kaos Tes']);
        $warna = Warna::create(['nama' => 'Hitam']);
        $ukuran = Ukuran::create(['kode' => 'M']);

        // Order: A (produksi, milik staf), B1-B3 (terkirim), C (diajukan, staf), D (telat, staf), E (siap kirim)
        $a = $this->buatOrder('ORD-A', 'produksi', 10, 'staf_order');
        $b1 = $this->buatOrder('ORD-B1', 'terkirim', 0);
        $b2 = $this->buatOrder('ORD-B2', 'terkirim', 3);
        $b3 = $this->buatOrder('ORD-B3', 'terkirim', -2);
        $this->buatOrder('ORD-C', 'diajukan', 5, 'staf_order');
        $this->buatOrder('ORD-D', 'produksi', -3, 'staf_order');
        $this->buatOrder('ORD-E', 'siap_kirim', 2);

        $orderItem = OrderItem::create([
            'order_id' => $a->id, 'produk_id' => $produk->id, 'warna_id' => $warna->id, 'ukuran_id' => $ukuran->id, 'qty' => 100,
        ]);

        $wo = WorkOrder::create([
            'no_wo' => 'WO-A', 'order_id' => $a->id, 'revisi_ke' => 0, 'status' => 'aktif',
            'tgl_target' => today()->addDays(8)->toDateString(), 'dibuat_oleh' => $this->u['admin']->id,
        ]);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahap['cutting'], 'status' => 'berjalan']);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahap['sewing'], 'status' => 'berjalan']);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahap['finishing'], 'status' => 'menunggu']);

        $woItem = WoItem::create([
            'wo_id' => $wo->id, 'order_item_id' => $orderItem->id, 'qty_order' => 100, 'qty_batas_potong' => 103,
            'qty_cutting' => 90, 'qty_cutting_reject' => 10, 'qty_sewing' => 60,
        ]);
        $this->woItemId = $woItem->id;

        // Master pendukung
        $satuan = $this->ins('satuan', ['kode' => 'm', 'nama' => 'Meter']);
        $supplier = $this->ins('supplier', ['kode' => 'SUP-1', 'nama' => 'Supplier Tes']);
        $this->bahanK1 = $this->ins('bahan', ['kode' => 'K1', 'nama' => 'Kain Katun', 'jenis' => 'kain', 'satuan_id' => $satuan, 'stok' => 10, 'stok_dipesan' => 10, 'stok_minimum' => 20, 'is_active' => 1]);
        $this->bahanK2 = $this->ins('bahan', ['kode' => 'K2', 'nama' => 'Benang', 'jenis' => 'benang', 'satuan_id' => $satuan, 'stok' => 100, 'stok_dipesan' => 30, 'stok_minimum' => 20, 'is_active' => 1]);

        $jcBolong = $this->ins('jenis_cacat', ['kode' => 'JC1', 'nama' => 'Bolong kain', 'kategori' => 'kain', 'is_active' => 1]);
        $jcLompat = $this->ins('jenis_cacat', ['kode' => 'JC2', 'nama' => 'Jahitan lompat', 'kategori' => 'jahitan', 'is_active' => 1]);
        $jcNoda = $this->ins('jenis_cacat', ['kode' => 'JC3', 'nama' => 'Noda', 'kategori' => 'kotor', 'is_active' => 1]);
        $lineSew = $this->ins('line_produksi', ['kode' => 'SEW-1', 'nama' => 'Line Jahit 1', 'tahap_id' => $this->tahap['sewing'], 'kapasitas_per_hari' => 80, 'is_active' => 1]);

        // Kebutuhan bahan WO A
        $this->ins('wo_bahan', ['wo_id' => $wo->id, 'bahan_id' => $this->bahanK2, 'tahap_id' => $this->tahap['cutting'], 'qty_rencana' => 50, 'qty_reservasi' => 50, 'qty_keluar' => 20, 'qty_retur' => 0]);
        $this->ins('wo_bahan', ['wo_id' => $wo->id, 'bahan_id' => $this->bahanK1, 'tahap_id' => $this->tahap['sewing'], 'qty_rencana' => 25, 'qty_reservasi' => 10, 'qty_keluar' => 0, 'qty_retur' => 0]);

        // Log produksi
        $log = fn (array $d) => $this->ins('produksi_log', $d + ['wo_item_id' => $woItem->id, 'status' => 'aktif', 'dibuat_oleh' => $this->u['admin']->id]);
        $log(['tahap_id' => $this->tahap['cutting'], 'tanggal' => $hari, 'qty_hasil' => 90, 'qty_reject' => 10, 'jenis_cacat_id' => $jcBolong]);
        $log(['tahap_id' => $this->tahap['sewing'], 'tanggal' => $hari, 'qty_hasil' => 40, 'line_id' => $lineSew]);
        $log(['tahap_id' => $this->tahap['sewing'], 'tanggal' => $kemarin, 'qty_hasil' => 20, 'line_id' => $lineSew]);
        $log(['tahap_id' => $this->tahap['sewing'], 'tanggal' => $hari, 'qty_hasil' => 999, 'line_id' => $lineSew, 'status' => 'void']);

        // QC: diperiksa 20 = lolos 12 + repair 6 + reject 2; repair 6 (2 selesai)
        $qc = $this->ins('qc_inspeksi', ['no_qc' => 'QC-T-1', 'wo_id' => $wo->id, 'tanggal' => $hari, 'status' => 'aktif', 'qc_id' => $this->u['qc']->id]);
        $qcItem = $this->ins('qc_inspeksi_items', ['qc_inspeksi_id' => $qc, 'wo_item_id' => $woItem->id, 'qty_diperiksa' => 20, 'qty_lolos' => 12, 'qty_repair' => 6, 'qty_reject' => 2]);
        $this->ins('qc_inspeksi_cacat', ['qc_inspeksi_item_id' => $qcItem, 'jenis_cacat_id' => $jcLompat, 'qty' => 6, 'tindakan' => 'repair']);
        $this->ins('qc_inspeksi_cacat', ['qc_inspeksi_item_id' => $qcItem, 'jenis_cacat_id' => $jcNoda, 'qty' => 2, 'tindakan' => 'reject']);
        $this->ins('qc_repair', ['qc_inspeksi_item_id' => $qcItem, 'wo_item_id' => $woItem->id, 'qty' => 6, 'tahap_tujuan_id' => $this->tahap['sewing'], 'status' => 'open', 'qty_selesai' => 2]);

        // Pembelian dan penerimaan: PO 10 x 5.000, diterima 4
        $po = $this->ins('purchase_orders', ['no_po' => 'PO-T-1', 'supplier_id' => $supplier, 'tgl_po' => $hari, 'status' => 'diterima_sebagian', 'dibuat_oleh' => $this->u['gudang']->id]);
        $poItem = $this->ins('purchase_order_items', ['po_id' => $po, 'bahan_id' => $this->bahanK1, 'qty_pesan' => 10, 'qty_diterima' => 4, 'harga_satuan' => 5000]);
        $pb = $this->ins('penerimaan_bahan', ['no_bm' => 'BM-T-1', 'po_id' => $po, 'supplier_id' => $supplier, 'tgl_terima' => $hari, 'status' => 'aktif', 'diterima_oleh' => $this->u['gudang']->id]);
        $this->ins('penerimaan_bahan_items', ['penerimaan_id' => $pb, 'po_item_id' => $poItem, 'bahan_id' => $this->bahanK1, 'qty' => 4, 'harga_satuan' => 5000]);

        // Kartu stok K1: masuk 30 (0 -> 30), keluar 20 (30 -> 10)
        $this->ins('stok_mutasi', ['bahan_id' => $this->bahanK1, 'tanggal' => now(), 'tipe' => 'masuk', 'qty' => 30, 'stok_sebelum' => 0, 'stok_sesudah' => 30, 'ref_tipe' => 'manual', 'keterangan' => 'Saldo awal', 'user_id' => $this->u['gudang']->id]);
        $this->ins('stok_mutasi', ['bahan_id' => $this->bahanK1, 'tanggal' => now(), 'tipe' => 'keluar', 'qty' => -20, 'stok_sebelum' => 30, 'stok_sesudah' => 10, 'ref_tipe' => 'manual', 'keterangan' => 'Pakai', 'user_id' => $this->u['gudang']->id]);

        // Opname
        $opname = $this->ins('stok_opname', ['no_opname' => 'OPN-T-1', 'tanggal' => $hari, 'status' => 'disetujui', 'dibuat_oleh' => $this->u['gudang']->id]);
        $this->ins('stok_opname_items', ['opname_id' => $opname, 'bahan_id' => $this->bahanK1, 'stok_sistem' => 10, 'stok_fisik' => 8, 'selisih' => -2, 'alasan' => 'Rusak']);

        // Pengiriman: B1 (tepat), B2 (tepat), B3 (telat), SJ order A (1 koli, 30 pcs), SJ order A masih disiapkan
        $sj = fn (int $order, string $no, string $status, ?string $tgl) => $this->ins('pengiriman', ['no_sj' => $no, 'order_id' => $order, 'tgl_kirim' => $tgl, 'status' => $status, 'dibuat_oleh' => $this->u['admin']->id]);
        $sj($b1->id, 'SJ-T-B1', 'dikirim', $hari);
        $sj($b2->id, 'SJ-T-B2', 'dikirim', $hari);
        $sj($b3->id, 'SJ-T-B3', 'dikirim', $hari);
        $sjA = $sj($a->id, 'SJ-T-A', 'dikirim', $hari);
        $sj($a->id, 'SJ-T-A2', 'disiapkan', null);

        $koli = $this->ins('packing', ['no_koli' => 'PK-T-1', 'wo_id' => $wo->id, 'urutan_koli' => 1, 'tanggal' => $hari, 'status' => 'terkirim', 'dibuat_oleh' => $this->u['admin']->id]);
        $this->ins('packing_items', ['packing_id' => $koli, 'wo_item_id' => $woItem->id, 'qty' => 30]);
        $this->ins('pengiriman_koli', ['pengiriman_id' => $sjA, 'packing_id' => $koli]);

        // Audit log
        DB::table('activity_log')->insert([
            'user_id' => $this->u['admin']->id, 'aksi' => 'order.setujui', 'subjek_tipe' => 'Order', 'subjek_id' => $a->id,
            'alasan' => 'uji', 'ip' => '127.0.0.1', 'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helper permintaan
    // ------------------------------------------------------------------

    protected function lap(string $jenis, array $query = [], string $role = 'admin')
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $qs = $query ? '?'.http_build_query($query) : '';

        return $this->actingAs($this->u[$role], 'sanctum')->getJson("/api/v1/laporan/{$jenis}{$qs}");
    }

    protected function nilai($response, string $label): mixed
    {
        foreach ($response->json('data.ringkasan') as $r) {
            if ($r['label'] === $label) {
                return $r['nilai'];
            }
        }

        $this->fail("Ringkasan '{$label}' tidak ditemukan.");
    }

    protected function baris($response): \Illuminate\Support\Collection
    {
        return collect($response->json('data.baris'));
    }

    /** Parameter wajib agar semua jenis laporan bisa dipanggil. */
    protected function semuaJenis(): array
    {
        return array_keys(ReportService::JENIS);
    }

    protected function parameterJenis(string $jenis): array
    {
        return $jenis === 'kartu-stok' ? ['bahan_id' => $this->bahanK1] : [];
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function test_setiap_role_mendapat_dashboard_sesuai_tipenya(): void
    {
        $harapan = [
            'admin' => 'eksekutif', 'manajer' => 'eksekutif', 'pimpinan' => 'eksekutif',
            'staf_order' => 'staf_order', 'gudang' => 'gudang', 'spv_sewing' => 'supervisor', 'qc' => 'qc',
        ];

        foreach ($harapan as $role => $tipe) {
            $this->actingAs($this->u[$role], 'sanctum')
                ->getJson('/api/v1/dashboard')
                ->assertOk()
                ->assertJsonPath('data.tipe', $tipe);
        }

               
    }

    public function test_dashboard_eksekutif_angka_kpi_sesuai_hitungan_manual(): void
    {
        $d = $this->actingAs($this->u['manajer'], 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $status = collect($d['order_aktif_per_status'])->pluck('jumlah', 'status');
        $this->assertEquals(2, $status['produksi']);
        $this->assertEquals(1, $status['diajukan']);
        $this->assertEquals(1, $status['siap_kirim']);
        $this->assertEquals(0, $status['menunggu_bahan']);

        $this->assertEquals(1, $d['order_terlambat']['total']);
        $this->assertEquals('ORD-D', $d['order_terlambat']['daftar'][0]['no_order']);
        $this->assertEquals(3, $d['order_terlambat']['daftar'][0]['hari_terlambat']);

        $this->assertEquals(1, $d['order_mendekati_deadline']['total']);
        $this->assertEquals('ORD-E', $d['order_mendekati_deadline']['daftar'][0]['no_order']);

        $this->assertEquals(90, $d['progres_tahap']['cutting']);
        $this->assertEquals(60, $d['progres_tahap']['sewing']);
        $this->assertEquals(0, $d['progres_tahap']['finishing']);
        $this->assertEquals(0, $d['yield_produksi']);

        $this->assertEquals(30, $d['wip']['sewing_tersedia']);
        $this->assertEquals(60, $d['wip']['finishing_tersedia']);
        $this->assertEquals(0, $d['wip']['qc_tersedia']);

        $this->assertCount(7, $d['output_7_hari']);
        $hariIni = end($d['output_7_hari']);
        $this->assertEquals(today()->toDateString(), $hariIni['tanggal']);
        $this->assertEquals(90, $hariIni['cutting']);
        $this->assertEquals(40, $hariIni['sewing']);
        $this->assertEquals(20, $d['output_7_hari'][5]['sewing']);

        $rejectCutting = collect($d['reject_rate'])->firstWhere('tahap', 'cutting');
        $this->assertEquals(10, $rejectCutting['reject_rate']);

        $this->assertEquals(1, $d['stok_di_bawah_minimum']['total']);
        $this->assertEquals('K1', $d['stok_di_bawah_minimum']['daftar'][0]['kode']);
        $this->assertEquals(1, $d['order_menunggu_persetujuan']);

        $this->assertEquals(3, $d['on_time_delivery_bulan_ini']['total_order_terkirim']);
        $this->assertEquals(2, $d['on_time_delivery_bulan_ini']['tepat_waktu']);
        $this->assertEqualsWithDelta(66.67, $d['on_time_delivery_bulan_ini']['persen'], 0.001);
    }

    public function test_dashboard_staf_order_hanya_order_miliknya(): void
    {
        $d = $this->actingAs($this->u['staf_order'], 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $status = collect($d['order_saya_per_status'])->pluck('jumlah', 'status');
        $this->assertEquals(2, $status['produksi']); // A dan D
        $this->assertEquals(1, $status['diajukan']); // C
        $this->assertEquals(0, $status['siap_kirim']); // E milik admin

        $noOrder = collect($d['order_aktif'])->pluck('no_order')->sort()->values()->all();
        $this->assertEquals(['ORD-A', 'ORD-C', 'ORD-D'], $noOrder);

        $a = collect($d['order_aktif'])->firstWhere('no_order', 'ORD-A');
        $this->assertEquals(90, $a['progres']['cutting']);
        $this->assertEquals(60, $a['progres']['sewing']);

        $this->assertCount(2, $d['pengiriman_terbaru']); // SJ-T-A dan SJ-T-A2
    }

    public function test_dashboard_gudang(): void
    {
        $d = $this->actingAs($this->u['gudang'], 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertEquals(1, $d['bahan_di_bawah_minimum']['total']);
        $this->assertEquals(1, $d['po_berjalan']['total']);
        $this->assertEquals('PO-T-1', $d['po_berjalan']['daftar'][0]['no_po']);
        $this->assertEquals(1, $d['wo_siap_dikeluarkan']['total']);
        $this->assertEquals(4, $d['pengiriman_hari_ini']['total']);
        $this->assertEquals(1, $d['pengiriman_hari_ini']['sj_disiapkan']);
        $this->assertEquals(0, $d['kekurangan_bahan']['total']); // order A sudah produksi, bukan menunggu_bahan
        $this->assertEquals(0, $d['opname']['draft']);
    }

    public function test_dashboard_supervisor_hanya_tahapnya(): void
    {
        $d = $this->actingAs($this->u['spv_sewing'], 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertEquals('sewing', $d['tahap']);
        $this->assertCount(1, $d['antrean']);
        $this->assertEquals(30, $d['antrean'][0]['tersedia_dikerjakan']); // 90 - 60

        $this->assertEquals(40, $d['output_hari_ini']['total_hasil']); // log void 999 diabaikan
        $this->assertEquals(80, $d['output_hari_ini']['per_line'][0]['target']);
        $this->assertEquals(50, $d['output_hari_ini']['per_line'][0]['persen_target']);

        $this->assertEquals(4, $d['repair_open']['total_pcs']); // 6 - 2

        // Supervisor tanpa tahap: kosong, bukan error
        $kosong = $this->actingAs($this->u['spv_tanpa_tahap'], 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $this->assertNull($kosong['tahap']);
        $this->assertSame([], $kosong['antrean']);
    }

    public function test_dashboard_qc(): void
    {
        $d = $this->actingAs($this->u['qc'], 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertEquals(12, $d['reject_rate']['lolos']);
        $this->assertEquals(2, $d['reject_rate']['reject']);
        $this->assertEqualsWithDelta(14.29, $d['reject_rate']['reject_rate'], 0.001); // 2 / (12 + 2)
        $this->assertEquals('Jahitan lompat', $d['top_jenis_cacat'][0]['nama']);
        $this->assertEquals(6, $d['top_jenis_cacat'][0]['qty']);
        $this->assertEquals(0, $d['antrean_inspeksi']['total_pcs']);
    }

    // ------------------------------------------------------------------
    // Hak akses laporan
    // ------------------------------------------------------------------

    public function test_matriks_hak_akses_laporan(): void
    {
        // [role, jenis, status HTTP yang diharapkan]
        $kasus = [
            ['staf_order', 'order', 200], ['staf_order', 'pengiriman', 200],
            ['staf_order', 'stok-bahan', 403], ['staf_order', 'progres-wo', 403], ['staf_order', 'audit-log', 403],
            ['gudang', 'stok-bahan', 200], ['gudang', 'pembelian', 200], ['gudang', 'pengiriman', 200],
            ['gudang', 'order', 403], ['gudang', 'produksi-harian', 403], ['gudang', 'audit-log', 403],
            ['spv_sewing', 'produksi-harian', 200], ['spv_sewing', 'wip', 200], ['spv_sewing', 'repair', 200],
            ['spv_sewing', 'stok-bahan', 403], ['spv_sewing', 'order', 403], ['spv_sewing', 'pengiriman', 403],
            ['qc', 'reject', 200], ['qc', 'repair', 200],
            ['qc', 'produksi-harian', 403], ['qc', 'order', 403], ['qc', 'stok-bahan', 403],
            ['pimpinan', 'order', 200], ['pimpinan', 'stok-bahan', 200], ['pimpinan', 'on-time-delivery', 200],
            ['pimpinan', 'audit-log', 403],
            ['manajer', 'audit-log', 200], ['manajer', 'on-time-delivery', 200],
            ['spv_tanpa_tahap', 'produksi-harian', 403],
        ];

        foreach ($kasus as [$role, $jenis, $status]) {
            $this->lap($jenis, $this->parameterJenis($jenis), $role)
                ->assertStatus($status);
        }

        $this->lap('tidak-ada')->assertNotFound();
    }

    public function test_daftar_jenis_laporan_mengikuti_role(): void
    {
        $this->assertCount(16, $this->actingAs($this->u['admin'], 'sanctum')->getJson('/api/v1/laporan')->assertOk()->json('data'));

        $stafOrder = collect($this->actingAs($this->u['staf_order'], 'sanctum')->getJson('/api/v1/laporan')->json('data'))->pluck('jenis')->sort()->values()->all();
        $this->assertEquals(['order', 'pengiriman'], $stafOrder);

        $qc = collect($this->actingAs($this->u['qc'], 'sanctum')->getJson('/api/v1/laporan')->json('data'))->pluck('jenis')->sort()->values()->all();
        $this->assertEquals(['reject', 'repair'], $qc);
    }

    public function test_laporan_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/v1/laporan/order')->assertUnauthorized();
        $this->getJson('/api/v1/laporan')->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // Isi dan KPI laporan
    // ------------------------------------------------------------------

    public function test_semua_laporan_menghasilkan_json_tanpa_error(): void
    {
        foreach ($this->semuaJenis() as $jenis) {
            $r = $this->lap($jenis, $this->parameterJenis($jenis))->assertOk();
            $r->assertJsonStructure(['data' => ['jenis', 'judul', 'periode', 'kolom', 'ringkasan', 'baris'], 'meta' => ['current_page', 'per_page', 'total', 'last_page', 'terpotong']]);
            $this->assertEquals($jenis, $r->json('data.jenis'), $jenis);
        }
    }

    public function test_semua_laporan_menghasilkan_pdf(): void
    {
        foreach ($this->semuaJenis() as $jenis) {
            $r = $this->actingAs($this->u['admin'], 'sanctum');
            $this->withoutMiddleware(ThrottleRequests::class);
            $resp = $r->get('/api/v1/laporan/'.$jenis.'?'.http_build_query(['format' => 'pdf'] + $this->parameterJenis($jenis)));

            $resp->assertOk();
            $resp->assertHeader('content-type', 'application/pdf');
            $this->assertStringContainsString('laporan-'.$jenis, $resp->headers->get('content-disposition'), $jenis);
        }
    }

    public function test_semua_laporan_menghasilkan_excel(): void
    {
        foreach ($this->semuaJenis() as $jenis) {
            $this->withoutMiddleware(ThrottleRequests::class);
            $resp = $this->actingAs($this->u['admin'], 'sanctum')
                ->get('/api/v1/laporan/'.$jenis.'?'.http_build_query(['format' => 'xlsx'] + $this->parameterJenis($jenis)));

            $resp->assertOk();
            $this->assertStringContainsString('.xlsx', $resp->headers->get('content-disposition'), $jenis);
        }
    }

    public function test_laporan_order(): void
    {
        $r = $this->lap('order')->assertOk();
        $baris = $this->baris($r);

        $this->assertCount(7, $baris);
        $this->assertEquals(7, $this->nilai($r, 'Jumlah Order'));
        $this->assertEquals(700, $this->nilai($r, 'Total Qty'));
        $this->assertTrue($baris->firstWhere('no_order', 'ORD-D')['terlambat']);
        $this->assertFalse($baris->firstWhere('no_order', 'ORD-A')['terlambat']);
        $this->assertFalse($baris->firstWhere('no_order', 'ORD-B3')['terlambat']); // sudah terkirim

        $this->assertCount(1, $this->baris($this->lap('order', ['status' => 'diajukan'])));
    }

    public function test_laporan_progres_wo(): void
    {
        $r = $this->lap('progres-wo')->assertOk();
        $row = $this->baris($r)->first();

        $this->assertEquals('WO-A', $row['no_wo']);
        $this->assertEquals(90, $row['progres_cutting']);
        $this->assertEquals(60, $row['progres_sewing']);
        $this->assertEquals(0, $row['progres_finishing']);
        $this->assertEquals(100, $row['kekurangan']); // qty_qc_lolos di wo_items = 0, jadi kekurangan = 100 - 0
        $this->assertEquals(0, $row['kelebihan']);

        $this->assertEquals(90, $this->nilai($r, 'Progres Cutting'));
        $this->assertEquals(0, $this->nilai($r, 'Yield Produksi'));
    }

    public function test_laporan_produksi_harian_dan_produktivitas_line(): void
    {
        $r = $this->lap('produksi-harian', ['tgl_dari' => today()->subDay()->toDateString(), 'tgl_sampai' => today()->toDateString()])->assertOk();
        $baris = $this->baris($r);

        $cutting = $baris->firstWhere('tahap', 'Pemotongan');
        $this->assertEquals(90, $cutting['hasil']);
        $this->assertEquals(10, $cutting['reject']);
        $this->assertEquals(10, $cutting['reject_rate']);
        $this->assertEquals('Tanpa line', $cutting['line']);

        $sewing = $baris->where('tahap', 'Penjahitan');
        $this->assertCount(2, $sewing); // kemarin dan hari ini; log void tidak dihitung
        $this->assertEquals(60, $sewing->sum('hasil'));

        $this->assertEquals(30, $this->nilai($r, 'Produktivitas Line Jahit 1 (Sewing, pcs/hari)')); // 60 / 2 hari
        $this->assertEquals(60, $this->nilai($r, 'Total Hasil Sewing'));
    }

    public function test_laporan_wip(): void
    {
        $r = $this->lap('wip')->assertOk();
        $row = $this->baris($r)->first();

        $this->assertNull($r->json('data.periode'));
        $this->assertEquals(30, $row['sewing_tersedia']);
        $this->assertEquals(60, $row['finishing_tersedia']);
        $this->assertEquals(3, $row['cutting_boleh']); // 103 - (90 + 10)
        $this->assertEquals(0, $row['qc_tersedia']);
        $this->assertEquals(30, $this->nilai($r, 'Total Antrean Sewing'));
    }

    public function test_laporan_reject_per_tahap_dan_jenis_cacat(): void
    {
        $r = $this->lap('reject')->assertOk();
        $baris = $this->baris($r);

        $this->assertEquals(10, $baris->firstWhere('kelompok', 'Pemotongan')['reject_rate']); // 10 / (90 + 10)
        $this->assertEquals(0, $baris->firstWhere('kelompok', 'Penjahitan')['reject_rate']);
        $qc = $baris->firstWhere('kelompok', 'Final QC');
        $this->assertEquals(12, $qc['hasil']);
        $this->assertEquals(2, $qc['reject']);
        $this->assertEqualsWithDelta(14.29, $qc['reject_rate'], 0.001);

        $cacat = $this->baris($this->lap('reject', ['kelompok' => 'jenis_cacat']));
        $this->assertEquals(10, $cacat->firstWhere('jenis_cacat', 'Bolong kain')['reject']);
        $this->assertEquals(2, $cacat->firstWhere('jenis_cacat', 'Noda')['reject']);
        $this->assertEqualsWithDelta(83.33, $cacat->firstWhere('jenis_cacat', 'Bolong kain')['kontribusi'], 0.001);

        $this->lap('reject', ['kelompok' => 'sembarang'])->assertStatus(422);
    }

    public function test_laporan_repair(): void
    {
        $r = $this->lap('repair')->assertOk();
        $row = $this->baris($r)->first();

        $this->assertEquals(6, $row['qty']);
        $this->assertEquals(2, $row['qty_selesai']);
        $this->assertEquals(4, $row['qty_open']);
        $this->assertEquals('Penjahitan', $row['tahap_tujuan']);
        $this->assertEquals(4, $this->nilai($r, 'Pcs Masih Open'));
    }

    public function test_laporan_stok_bahan_dan_filter_di_bawah_minimum(): void
    {
        $semua = $this->baris($this->lap('stok-bahan')->assertOk());
        $this->assertCount(2, $semua);

        $k1 = $semua->firstWhere('kode', 'K1');
        $this->assertEquals(0, $k1['stok_tersedia']); // 10 - 10
        $this->assertTrue($k1['di_bawah_minimum']);
        $this->assertFalse($semua->firstWhere('kode', 'K2')['di_bawah_minimum']);

        $bawah = $this->baris($this->lap('stok-bahan', ['di_bawah_minimum' => 1]));
        $this->assertCount(1, $bawah);
        $this->assertEquals('K1', $bawah->first()['kode']);
    }

    public function test_laporan_kartu_stok(): void
    {
        $r = $this->lap('kartu-stok', ['bahan_id' => $this->bahanK1])->assertOk();

        $this->assertCount(2, $this->baris($r));
        $this->assertEquals(0, $this->nilai($r, 'Saldo Awal'));
        $this->assertEquals(30, $this->nilai($r, 'Total Masuk'));
        $this->assertEquals(20, $this->nilai($r, 'Total Keluar'));
        $this->assertEquals(10, $this->nilai($r, 'Saldo Akhir'));

        $this->lap('kartu-stok')->assertStatus(422)->assertJsonValidationErrors('bahan_id');
        $this->lap('kartu-stok', ['bahan_id' => 99999])->assertNotFound();
    }

    public function test_laporan_kebutuhan_vs_stok(): void
    {
        $baris = $this->baris($this->lap('kebutuhan-vs-stok')->assertOk());

        // K1: kebutuhan sisa 25, stok 10, PO berjalan 10 - 4 = 6 -> proyeksi 10 + 6 - 25 = -9
        $k1 = $baris->firstWhere('kode', 'K1');
        $this->assertEquals(25, $k1['kebutuhan_sisa']);
        $this->assertEquals(6, $k1['po_dalam_perjalanan']);
        $this->assertEquals(-9, $k1['proyeksi_saldo']);
        $this->assertEquals(9, $k1['kekurangan']);
        $this->assertEquals('Kurang', $k1['status']);

        // K2: kebutuhan sisa 50 - 20 = 30, stok 100 -> cukup
        $k2 = $baris->firstWhere('kode', 'K2');
        $this->assertEquals(30, $k2['kebutuhan_sisa']);
        $this->assertEquals(0, $k2['kekurangan']);
        $this->assertEquals('Cukup', $k2['status']);
    }

    public function test_laporan_pemakaian_bahan(): void
    {
        $baris = $this->baris($this->lap('pemakaian-bahan')->assertOk());

        $k2 = $baris->firstWhere('kode_bahan', 'K2');
        $this->assertEquals(50, $k2['rencana']);
        $this->assertEquals(20, $k2['aktual']);
        $this->assertEquals(-30, $k2['selisih']);
        $this->assertEquals(-60, $k2['selisih_persen']);
    }

    public function test_laporan_pembelian_dan_penerimaan(): void
    {
        $po = $this->lap('pembelian')->assertOk();
        $row = $this->baris($po)->first();
        $this->assertEquals(50000, $row['total_nilai']);
        $this->assertEquals(20000, $row['nilai_diterima']);
        $this->assertEquals(1, $row['jumlah_item']);

        $bm = $this->lap('penerimaan')->assertOk();
        $this->assertEquals(20000, $this->baris($bm)->first()['nilai']);
        $this->assertEquals(20000, $this->nilai($bm, 'Total Nilai'));
    }

    public function test_laporan_pengiriman(): void
    {
        $r = $this->lap('pengiriman')->assertOk();

        $this->assertCount(5, $this->baris($r));
        $this->assertEquals(5, $this->nilai($r, 'Jumlah Surat Jalan (tanpa dibatalkan)'));
        $this->assertEquals(1, $this->nilai($r, 'Total Koli'));
        $this->assertEquals(30, $this->nilai($r, 'Total Qty'));

        $sjA = $this->baris($r)->firstWhere('no_sj', 'SJ-T-A');
        $this->assertEquals(1, $sjA['jumlah_koli']);
        $this->assertEquals(30, $sjA['total_qty']);
    }

    public function test_laporan_on_time_delivery(): void
    {
        $r = $this->lap('on-time-delivery')->assertOk();
        $baris = $this->baris($r);

        $this->assertCount(3, $baris);
        $this->assertEquals(3, $this->nilai($r, 'Total Order Terkirim'));
        $this->assertEquals(2, $this->nilai($r, 'Tepat Waktu'));
        $this->assertEqualsWithDelta(66.67, $this->nilai($r, 'On-Time Delivery'), 0.001);

        $telat = $baris->firstWhere('no_order', 'ORD-B3');
        $this->assertFalse($telat['tepat_waktu']);
        $this->assertEquals(2, $telat['selisih_hari']);
        $this->assertTrue($baris->firstWhere('no_order', 'ORD-B2')['tepat_waktu']);
    }

    public function test_laporan_opname_dan_audit_log(): void
    {
        $opname = $this->baris($this->lap('opname')->assertOk())->first();
        $this->assertEquals(-2, $opname['selisih']);
        $this->assertEquals('OPN-T-1', $opname['no_opname']);

        $audit = $this->lap('audit-log', [], 'manajer')->assertOk();
        $this->assertCount(1, $this->baris($audit));
        $this->assertEquals('order.setujui', $this->baris($audit)->first()['aksi']);
        $this->assertEquals(0, $this->baris($this->lap('audit-log', ['aksi' => 'tidak.ada']))->count());
    }

    // ------------------------------------------------------------------
    // Pembatasan data supervisor (scope tahap)
    // ------------------------------------------------------------------

    public function test_supervisor_hanya_melihat_data_tahapnya_di_produksi_harian(): void
    {
        $r = $this->lap('produksi-harian', ['tgl_dari' => today()->subDay()->toDateString()], 'spv_sewing')->assertOk();

        $this->assertTrue($this->baris($r)->every(fn ($b) => $b['tahap'] === 'Penjahitan'));
        $this->assertCount(2, $this->baris($r));

        $labels = collect($r->json('data.ringkasan'))->pluck('label')->implode('|');
        $this->assertStringNotContainsString('Cutting', $labels);
        $this->assertStringContainsString('Sewing', $labels);
    }

    public function test_supervisor_progres_wo_membuang_kolom_tahap_lain_dan_hilir(): void
    {
        $r = $this->lap('progres-wo', [], 'spv_sewing')->assertOk();
        $kunci = collect($r->json('data.kolom'))->pluck('key')->all();

        $this->assertContains('sewing', $kunci);
        $this->assertContains('progres_sewing', $kunci);
        foreach (['cutting', 'finishing', 'lolos_qc', 'terkirim', 'progres_cutting', 'progres_kirim', 'kekurangan', 'kelebihan'] as $dilarang) {
            $this->assertNotContains($dilarang, $kunci, $dilarang);
        }

        // Datanya pun tidak bocor di JSON
        $row = $this->baris($r)->first();
        $this->assertArrayHasKey('sewing', $row);
        $this->assertArrayNotHasKey('cutting', $row);
        $this->assertArrayNotHasKey('terkirim', $row);

        $labels = collect($r->json('data.ringkasan'))->pluck('label')->implode('|');
        $this->assertStringNotContainsString('Cutting', $labels);
        $this->assertStringNotContainsString('Kirim', $labels);
        $this->assertStringNotContainsString('Yield', $labels);
    }

    public function test_supervisor_reject_dan_repair_hanya_tahapnya(): void
    {
        $reject = $this->baris($this->lap('reject', [], 'spv_sewing')->assertOk());
        $this->assertSame(['Penjahitan'], $reject->pluck('kelompok')->all()); // tanpa Pemotongan dan Final QC

        $repair = $this->baris($this->lap('repair', [], 'spv_sewing')->assertOk());
        $this->assertCount(1, $repair); // tahap tujuan = sewing
    }

    public function test_qc_melihat_seluruh_reject_tanpa_pembatasan_tahap(): void
    {
        $baris = $this->baris($this->lap('reject', [], 'qc')->assertOk());

        $this->assertNotNull($baris->firstWhere('kelompok', 'Pemotongan'));
        $this->assertNotNull($baris->firstWhere('kelompok', 'Final QC'));
    }

    // ------------------------------------------------------------------
    // Validasi, paginasi, dan fungsi hitung
    // ------------------------------------------------------------------

    public function test_validasi_parameter_laporan(): void
    {
        $this->lap('order', ['tgl_dari' => '2026-11-10', 'tgl_sampai' => '2026-11-01'])
            ->assertStatus(422)->assertJsonValidationErrors('tgl_sampai');
        $this->lap('order', ['format' => 'docx'])->assertStatus(422)->assertJsonValidationErrors('format');
        $this->lap('order', ['per_page' => 9999])->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_paginasi_json_laporan(): void
    {
        $r = $this->lap('order', ['per_page' => 3, 'page' => 2])->assertOk();

        $this->assertCount(3, $r->json('data.baris'));
        $this->assertEquals(7, $r->json('meta.total'));
        $this->assertEquals(3, $r->json('meta.last_page'));
        $this->assertEquals(2, $r->json('meta.current_page'));

        $this->assertCount(1, $this->lap('order', ['per_page' => 3, 'page' => 3])->json('data.baris'));
    }

    public function test_batas_periode_memfilter_laporan(): void
    {
        $depan = today()->addMonths(2)->toDateString();
        $r = $this->lap('order', ['tgl_dari' => $depan, 'tgl_sampai' => $depan])->assertOk();

        $this->assertCount(0, $this->baris($r));
        $this->assertEquals(0, $this->nilai($r, 'Jumlah Order'));
    }

    public function test_fungsi_persen_dan_reject_rate_aman(): void
    {
        $s = app(ReportService::class);

        $this->assertSame(0.0, $s->persen(5, 0));
        $this->assertSame(0.0, $s->persen(5, null));
        $this->assertSame(100.0, $s->persen(150, 100)); // dibatasi 100
        $this->assertSame(150.0, $s->persen(150, 100, false));
        $this->assertSame(66.67, $s->persen(2, 3));
        $this->assertSame(0.0, $s->rejectRate(0, 0));
        $this->assertSame(10.0, $s->rejectRate(90, 10));
        $this->assertSame(100.0, $s->rejectRate(0, 5));
    }

    public function test_throttle_ekspor_laporan(): void
    {
        $user = $this->u['admin'];
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/laporan/order')->assertOk();
        }

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/laporan/order')->assertStatus(429);
    }
}
