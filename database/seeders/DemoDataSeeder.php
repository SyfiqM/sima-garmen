<?php

namespace Database\Seeders;

use App\Models\Bahan;
use App\Models\JenisCacat;
use App\Models\LineProduksi;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pelanggan;
use App\Models\Produk;
use App\Models\PurchaseOrder;
use App\Models\QcRepair;
use App\Models\Satuan;
use App\Models\Supplier;
use App\Models\Ukuran;
use App\Models\User;
use App\Models\Warna;
use App\Models\WoBahan;
use App\Models\WoItem;
use App\Models\WorkOrder;
use App\Services\BomService;
use App\Services\MaterialIssueService;
use App\Services\OrderService;
use App\Services\PackingService;
use App\Services\ProductionService;
use App\Services\PurchaseService;
use App\Services\QcService;
use App\Services\ShipmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * HANYA UNTUK PENGEMBANGAN/DEMO. Jangan dijalankan di server produksi.
 *
 * Prasyarat : php artisan migrate:fresh --seed        (seeder dasar: tahap, satuan, ukuran, jenis cacat, pengaturan)
 * Jalankan  : php artisan db:seed --class=DemoDataSeeder
 *
 * Seluruh transaksi dibuat lewat service yang sama dengan API (bukan insert langsung), sehingga stok,
 * reservasi, counter produksi, dan status order konsisten dan lolos sima:check-integrity.
 * Aman dijalankan ulang: bila data demo sudah ada, seeder berhenti tanpa mengubah apa pun.
 *
 * Enam order demo:
 *   1. draft            Kaos Oblong
 *   2. diajukan         Seragam Sekolah
 *   3. menunggu_bahan   Jaket Fleece (stok fleece dan resleting kurang, PO sedang berjalan)
 *   4. produksi         Kaos Polo (ada reject, repair masih open, WO belum selesai)
 *   5. siap_kirim       Celana Kerja (3 koli sudah dikemas)
 *   6. terkirim         Kemeja Kerja (2 surat jalan: pengiriman parsial, satu sudah diterima)
 */
class DemoDataSeeder extends Seeder
{
    /** @var array<string,User> */
    protected array $u = [];

    /** @var array<string,int> kode tahap => id */
    protected array $tahap = [];

    /** @var array<string,Bahan> */
    protected array $bahan = [];

    /** @var array<string,Produk> */
    protected array $produk = [];

    /** @var array<string,int> */
    protected array $warna = [];

    /** @var array<string,int> */
    protected array $ukuran = [];

    /** @var array<string,int> kode => id */
    protected array $line = [];

    /** @var array<string,int> kode jenis cacat => id */
    protected array $cacat = [];

    /** @var array<string,Supplier> */
    protected array $supplier = [];

    /** @var array<int,Pelanggan> */
    protected array $pelanggan = [];

    protected OrderService $orders;

    protected ProductionService $produksi;

    protected QcService $qc;

    protected PackingService $packing;

    protected ShipmentService $kirim;

    protected PurchaseService $beli;

    protected MaterialIssueService $keluar;

    public function run(): void
    {
        if (Pelanggan::where('kode', 'PLG-D01')->exists()) {
            $this->info('Data demo sudah ada. Tidak ada yang diubah. Untuk mengulang: php artisan migrate:fresh --seed lalu jalankan seeder ini lagi.');

            return;
        }

        $this->tahap = DB::table('tahap')->pluck('id', 'kode')->all();
        if (! isset($this->tahap['cutting'], $this->tahap['sewing'], $this->tahap['finishing'])) {
            $this->command?->error('Master tahap belum ada. Jalankan dulu: php artisan migrate:fresh --seed');

            return;
        }

        // Laravel menjalankan seeder dengan mass assignment TIDAK dijaga (unguarded). Service memakai
        // Model::create($data) dengan data dari API yang sudah divalidasi, jadi harus dijalankan dalam
        // kondisi dijaga seperti saat lewat API. Tanpa ini, kolom seperti `items` ikut di-insert.
        Model::reguard();

        $this->call(DemoUserSeeder::class);

        $this->orders = app(OrderService::class);
        $this->produksi = app(ProductionService::class);
        $this->qc = app(QcService::class);
        $this->packing = app(PackingService::class);
        $this->kirim = app(ShipmentService::class);
        $this->beli = app(PurchaseService::class);
        $this->keluar = app(MaterialIssueService::class);

        foreach (['manajer', 'order', 'gudang', 'spv_cutting', 'spv_sewing', 'spv_finishing', 'qc'] as $username) {
            $this->u[$username] = User::where('username', $username)->firstOrFail();
        }

        DB::transaction(function () {
            $this->masterData();
            $this->stokAwal();
            $this->pembelian();
            $this->bom();
            $this->orderDemo();
        });

        $this->info('Data demo selesai dibuat: 5 pelanggan, 5 supplier, 20 bahan, 8 produk, 3 PO, 6 order.');

        $kode = Artisan::call('sima:check-integrity', ['--tanpa-notifikasi' => true]);
        $this->info(trim(Artisan::output()));
        if ($kode !== 0) {
            $this->command?->warn('Ada temuan integritas pada data demo. Mohon dilaporkan.');
        }
    }

    protected function info(string $pesan): void
    {
        $this->command?->info($pesan);
    }

    protected function hari(int $offset = 0): string
    {
        return now()->addDays($offset)->toDateString();
    }

    // ------------------------------------------------------------------
    // Master data
    // ------------------------------------------------------------------

    protected function masterData(): void
    {
        $pelanggan = [
            ['PLG-D01', 'PT Mitra Busana Nusantara', 'Rina Wulandari', '021-5550101', 'Jakarta'],
            ['PLG-D02', 'CV Seragam Cemerlang', 'Agus Pranoto', '022-5550102', 'Bandung'],
            ['PLG-D03', 'Toko Outdoor Gunung Jaya', 'Dedi Kurniawan', '0274-5550103', 'Yogyakarta'],
            ['PLG-D04', 'Koperasi Karya Mandiri', 'Siti Aminah', '031-5550104', 'Surabaya'],
            ['PLG-D05', 'PT Prima Teknik Abadi', 'Hendra Saputra', '024-5550105', 'Semarang'],
        ];
        foreach ($pelanggan as $i => [$kode, $nama, $kontak, $telp, $kota]) {
            $this->pelanggan[$i + 1] = Pelanggan::create([
                'kode' => $kode, 'nama' => $nama, 'kontak_person' => $kontak, 'telepon' => $telp,
                'email' => strtolower($kode).'@contoh.test', 'alamat' => 'Jl. Contoh No. '.($i + 1).', '.$kota,
                'kota' => $kota, 'is_active' => true,
            ]);
        }

        $supplier = [
            ['SUP-D01', 'UD Tekstil Sejahtera', 'Bandung'],
            ['SUP-D02', 'CV Benang Mas', 'Solo'],
            ['SUP-D03', 'PT Kain Prima', 'Majalaya'],
            ['SUP-D04', 'Toko Aksesoris Garmen Jaya', 'Jakarta'],
            ['SUP-D05', 'CV Kemasan Praktis', 'Tangerang'],
        ];
        foreach ($supplier as $i => [$kode, $nama, $kota]) {
            $this->supplier[$kode] = Supplier::create([
                'kode' => $kode, 'nama' => $nama, 'kontak_person' => 'Sales '.($i + 1), 'telepon' => '08123000'.($i + 1).'00',
                'email' => strtolower($kode).'@contoh.test', 'alamat' => 'Jl. Pemasok No. '.($i + 1).', '.$kota, 'is_active' => true,
            ]);
        }

        foreach ([
            'Hitam' => '#111827', 'Putih' => '#FFFFFF', 'Navy' => '#1E3A8A', 'Merah' => '#DC2626',
            'Abu-abu' => '#6B7280', 'Hijau Army' => '#4B5320', 'Biru Muda' => '#60A5FA', 'Kuning' => '#FACC15',
        ] as $nama => $hex) {
            $this->warna[$nama] = Warna::firstOrCreate(['nama' => $nama], ['kode_hex' => $hex, 'is_active' => true])->id;
        }
        foreach (Ukuran::pluck('id', 'kode') as $kode => $id) {
            $this->ukuran[$kode] = $id;
        }

        foreach ([
            ['LN-CUT-A', 'Meja Potong A', 'cutting', 400],
            ['LN-SEW-1', 'Line Jahit 1', 'sewing', 150],
            ['LN-SEW-2', 'Line Jahit 2', 'sewing', 150],
            ['LN-FIN-1', 'Line Finishing 1', 'finishing', 200],
        ] as [$kode, $nama, $tahap, $kapasitas]) {
            $this->line[$kode] = LineProduksi::create([
                'kode' => $kode, 'nama' => $nama, 'tahap_id' => $this->tahap[$tahap], 'kapasitas_per_hari' => $kapasitas, 'is_active' => true,
            ])->id;
        }

        foreach (JenisCacat::pluck('id', 'kode') as $kode => $id) {
            $this->cacat[$kode] = $id;
        }

        $satuan = Satuan::pluck('id', 'kode');

        // [kode, nama, jenis, satuan, minimum, supplier]
        $bahan = [
            ['KN-001', 'Cotton Combed 30s', 'kain', 'kg', 50, 'SUP-D01'],
            ['KN-002', 'Cotton Combed 24s', 'kain', 'kg', 50, 'SUP-D01'],
            ['KN-003', 'Fleece Tebal', 'kain', 'kg', 40, 'SUP-D03'],
            ['KN-004', 'Denim 12 oz', 'kain', 'm', 80, 'SUP-D03'],
            ['KN-005', 'Kain Drill Nagata', 'kain', 'm', 100, 'SUP-D03'],
            ['KN-006', 'Pique Lacoste', 'kain', 'kg', 50, 'SUP-D01'],
            ['BN-001', 'Benang Jahit Polyester Hitam', 'benang', 'cone', 10, 'SUP-D02'],
            ['BN-002', 'Benang Jahit Polyester Putih', 'benang', 'cone', 10, 'SUP-D02'],
            ['BN-003', 'Benang Obras', 'benang', 'cone', 10, 'SUP-D02'],
            ['BN-004', 'Benang Bordir', 'benang', 'cone', 5, 'SUP-D02'],
            ['AK-001', 'Kancing Baju 4 Lubang', 'aksesoris', 'pcs', 300, 'SUP-D04'],
            ['AK-002', 'Resleting Celana', 'aksesoris', 'pcs', 300, 'SUP-D04'],
            ['AK-003', 'Resleting Jaket 60 cm', 'aksesoris', 'pcs', 150, 'SUP-D04'],
            ['AK-004', 'Label Brand', 'aksesoris', 'pcs', 300, 'SUP-D04'],
            ['AK-005', 'Rib Kerah dan Manset', 'aksesoris', 'pcs', 300, 'SUP-D04'],
            ['AK-006', 'Karet Pinggang', 'aksesoris', 'm', 50, 'SUP-D04'],
            ['KM-001', 'Polybag Kemasan', 'kemasan', 'pcs', 500, 'SUP-D05'],
            ['KM-002', 'Hangtag', 'kemasan', 'pcs', 500, 'SUP-D05'],
            ['KM-003', 'Karton Master', 'kemasan', 'pcs', 200, 'SUP-D05'],
            ['KM-004', 'Stiker Barcode', 'kemasan', 'pcs', 500, 'SUP-D05'],
        ];
        foreach ($bahan as [$kode, $nama, $jenis, $sat, $min, $sup]) {
            $this->bahan[$kode] = Bahan::create([
                'kode' => $kode, 'nama' => $nama, 'jenis' => $jenis, 'satuan_id' => $satuan[$sat],
                'stok' => 0, 'stok_dipesan' => 0, 'stok_minimum' => $min,
                'supplier_id' => $this->supplier[$sup]->id, 'lokasi_rak' => 'R-'.substr($kode, 0, 2), 'is_active' => true,
            ]);
        }

        $produk = [
            ['PRD-D01', 'Kaos Oblong Cotton 30s', 'Kaos', 45000],
            ['PRD-D02', 'Kaos Polo Pique', 'Kaos', 85000],
            ['PRD-D03', 'Kemeja Kerja Lengan Panjang', 'Kemeja', 95000],
            ['PRD-D04', 'Celana Kerja Drill', 'Celana', 120000],
            ['PRD-D05', 'Jaket Fleece Zipper', 'Jaket', 150000],
            ['PRD-D06', 'Hoodie Fleece', 'Jaket', 135000],
            ['PRD-D07', 'Celana Jeans Denim', 'Celana', 165000],
            ['PRD-D08', 'Seragam Sekolah', 'Seragam', 75000],
        ];
        foreach ($produk as [$kode, $nama, $kategori, $harga]) {
            $this->produk[$kode] = Produk::create([
                'kode' => $kode, 'nama' => $nama, 'kategori' => $kategori, 'harga_jual_default' => $harga, 'is_active' => true,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Stok awal dan pembelian (lewat penerimaan, bukan edit stok langsung)
    // ------------------------------------------------------------------

    protected function stokAwal(): void
    {
        // [bahan, qty, harga]. Sengaja kurang: fleece (20), resleting jaket (100), karton master (150).
        $stok = [
            'SUP-D01' => [['KN-001', 300, 68000], ['KN-002', 300, 62000], ['KN-006', 150, 78000]],
            'SUP-D03' => [['KN-003', 20, 85000], ['KN-004', 250, 48000], ['KN-005', 400, 32000]],
            'SUP-D02' => [['BN-001', 60, 18000], ['BN-002', 40, 18000], ['BN-003', 80, 15000], ['BN-004', 30, 22000]],
            'SUP-D04' => [['AK-001', 3000, 150], ['AK-002', 1500, 2500], ['AK-003', 100, 4500], ['AK-004', 4000, 250], ['AK-005', 800, 3500], ['AK-006', 300, 2000]],
            'SUP-D05' => [['KM-001', 4000, 200], ['KM-002', 4000, 150], ['KM-003', 150, 4000], ['KM-004', 5000, 100]],
        ];

        foreach ($stok as $kodeSupplier => $baris) {
            $this->beli->terimaBahan([
                'supplier_id' => $this->supplier[$kodeSupplier]->id,
                'tgl_terima' => $this->hari(-20),
                'no_sj_supplier' => 'SJ-AWAL-'.substr($kodeSupplier, -2),
                'alasan_tanpa_po' => 'Stok awal data demo',
                'items' => array_map(fn ($b) => ['bahan_id' => $this->bahan[$b[0]]->id, 'qty' => $b[1], 'harga_satuan' => $b[2]], $baris),
            ], $this->u['gudang']->id);
        }
    }

    protected function pembelian(): void
    {
        // PO 1: diterima penuh
        $po1 = $this->buatPo('SUP-D01', -12, -8, [['KN-001', 100, 68000], ['KN-002', 80, 62000]]);
        $this->terimaPo($po1, -8, 'SJ-SUP1-0001', [100, 80]);

        // PO 2: diterima sebagian
        $po2 = $this->buatPo('SUP-D02', -6, 2, [['BN-001', 20, 18000], ['BN-002', 20, 18000], ['BN-003', 30, 15000]]);
        $this->terimaPo($po2, -3, 'SJ-SUP2-0001', [20, 10, 0]);
    }

    /**
     * @param  array<int,array{0:string,1:float|int,2:float|int}>  $baris
     */
    protected function buatPo(string $kodeSupplier, int $offsetTgl, int $offsetEstimasi, array $baris): PurchaseOrder
    {
        $po = $this->beli->storePo([
            'supplier_id' => $this->supplier[$kodeSupplier]->id,
            'tgl_po' => $this->hari($offsetTgl),
            'tgl_estimasi_tiba' => $this->hari($offsetEstimasi),
            'catatan' => 'PO data demo',
            'items' => array_map(fn ($b) => ['bahan_id' => $this->bahan[$b[0]]->id, 'qty_pesan' => $b[1], 'harga_satuan' => $b[2]], $baris),
        ], $this->u['gudang']->id);

        $this->beli->pesanPo($po->id, $this->u['gudang']->id);

        return $po->fresh('items');
    }

    /**
     * @param  array<int,int|float>  $qty  jumlah diterima per baris PO (0 = tidak diterima)
     */
    protected function terimaPo(PurchaseOrder $po, int $offsetTgl, string $noSj, array $qty): void
    {
        $items = [];
        foreach ($po->items->values() as $i => $item) {
            if (($qty[$i] ?? 0) > 0) {
                $items[] = ['po_item_id' => $item->id, 'bahan_id' => $item->bahan_id, 'qty' => $qty[$i], 'harga_satuan' => $item->harga_satuan];
            }
        }

        $this->beli->terimaBahan([
            'po_id' => $po->id,
            'supplier_id' => $po->supplier_id,
            'tgl_terima' => $this->hari($offsetTgl),
            'no_sj_supplier' => $noSj,
            'items' => $items,
        ], $this->u['gudang']->id);
    }

    // ------------------------------------------------------------------
    // BOM (termasuk contoh per ukuran dan per warna)
    // ------------------------------------------------------------------

    protected function bom(): void
    {
        $service = app(BomService::class);

        // [bahan, tahap, qty per pcs, ukuran|null, warna|null]
        $daftar = [
            'PRD-D01' => [
                ['KN-001', 'cutting', 0.25, null, null], ['KN-001', 'cutting', 0.28, 'XL', null], ['KN-001', 'cutting', 0.31, 'XXL', null],
                ['BN-001', 'sewing', 0.01, null, 'Hitam'], ['BN-002', 'sewing', 0.01, null, 'Putih'], ['BN-003', 'sewing', 0.01, null, null],
                ['AK-004', 'finishing', 1, null, null], ['KM-001', 'finishing', 1, null, null], ['KM-002', 'finishing', 1, null, null], ['KM-003', 'finishing', 0.02, null, null],
            ],
            'PRD-D02' => [
                ['KN-006', 'cutting', 0.30, null, null], ['KN-006', 'cutting', 0.32, null, 'Navy'],
                ['BN-003', 'sewing', 0.012, null, null], ['AK-001', 'sewing', 3, null, null],
                ['AK-004', 'finishing', 1, null, null], ['KM-001', 'finishing', 1, null, null], ['KM-002', 'finishing', 1, null, null], ['KM-003', 'finishing', 0.02, null, null],
            ],
            'PRD-D03' => [
                ['KN-002', 'cutting', 0.35, null, null],
                ['BN-001', 'sewing', 0.015, null, null], ['AK-001', 'sewing', 7, null, null],
                ['AK-004', 'finishing', 1, null, null], ['KM-001', 'finishing', 1, null, null], ['KM-002', 'finishing', 1, null, null], ['KM-003', 'finishing', 0.02, null, null],
            ],
            'PRD-D04' => [
                ['KN-005', 'cutting', 1.2, null, null],
                ['BN-003', 'sewing', 0.02, null, null], ['AK-002', 'sewing', 1, null, null], ['AK-001', 'sewing', 1, null, null],
                ['AK-004', 'finishing', 1, null, null], ['KM-001', 'finishing', 1, null, null],
            ],
            'PRD-D05' => [
                ['KN-003', 'cutting', 0.60, null, null],
                ['BN-003', 'sewing', 0.02, null, null], ['AK-003', 'sewing', 1, null, null],
                ['AK-004', 'finishing', 1, null, null], ['KM-001', 'finishing', 1, null, null],
            ],
            'PRD-D06' => [
                ['KN-003', 'cutting', 0.55, null, null],
                ['BN-003', 'sewing', 0.02, null, null], ['AK-005', 'sewing', 1, null, null],
                ['AK-004', 'finishing', 1, null, null], ['KM-001', 'finishing', 1, null, null],
            ],
            'PRD-D07' => [
                ['KN-004', 'cutting', 1.4, null, null],
                ['BN-001', 'sewing', 0.02, null, null], ['AK-002', 'sewing', 1, null, null], ['AK-001', 'sewing', 1, null, null],
                ['AK-004', 'finishing', 1, null, null], ['KM-001', 'finishing', 1, null, null],
            ],
            'PRD-D08' => [
                ['KN-002', 'cutting', 0.40, null, null],
                ['BN-002', 'sewing', 0.015, null, null], ['AK-001', 'sewing', 6, null, null],
                ['AK-004', 'finishing', 1, null, null], ['KM-001', 'finishing', 1, null, null], ['KM-003', 'finishing', 0.02, null, null],
            ],
        ];

        foreach ($daftar as $kodeProduk => $baris) {
            foreach ($baris as [$kodeBahan, $kodeTahap, $qty, $ukuran, $warna]) {
                $service->store($this->produk[$kodeProduk]->id, [
                    'bahan_id' => $this->bahan[$kodeBahan]->id,
                    'tahap_id' => $this->tahap[$kodeTahap],
                    'ukuran_id' => $ukuran ? $this->ukuran[$ukuran] : null,
                    'warna_id' => $warna ? $this->warna[$warna] : null,
                    'qty_per_pcs' => $qty,
                    'waste_persen' => 5,
                ], $this->u['manajer']->id);
            }
        }
    }

    // ------------------------------------------------------------------
    // Enam order demo
    // ------------------------------------------------------------------

    /**
     * @param  array<int,array{0:string,1:string,2:string,3:int}>  $items  [kode produk, warna, ukuran, qty]
     */
    protected function buatOrder(int $pelanggan, array $items, string $prioritas, int $tglOrder, int $deadline, string $catatan): Order
    {
        $data = [
            'pelanggan_id' => $this->pelanggan[$pelanggan]->id,
            'no_po_pelanggan' => 'PO-PLG-'.random_int(1000, 9999),
            'tgl_order' => $this->hari($tglOrder),
            'deadline' => $this->hari($deadline),
            'prioritas' => $prioritas,
            'catatan' => $catatan,
            'items' => array_map(fn ($i) => [
                'produk_id' => $this->produk[$i[0]]->id,
                'warna_id' => $this->warna[$i[1]],
                'ukuran_id' => $this->ukuran[$i[2]],
                'qty' => $i[3],
                'harga_satuan' => $this->produk[$i[0]]->harga_jual_default,
            ], $items),
        ];

        return $this->orders->store($data, $this->u['order']->id);
    }

    protected function ajukanDanSetujui(Order $order, bool $setujui = true): WorkOrder|null
    {
        $this->orders->ubahStatus($order->id, 'ajukan', [], $this->u['order']->id);
        if (! $setujui) {
            return null;
        }
        $this->orders->ubahStatus($order->id, 'setujui', [], $this->u['manajer']->id);

        return WorkOrder::where('order_id', $order->id)->where('status', 'aktif')->firstOrFail();
    }

    protected function orderDemo(): void
    {
        // 1. Draft
        $this->buatOrder(1, [['PRD-D01', 'Hitam', 'M', 50], ['PRD-D01', 'Hitam', 'L', 50]], 'normal', -1, 20, 'Kaos oblong, sablon menyusul.');

        // 2. Diajukan
        $o2 = $this->buatOrder(2, [['PRD-D08', 'Putih', 'M', 100], ['PRD-D08', 'Putih', 'L', 100], ['PRD-D08', 'Putih', 'XL', 50]], 'normal', -2, 14, 'Seragam tahun ajaran baru.');
        $this->ajukanDanSetujui($o2, false);

        // 3. Menunggu bahan (fleece dan resleting jaket kurang, PO fleece sudah dipesan)
        $o3 = $this->buatOrder(3, [['PRD-D05', 'Hitam', 'L', 80], ['PRD-D05', 'Hitam', 'XL', 70]], 'tinggi', -4, 18, 'Jaket outdoor, bahan fleece sedang dipesan.');
        $this->ajukanDanSetujui($o3);
        // PO 3: baru dipesan (belum diterima), untuk order yang menunggu bahan
        $this->buatPo('SUP-D03', -1, 5, [['KN-003', 50, 85000]]);

        // 4. Produksi berjalan dengan reject dan repair
        $this->orderProduksi();

        // 5. Siap kirim
        $this->orderSiapKirim();

        // 6. Terkirim dengan pengiriman parsial
        $this->orderTerkirim();
    }

    /** @return array<string,WoItem> */
    protected function itemPerUkuran(WorkOrder $wo): array
    {
        $hasil = [];
        foreach (WoItem::where('wo_id', $wo->id)->orderBy('id')->get() as $wi) {
            $oi = OrderItem::find($wi->order_item_id);
            $hasil[array_search($oi->ukuran_id, $this->ukuran)] = $wi;
        }

        return $hasil;
    }

    /** Pengeluaran bahan sesuai reservasi untuk tahap tertentu. */
    protected function keluarkanBahan(WorkOrder $wo, array $kodeTahap, int $offsetTgl = 0): void
    {
        foreach ($kodeTahap as $kode) {
            $rows = WoBahan::where('wo_id', $wo->id)->where('tahap_id', $this->tahap[$kode])->get();
            if ($rows->isEmpty()) {
                continue;
            }

            $this->keluar->store([
                'wo_id' => $wo->id,
                'tahap_id' => $this->tahap[$kode],
                'tipe' => 'keluar',
                'tanggal' => $this->hari($offsetTgl),
                'catatan' => 'Pengeluaran bahan data demo',
                'items' => $rows->map(fn ($r) => ['wo_bahan_id' => $r->id, 'qty' => (float) $r->qty_reservasi])->all(),
            ], $this->u['gudang']->id);
        }
    }

    /**
     * @param  array<string,array{0:int,1:int}>  $qtyPerUkuran  ukuran => [hasil, reject]
     */
    protected function catatTahap(WorkOrder $wo, string $kodeTahap, array $qtyPerUkuran, int $offsetTgl, string $spv, string $line): void
    {
        $item = $this->itemPerUkuran($wo);
        $cacat = ['cutting' => 'PTM', 'sewing' => 'JLP', 'finishing' => 'BNJ'][$kodeTahap];

        $baris = [];
        foreach ($qtyPerUkuran as $ukuran => [$hasil, $reject]) {
            $row = ['wo_item_id' => $item[$ukuran]->id, 'qty_hasil' => $hasil, 'qty_reject' => $reject, 'line_id' => $this->line[$line]];
            if ($reject > 0) {
                $row['jenis_cacat_id'] = $this->cacat[$cacat];
            }
            $baris[] = $row;
        }

        $this->produksi->catatProduksi($wo, $this->tahap[$kodeTahap], $baris, $this->hari($offsetTgl), $this->u[$spv]->id);
    }

    protected function orderProduksi(): void
    {
        $order = $this->buatOrder(4, [['PRD-D02', 'Navy', 'M', 100], ['PRD-D02', 'Navy', 'L', 80]], 'tinggi', -9, 6, 'Polo seragam koperasi, ada prioritas.');
        $wo = $this->ajukanDanSetujui($order);

        // Bahan cutting dan sewing sudah dikeluarkan, bahan finishing belum
        $this->keluarkanBahan($wo, ['cutting', 'sewing'], -2);

        $this->catatTahap($wo, 'cutting', ['M' => [100, 2], 'L' => [80, 0]], -2, 'spv_cutting', 'LN-CUT-A');
        $this->catatTahap($wo, 'sewing', ['M' => [80, 1], 'L' => [50, 0]], -1, 'spv_sewing', 'LN-SEW-1');
        $this->catatTahap($wo, 'finishing', ['M' => [60, 0], 'L' => [30, 0]], -1, 'spv_finishing', 'LN-FIN-1');

        // QC 30 pcs ukuran M: 22 lolos, 5 repair, 3 reject
        $item = $this->itemPerUkuran($wo);
        $this->qc->catatInspeksi($wo, [[
            'wo_item_id' => $item['M']->id, 'qty_diperiksa' => 30, 'qty_lolos' => 22, 'qty_repair' => 5, 'qty_reject' => 3,
            'cacat' => [
                ['jenis_cacat_id' => $this->cacat['JLP'], 'qty' => 5, 'tindakan' => 'repair'],
                ['jenis_cacat_id' => $this->cacat['BLG'], 'qty' => 3, 'tindakan' => 'reject'],
            ],
        ]], $this->hari(0), $this->u['qc']->id, 'Inspeksi pertama ukuran M');

        // Repair 5 pcs: 2 pcs sudah selesai, 3 pcs masih open
        $repair = QcRepair::where('wo_item_id', $item['M']->id)->firstOrFail();
        $this->qc->selesaikanRepair($repair->id, 2, $this->u['spv_sewing']->id, 'Sebagian sudah diperbaiki');
    }

    protected function orderSiapKirim(): void
    {
        $order = $this->buatOrder(1, [['PRD-D04', 'Abu-abu', 'L', 150]], 'normal', -12, 3, 'Celana kerja, siap dikirim setelah dikemas.');
        $wo = $this->ajukanDanSetujui($order);
        $this->keluarkanBahan($wo, ['cutting', 'sewing', 'finishing'], -2);
        $this->produksiPenuh($wo, ['L' => 150], -2, -1, 0);

        $this->kemas($wo, 'L', [60, 60, 30], 0);
    }

    protected function orderTerkirim(): void
    {
        $order = $this->buatOrder(5, [['PRD-D03', 'Hijau Army', 'M', 100], ['PRD-D03', 'Hijau Army', 'L', 100]], 'normal', -14, 2, 'Kemeja kerja, dikirim dua tahap.');
        $wo = $this->ajukanDanSetujui($order);
        $this->keluarkanBahan($wo, ['cutting', 'sewing', 'finishing'], -2);
        $this->produksiPenuh($wo, ['M' => 100, 'L' => 100], -2, -1, -1);

        // Tiga koli: M 100, L 60, L 40
        $item = $this->itemPerUkuran($wo);
        $koli = [];
        foreach ([['M', 100], ['L', 60], ['L', 40]] as [$ukuran, $qty]) {
            $koli[] = $this->packing->buatKoli($wo, [['wo_item_id' => $item[$ukuran]->id, 'qty' => $qty]], $this->hari(-1), 14.5, $this->u['spv_finishing']->id)->id;
        }

        // Surat jalan 1: koli 1 dan 2, dikirim kemarin dan sudah diterima
        $sj1 = $this->kirim->buat([
            'order_id' => $order->id, 'koli_ids' => [$koli[0], $koli[1]], 'tgl_kirim' => $this->hari(-1),
            'ekspedisi' => 'JNE Trucking', 'no_resi' => 'JNE-DEMO-0001', 'nama_penerima' => 'Gudang PT Prima Teknik',
        ], $this->u['gudang']->id);
        $this->kirim->kirim($sj1->id, $this->hari(-1), $this->u['gudang']->id);
        $this->kirim->diterima($sj1->id, $this->hari(0), null, 'Diterima lengkap, kondisi baik', $this->u['order']->id);

        // Surat jalan 2: koli 3, dikirim hari ini (masih dalam perjalanan)
        $sj2 = $this->kirim->buat([
            'order_id' => $order->id, 'koli_ids' => [$koli[2]], 'tgl_kirim' => $this->hari(0),
            'ekspedisi' => 'JNE Trucking', 'no_resi' => 'JNE-DEMO-0002', 'nama_penerima' => 'Gudang PT Prima Teknik',
        ], $this->u['gudang']->id);
        $this->kirim->kirim($sj2->id, $this->hari(0), $this->u['gudang']->id);
    }

    /**
     * Produksi tanpa reject sampai QC semua lolos.
     *
     * @param  array<string,int>  $qty  ukuran => qty order
     */
    protected function produksiPenuh(WorkOrder $wo, array $qty, int $tglCutting, int $tglSewing, int $tglFinishing): void
    {
        $cutting = $sewing = $finishing = [];
        foreach ($qty as $ukuran => $q) {
            $cutting[$ukuran] = [$q, 0];
            $sewing[$ukuran] = [$q, 0];
            $finishing[$ukuran] = [$q, 0];
        }

        $this->catatTahap($wo, 'cutting', $cutting, $tglCutting, 'spv_cutting', 'LN-CUT-A');
        $this->catatTahap($wo, 'sewing', $sewing, $tglSewing, 'spv_sewing', 'LN-SEW-1');
        $this->catatTahap($wo, 'finishing', $finishing, $tglFinishing, 'spv_finishing', 'LN-FIN-1');

        $item = $this->itemPerUkuran($wo);
        $baris = [];
        foreach ($qty as $ukuran => $q) {
            $baris[] = ['wo_item_id' => $item[$ukuran]->id, 'qty_diperiksa' => $q, 'qty_lolos' => $q, 'qty_repair' => 0, 'qty_reject' => 0];
        }
        $this->qc->catatInspeksi($wo, $baris, $this->hari($tglFinishing), $this->u['qc']->id, 'Semua lolos');
    }

    /** @param  array<int,int>  $qtyPerKoli */
    protected function kemas(WorkOrder $wo, string $ukuran, array $qtyPerKoli, int $offsetTgl): void
    {
        $item = $this->itemPerUkuran($wo);
        foreach ($qtyPerKoli as $qty) {
            $this->packing->buatKoli($wo, [['wo_item_id' => $item[$ukuran]->id, 'qty' => $qty]], $this->hari($offsetTgl), 12.0, $this->u['spv_finishing']->id);
        }
    }
}
