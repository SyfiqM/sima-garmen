<?php

namespace Tests\Concerns;

use App\Models\Bahan;
use App\Models\Bom;
use App\Models\JenisCacat;
use App\Models\Order;
use App\Models\Packing;
use App\Models\Pelanggan;
use App\Models\Pengiriman;
use App\Models\Produk;
use App\Models\Satuan;
use App\Models\Supplier;
use App\Models\Tahap;
use App\Models\Ukuran;
use App\Models\User;
use App\Models\Warna;
use App\Models\WoBahan;
use App\Models\WoItem;
use App\Models\WorkOrder;
use App\Services\IntegrityService;
use App\Services\StockService;
use App\Support\Setting;
use Database\Seeders\PengaturanSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Fixture dan langkah alur lengkap (order sampai pengiriman) lewat API,
 * dipakai tes notifikasi, command, dan skenario UAT. Semua data dibuat konsisten
 * (stok lewat mutasi, counter lewat endpoint) sehingga sima:check-integrity harus bersih.
 */
trait SkenarioProduksi
{
    protected array $tahapIds = [];

    /** @var array<string, User> */
    protected array $u = [];

    protected $satuan;

    protected $kain;

    protected $produk;

    protected $pelanggan;

    protected $warna;

    protected $ukuran;

    protected $supplier;

    protected $jenisCacat;

    protected function siapkanSkenario(float $stokKain = 100, float $qtyPerPcs = 1.0): void
    {
        Setting::flush();
        $this->seed(PengaturanSeeder::class);

        foreach ([['cutting', 'Pemotongan', 1], ['sewing', 'Penjahitan', 2], ['finishing', 'Finishing', 3]] as [$kode, $nama, $urutan]) {
            $this->tahapIds[$kode] = Tahap::create(['kode' => $kode, 'nama' => $nama, 'urutan' => $urutan])->id;
        }

        $peran = [
            'admin' => ['admin', null], 'manajer' => ['manajer', null], 'manajer2' => ['manajer', null],
            'staf_order' => ['staf_order', null], 'gudang' => ['gudang', null], 'gudang2' => ['gudang', null],
            'spv_cutting' => ['supervisor', 'cutting'], 'spv_sewing' => ['supervisor', 'sewing'],
            'spv_finishing' => ['supervisor', 'finishing'], 'qc' => ['qc', null], 'pimpinan' => ['pimpinan', null],
        ];
        foreach ($peran as $kunci => [$role, $tahap]) {
            $this->u[$kunci] = User::factory()->create(['role' => $role, 'tahap_id' => $tahap ? $this->tahapIds[$tahap] : null]);
        }

        $this->satuan = Satuan::create(['kode' => 'MTR', 'nama' => 'Meter']);
        $this->supplier = Supplier::create(['kode' => 'SUP1', 'nama' => 'Toko Kain', 'alamat' => '-']);
        $this->kain = Bahan::create([
            'kode' => 'KN-01', 'nama' => 'Kain Katun', 'jenis' => 'kain', 'satuan_id' => $this->satuan->id,
            'stok' => 0, 'stok_minimum' => 10, 'supplier_id' => $this->supplier->id,
        ]);
        if ($stokKain > 0) {
            $this->tambahStok($stokKain);
        }

        $this->produk = Produk::create(['kode' => 'P01', 'nama' => 'Kaos Polos']);
        Bom::create([
            'produk_id' => $this->produk->id, 'bahan_id' => $this->kain->id, 'tahap_id' => $this->tahapIds['cutting'],
            'qty_per_pcs' => $qtyPerPcs, 'waste_persen' => 0,
        ]);

        $this->pelanggan = Pelanggan::create(['kode' => 'PLG-01', 'nama' => 'Pelanggan Tes', 'alamat' => 'Jl. Tujuan No. 1']);
        $this->warna = Warna::create(['nama' => 'Hitam']);
        $this->ukuran = Ukuran::create(['kode' => 'M', 'urutan' => 1]);
        $this->jenisCacat = JenisCacat::create(['kode' => 'JH-01', 'nama' => 'Jahitan putus', 'kategori' => 'jahitan']);
    }

    protected function api(string $peran)
    {
        return $this->actingAs($this->u[$peran], 'sanctum');
    }

    /** Stok masuk lewat mutasi (bukan edit langsung) agar konsisten dengan kartu stok. */
    protected function tambahStok(float $qty, ?Bahan $bahan = null): void
    {
        DB::transaction(fn () => app(StockService::class)->mutasi(
            ($bahan ?? $this->kain)->id, 'masuk', $qty, 'manual', null, 'Stok awal skenario', $this->u['admin']->id,
        ));
    }

    // ------------------------------------------------------------------ order

    protected function buatOrder(int $qty, string $prioritas = 'normal', int $hariDeadline = 14): Order
    {
        $this->api('staf_order')->postJson('/api/v1/order', [
            'pelanggan_id' => $this->pelanggan->id,
            'tgl_order' => now()->toDateString(),
            'deadline' => now()->addDays($hariDeadline)->toDateString(),
            'prioritas' => $prioritas,
            'items' => [[
                'produk_id' => $this->produk->id, 'warna_id' => $this->warna->id,
                'ukuran_id' => $this->ukuran->id, 'qty' => $qty,
            ]],
        ])->assertSuccessful();

        return Order::latest('id')->firstOrFail();
    }

    protected function ajukan(Order $order): void
    {
        $this->api('staf_order')->postJson("/api/v1/order/{$order->id}/ajukan")->assertOk();
    }

    protected function setujui(Order $order): Order
    {
        $this->api('manajer')->postJson("/api/v1/order/{$order->id}/setujui")->assertOk();

        return $order->fresh();
    }

    /** Buat, ajukan, dan setujui order dalam satu langkah. */
    protected function orderDisetujui(int $qty, string $prioritas = 'normal', int $hariDeadline = 14): Order
    {
        $order = $this->buatOrder($qty, $prioritas, $hariDeadline);
        $this->ajukan($order);

        return $this->setujui($order);
    }

    protected function woDari(Order $order): WorkOrder
    {
        return WorkOrder::where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    protected function itemDari(WorkOrder $wo): WoItem
    {
        return WoItem::where('wo_id', $wo->id)->firstOrFail();
    }

    // ---------------------------------------------------------------- produksi

    protected function keluarkanKain(WorkOrder $wo, float $qty)
    {
        $woBahan = WoBahan::where('wo_id', $wo->id)->where('tahap_id', $this->tahapIds['cutting'])->firstOrFail();

        return $this->api('gudang')->postJson('/api/v1/pengeluaran-bahan', [
            'wo_id' => $wo->id,
            'tahap_id' => $this->tahapIds['cutting'],
            'tipe' => 'keluar',
            'tanggal' => now()->toDateString(),
            'items' => [['wo_bahan_id' => $woBahan->id, 'qty' => $qty]],
        ]);
    }

    protected function inputProduksi(string $tahap, WorkOrder $wo, WoItem $item, int $hasil, int $reject = 0)
    {
        $baris = ['wo_item_id' => $item->id, 'qty_hasil' => $hasil, 'qty_reject' => $reject];
        if ($reject > 0) {
            $baris['jenis_cacat_id'] = $this->jenisCacat->id;
        }

        return $this->api("spv_{$tahap}")->postJson('/api/v1/produksi/log', [
            'wo_id' => $wo->id,
            'tahap_id' => $this->tahapIds[$tahap],
            'tanggal' => now()->toDateString(),
            'items' => [$baris],
        ]);
    }

    /** Cutting, sewing, dan finishing berturut-turut dengan qty yang sama (tanpa reject). */
    protected function produksiPenuh(WorkOrder $wo, WoItem $item, int $qty): void
    {
        foreach (['cutting', 'sewing', 'finishing'] as $tahap) {
            $this->inputProduksi($tahap, $wo, $item, $qty)->assertSuccessful();
        }
    }

    // -------------------------------------------------------------- QC, packing

    protected function inspeksi(WorkOrder $wo, WoItem $item, int $diperiksa, int $lolos, int $repair = 0, int $reject = 0)
    {
        $cacat = [];
        if ($repair > 0) {
            $cacat[] = ['jenis_cacat_id' => $this->jenisCacat->id, 'qty' => $repair, 'tindakan' => 'repair'];
        }
        if ($reject > 0) {
            $cacat[] = ['jenis_cacat_id' => $this->jenisCacat->id, 'qty' => $reject, 'tindakan' => 'reject'];
        }

        return $this->api('qc')->postJson('/api/v1/qc/inspeksi', [
            'wo_id' => $wo->id,
            'tanggal' => now()->toDateString(),
            'items' => [[
                'wo_item_id' => $item->id, 'qty_diperiksa' => $diperiksa, 'qty_lolos' => $lolos,
                'qty_repair' => $repair, 'qty_reject' => $reject, 'cacat' => $cacat,
            ]],
        ]);
    }

    protected function buatKoli(WorkOrder $wo, WoItem $item, int $qty)
    {
        return $this->api('spv_finishing')->postJson('/api/v1/packing', [
            'wo_id' => $wo->id,
            'tanggal' => now()->toDateString(),
            'items' => [['wo_item_id' => $item->id, 'qty' => $qty]],
        ]);
    }

    // --------------------------------------------------------------- pengiriman

    protected function buatSuratJalan(Order $order, array $koliIds)
    {
        return $this->api('gudang')->postJson('/api/v1/pengiriman', [
            'order_id' => $order->id, 'koli_ids' => $koliIds, 'ekspedisi' => 'JNE', 'nama_penerima' => 'Budi',
        ]);
    }

    protected function kirimSuratJalan(Pengiriman|int $sj)
    {
        $id = $sj instanceof Pengiriman ? $sj->id : $sj;

        return $this->api('gudang')->postJson("/api/v1/pengiriman/{$id}/kirim", []);
    }

    /** @return array<int, int> id koli (urut pembuatan) milik WO */
    protected function koliIds(WorkOrder $wo): array
    {
        return Packing::where('wo_id', $wo->id)->where('status', '!=', 'void')->orderBy('id')->pluck('id')->all();
    }

    // ---------------------------------------------------------------- pemeriksaan

    protected function assertIntegritasBersih(): void
    {
        $temuan = app(IntegrityService::class)->periksa();

        $this->assertSame([], $temuan, 'Temuan integritas: '.json_encode($temuan, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<int, string> judul notifikasi milik user */
    protected function judulNotifikasi(User $user): array
    {
        return $user->fresh()->notifications->pluck('data.judul')->all();
    }
}