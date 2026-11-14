<?php

namespace Tests\Feature;

use App\Helpers\NumberingService;
use App\Models\Order;
use App\Models\Packing;
use App\Models\Pengiriman;
use App\Models\PengirimanKoli;
use App\Models\WoItem;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SkenarioProduksi;
use Tests\TestCase;

class Batch9CommandTest extends TestCase
{
    use RefreshDatabase, SkenarioProduksi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkenario(stokKain: 100);
    }

    private function jumlahNotifikasi($user, string $judul): int
    {
        return collect($this->judulNotifikasi($user))->filter(fn ($j) => $j === $judul)->count();
    }

    /** Alur penuh satu order 10 pcs sampai terkirim, seluruhnya lewat API. */
    private function alurPenuhTerkirim(): array
    {
        $order = $this->orderDisetujui(10);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        $this->keluarkanKain($wo, 10)->assertSuccessful();
        $this->produksiPenuh($wo, $item, 10);
        $this->inspeksi($wo, $item, 10, 10)->assertSuccessful();
        $this->buatKoli($wo, $item, 10)->assertSuccessful();
        $sjId = $this->buatSuratJalan($order, $this->koliIds($wo))->assertCreated()->json('data.id');
        $this->kirimSuratJalan($sjId)->assertOk();

        return [$order->fresh(), $wo->fresh(), $item->fresh(), $sjId];
    }

    // ------------------------------------------------------------------ alert stok

    public function test_alert_stok_dikirim_sekali_per_bahan_per_hari(): void
    {
        // tersedia = 100 - 85 = 15 <= minimum 20
        $this->kain->update(['stok_minimum' => 20, 'stok_dipesan' => 85]);
        // Stok tersedia 0 dengan minimum 0 tetap memenuhi aturan tersedia <= minimum.
        \App\Models\Bahan::create([
            'kode' => 'BN-02', 'nama' => 'Benang', 'jenis' => 'benang', 'satuan_id' => $this->satuan->id,
            'stok' => 0, 'stok_minimum' => 0,
        ]);

        Artisan::call('sima:stock-alerts');
        $this->assertStringContainsString('2 bahan', Artisan::output());

        foreach (['gudang', 'gudang2', 'manajer', 'manajer2'] as $peran) {
            $this->assertEquals(2, $this->jumlahNotifikasi($this->u[$peran], 'Stok mencapai atau di bawah minimum'), $peran);
        }
        $this->assertEquals(0, $this->jumlahNotifikasi($this->u['staf_order'], 'Stok mencapai atau di bawah minimum'));

        Artisan::call('sima:stock-alerts');
        $this->assertStringContainsString('0 bahan', Artisan::output());
        $this->assertEquals(2, $this->jumlahNotifikasi($this->u['gudang'], 'Stok mencapai atau di bawah minimum'));
    }

    // -------------------------------------------------------------- alert deadline

    public function test_alert_deadline_untuk_order_mendekati_dan_terlambat_maksimal_sekali_sehari(): void
    {
        $buat = fn (string $no, string $status, int $selisihHari) => Order::create([
            'no_order' => $no, 'pelanggan_id' => $this->pelanggan->id, 'tgl_order' => now()->subDays(10),
            'deadline' => now()->addDays($selisihHari), 'status' => $status, 'dibuat_oleh' => $this->u['staf_order']->id,
        ]);

        $buat('ORD-A', 'produksi', 2);     // mendekati (H-2)
        $buat('ORD-B', 'siap_kirim', -3);  // terlambat 3 hari
        $buat('ORD-C', 'terkirim', -5);    // sudah terkirim: diabaikan
        $buat('ORD-D', 'produksi', 10);    // masih jauh: diabaikan
        $buat('ORD-E', 'draft', -1);       // belum disetujui: diabaikan

        Artisan::call('sima:deadline-alerts');
        $this->assertStringContainsString('2 order', Artisan::output());

        foreach (['manajer', 'manajer2', 'staf_order'] as $peran) {
            $judul = $this->judulNotifikasi($this->u[$peran]);
            $this->assertEquals(1, collect($judul)->filter(fn ($j) => $j === 'Deadline order mendekati')->count(), $peran);
            $this->assertEquals(1, collect($judul)->filter(fn ($j) => $j === 'Order terlambat')->count(), $peran);
        }
        $this->assertEmpty($this->judulNotifikasi($this->u['gudang']));

        $pesan = $this->u['manajer']->fresh()->notifications->pluck('data.pesan')->implode(' | ');
        $this->assertStringContainsString('ORD-A', $pesan);
        $this->assertStringContainsString('terlambat 3 hari', $pesan);
        $this->assertStringNotContainsString('ORD-C', $pesan);
        $this->assertStringNotContainsString('ORD-D', $pesan);
        $this->assertStringNotContainsString('ORD-E', $pesan);

        Artisan::call('sima:deadline-alerts');
        $this->assertStringContainsString('0 order', Artisan::output());
        $this->assertCount(2, $this->u['manajer']->fresh()->notifications);
    }

    // ------------------------------------------------------------------ integritas

    public function test_integritas_bersih_setelah_alur_penuh_order_sampai_terkirim(): void
    {
        [$order, $wo, $item] = $this->alurPenuhTerkirim();

        $this->assertEquals('terkirim', $order->status->value);
        $this->assertEquals(10, $item->qty_terkirim);
        $this->assertIntegritasBersih();
        $this->artisan('sima:check-integrity')->assertExitCode(0);
    }

    public function test_integritas_mendeteksi_selisih_stok_dan_mengirim_notifikasi_sekali(): void
    {
        $this->kain->update(['stok' => 999]);

        $this->artisan('sima:check-integrity')->assertExitCode(1);

        $this->assertContains('Selisih integritas data', $this->judulNotifikasi($this->u['admin']));
        $this->assertContains('Selisih integritas data', $this->judulNotifikasi($this->u['manajer']));

        $this->artisan('sima:check-integrity')->assertExitCode(1);
        $this->assertEquals(1, $this->jumlahNotifikasi($this->u['admin'], 'Selisih integritas data'));

        $this->artisan('sima:check-integrity', ['--tanpa-notifikasi' => true])->assertExitCode(1);
    }

    public function test_integritas_mendeteksi_selisih_reservasi(): void
    {
        $this->kain->update(['stok_dipesan' => 7]);

        $temuan = collect(app(\App\Services\IntegrityService::class)->periksa());

        $this->assertTrue($temuan->contains(fn ($t) => $t['kategori'] === 'reservasi'), 'Selisih reservasi harus terdeteksi.');
    }

    public function test_hitung_ulang_counter_memperbaiki_selisih_dan_dry_run_tidak_mengubah(): void
    {
        [, $wo, $item] = $this->alurPenuhTerkirim();

        WoItem::whereKey($item->id)->update(['qty_cutting' => 5, 'qty_qc_lolos' => 7]);
        $temuan = collect(app(\App\Services\IntegrityService::class)->periksa());
        $this->assertTrue($temuan->contains(fn ($t) => $t['kategori'] === 'counter'));

        $this->artisan('sima:recalc-counters', ['--wo' => $wo->id, '--dry-run' => true])->assertExitCode(0);
        $this->assertDatabaseHas('wo_items', ['id' => $item->id, 'qty_cutting' => 5, 'qty_qc_lolos' => 7]);

        $this->artisan('sima:recalc-counters', ['--wo' => $wo->id])->assertExitCode(0);
        $this->assertDatabaseHas('wo_items', ['id' => $item->id, 'qty_cutting' => 10, 'qty_qc_lolos' => 10]);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'sistem.recalc_counters']);
        $this->assertIntegritasBersih();
    }

    public function test_integritas_mendeteksi_wo_aktif_ganda_dan_koli_ganda(): void
    {
        [$order, $wo] = $this->alurPenuhTerkirim();

        WorkOrder::create([
            'no_wo' => 'WO-DUP-1', 'order_id' => $order->id, 'status' => 'aktif',
            'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $this->u['admin']->id,
        ]);
        WorkOrder::create([
            'no_wo' => 'WO-DUP-2', 'order_id' => $order->id, 'status' => 'aktif',
            'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $this->u['admin']->id,
        ]);

        $koli = Packing::where('wo_id', $wo->id)->firstOrFail();
        $sjLain = Pengiriman::create([
            'no_sj' => 'SJ-DUP', 'order_id' => $order->id, 'status' => 'disiapkan', 'dibuat_oleh' => $this->u['admin']->id,
        ]);
        PengirimanKoli::create(['pengiriman_id' => $sjLain->id, 'packing_id' => $koli->id]);

        $kategori = collect(app(\App\Services\IntegrityService::class)->periksa())->pluck('kategori')->unique()->all();

        $this->assertContains('wo', $kategori);
        $this->assertContains('koli', $kategori);
    }

    // -------------------------------------------------------- reservasi dan jadwal

    public function test_sisa_reservasi_dilepas_saat_wo_selesai_otomatis(): void
    {
        $order = $this->orderDisetujui(10);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);

        // Kain keluar 8 m dari reservasi 10 m (lebih hemat dari rencana)
        $this->keluarkanKain($wo, 8)->assertSuccessful();
        $this->assertEquals(2, (float) $this->kain->fresh()->stok_dipesan);

        $this->produksiPenuh($wo, $item, 10);
        $this->inspeksi($wo, $item, 10, 10)->assertSuccessful();
        $this->buatKoli($wo, $item, 10)->assertSuccessful();

        $this->assertEquals('selesai', $wo->fresh()->status->value);
        $this->assertDatabaseHas('wo_bahan', ['wo_id' => $wo->id, 'qty_reservasi' => 8, 'qty_keluar' => 8]);
        $this->assertEquals(0, (float) $this->kain->fresh()->stok_dipesan);
        $this->assertIntegritasBersih();
    }

    public function test_command_terjadwal_terdaftar(): void
    {
        Artisan::call('schedule:list');
        $keluaran = Artisan::output();

        foreach (['sima:check-integrity', 'sima:deadline-alerts', 'sima:stock-alerts'] as $perintah) {
            $this->assertStringContainsString($perintah, $keluaran);
        }
    }

    // ------------------------------------------------------------------ penomoran

    public function test_penomoran_berurutan_tanpa_nomor_kembar(): void
    {
        $nomor = [];
        for ($i = 0; $i < 25; $i++) {
            $nomor[] = NumberingService::next('ORD');
        }

        $this->assertCount(25, array_unique($nomor));
        $this->assertStringEndsWith('0025', end($nomor));
        $this->assertEquals(1, DB::table('penomoran')->where('kode', 'ORD')->count());
    }
}