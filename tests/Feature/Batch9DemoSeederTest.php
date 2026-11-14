<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\JenisCacatSeeder;
use Database\Seeders\PengaturanSeeder;
use Database\Seeders\SatuanSeeder;
use Database\Seeders\TahapSeeder;
use Database\Seeders\UkuranSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Batch 9 langkah 3: DemoDataSeeder membuat data lewat service sehingga harus konsisten
 * (lolos sima:check-integrity) dan bisa dipakai dashboard serta laporan.
 */
class Batch9DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([TahapSeeder::class, SatuanSeeder::class, UkuranSeeder::class, JenisCacatSeeder::class, PengaturanSeeder::class]);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_jumlah_data_demo_sesuai_langkah_kerja(): void
    {
        $this->assertSame(5, DB::table('pelanggan')->count());
        $this->assertSame(5, DB::table('supplier')->count());
        $this->assertGreaterThanOrEqual(8, DB::table('warna')->count());
        $this->assertSame(20, DB::table('bahan')->count());
        $this->assertSame(8, DB::table('produk')->count());
        $this->assertSame(3, DB::table('purchase_orders')->count());
        $this->assertSame(6, DB::table('orders')->count());

        // Setiap produk punya BOM cutting, dan ada contoh BOM per ukuran dan per warna
        foreach (DB::table('produk')->pluck('id') as $produkId) {
            $this->assertTrue(
                DB::table('bom')->where('produk_id', $produkId)->where('tahap_id', DB::table('tahap')->where('kode', 'cutting')->value('id'))->exists(),
                "Produk {$produkId} belum punya BOM cutting."
            );
        }
        $this->assertTrue(DB::table('bom')->whereNotNull('ukuran_id')->exists());
        $this->assertTrue(DB::table('bom')->whereNotNull('warna_id')->exists());
    }

    public function test_enam_order_memiliki_status_berbeda(): void
    {
        $status = DB::table('orders')->pluck('status')->sort()->values()->all();

        $this->assertSame(['diajukan', 'draft', 'menunggu_bahan', 'produksi', 'siap_kirim', 'terkirim'], $status);
    }

    public function test_order_produksi_punya_reject_dan_repair_yang_masih_open(): void
    {
        $orderId = DB::table('orders')->where('status', 'produksi')->value('id');
        $woId = DB::table('work_orders')->where('order_id', $orderId)->value('id');

        $this->assertSame('aktif', DB::table('work_orders')->where('id', $woId)->value('status'));
        $this->assertGreaterThan(0, (int) DB::table('wo_items')->where('wo_id', $woId)->sum('qty_cutting_reject'));
        $this->assertGreaterThan(0, (int) DB::table('wo_items')->where('wo_id', $woId)->sum('qty_qc_reject'));
        $this->assertSame(3, (int) DB::table('wo_items')->where('wo_id', $woId)->sum('qty_repair_open'));
        $this->assertSame(2, (int) DB::table('wo_items')->where('wo_id', $woId)->sum('qty_repair_selesai'));
    }

    public function test_order_siap_kirim_dan_terkirim(): void
    {
        $siap = DB::table('orders')->where('status', 'siap_kirim')->value('id');
        $koli = DB::table('packing as p')->join('work_orders as w', 'w.id', '=', 'p.wo_id')->where('w.order_id', $siap);
        $this->assertSame(3, (clone $koli)->count());
        $this->assertSame(3, (clone $koli)->where('p.status', 'tersedia')->count());

        $terkirim = DB::table('orders')->where('status', 'terkirim')->value('id');
        $sj = DB::table('pengiriman')->where('order_id', $terkirim)->orderBy('id')->pluck('status')->all();
        $this->assertSame(['diterima', 'dikirim'], $sj);
    }

    public function test_status_po_dan_stok_di_bawah_minimum(): void
    {
        $this->assertSame(['diterima', 'diterima_sebagian', 'dipesan'], DB::table('purchase_orders')->orderBy('id')->pluck('status')->all());

        $bawah = DB::table('bahan')->where('stok_minimum', '>', 0)->whereRaw('(stok - stok_dipesan) <= stok_minimum')->count();
        $this->assertGreaterThanOrEqual(3, $bawah);

        $this->assertGreaterThan(0, DB::table('stok_mutasi')->count());
        $this->assertSame(0, DB::table('bahan')->where('stok', '<', 0)->count());
    }

    public function test_integritas_data_demo_bersih(): void
    {
        $this->artisan('sima:check-integrity', ['--tanpa-notifikasi' => true])->assertExitCode(0);
    }

    public function test_seeder_dijalankan_dua_kali_tidak_menggandakan_data(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(5, DB::table('pelanggan')->count());
        $this->assertSame(6, DB::table('orders')->count());
        $this->assertSame(3, DB::table('purchase_orders')->count());
    }

    public function test_dashboard_dan_laporan_berjalan_dengan_data_demo(): void
    {
        $manajer = User::where('username', 'manajer')->firstOrFail();
        $dari = today()->subDays(30)->toDateString();

        $d = $this->actingAs($manajer, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data');
        $status = collect($d['order_aktif_per_status'])->pluck('jumlah', 'status');
        $this->assertSame(1, $status['diajukan']);
        $this->assertSame(1, $status['menunggu_bahan']);
        $this->assertSame(1, $status['produksi']);
        $this->assertSame(1, $status['siap_kirim']);
        $this->assertGreaterThanOrEqual(3, $d['stok_di_bawah_minimum']['total']);

        $this->getJson('/api/v1/laporan/order?tgl_dari='.$dari)->assertOk()->assertJsonPath('meta.total', 6);

        $ot = $this->getJson('/api/v1/laporan/on-time-delivery?tgl_dari='.$dari)->assertOk();
        $this->assertSame(1, $ot->json('meta.total'));
        $this->assertTrue($ot->json('data.baris.0.tepat_waktu'));

        $this->getJson('/api/v1/laporan/repair?tgl_dari='.$dari)->assertOk()->assertJsonPath('meta.total', 1);

        foreach (['dashboard' => 'order', 'gudang' => 'gudang', 'spv_sewing' => 'supervisor', 'qc' => 'qc', 'pimpinan' => 'eksekutif'] as $username => $tipe) {
            $user = User::where('username', $username === 'dashboard' ? 'order' : $username)->firstOrFail();
            $json = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data.tipe');
            $this->assertSame($username === 'dashboard' ? 'staf_order' : $tipe, $json);
        }
    }
}
