<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\PurchaseOrder;
use App\Models\Order;
use App\Models\WorkOrder;
use App\Models\WoBahan;
use App\Models\Tahap;
use App\Models\Satuan;
use App\Models\StokOpname;
use App\Models\StokOpnameItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch4StockPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected $gudang;

    protected $manajer;

    protected $bahan;

    protected $supplier;

    protected $satuan;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup User & Role
        // Bypass mass-assignment agar role 'gudang' dan 'manajer' pasti tersimpan ke database
        $this->gudang = User::factory()->create();
        $this->gudang->role = 'gudang';
        $this->gudang->save();

        $this->manajer = User::factory()->create();
        $this->manajer->role = 'manajer';
        $this->manajer->save();

        // 2. Setup Master Pendukung & Bahan
        // Menambahkan satuan_id untuk mencegah error SQL 1364
        $this->satuan = Satuan::create(['kode' => 'ROLL', 'nama' => 'Roll']);

        $this->bahan = Bahan::create([
            'kode' => 'B02',
            'nama' => 'Benang',
            'jenis' => 'benang',
            'satuan_id' => $this->satuan->id, // <- satuan_id dimasukkan
            'stok' => 50,
            'stok_minimum' => 10,
            'stok_dipesan' => 10,
        ]);

        $this->supplier = Supplier::create(['kode' => 'SUP1', 'nama' => 'Toko Benang', 'alamat' => '-']);
    }

    public function test_penerimaan_bahan_tanpa_po_menambah_stok_dan_mencatat_mutasi()
    {
        // Gunakan guard 'sanctum' untuk melewati middleware otentikasi API
        $response = $this->actingAs($this->gudang, 'sanctum')->postJson('/api/v1/penerimaan', [
            'supplier_id' => $this->supplier->id,
            'tgl_terima' => now()->format('Y-m-d'),
            'alasan_tanpa_po' => 'Pembelian bahan darurat',
            'items' => [
                ['bahan_id' => $this->bahan->id, 'qty' => 100, 'harga_satuan' => 5000],
            ],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('bahan', [
            'id' => $this->bahan->id,
            'stok' => 150,
            'harga_terakhir' => 5000,
        ]);

        $this->assertDatabaseHas('stok_mutasi', [
            'bahan_id' => $this->bahan->id,
            'tipe' => 'masuk',
            'qty' => 100,
            'stok_sebelum' => 50,
            'stok_sesudah' => 150,
        ]);
    }

    public function test_void_penerimaan_membuat_mutasi_pembalik_dan_menyimpan_mutasi_awal(): void
    {
        $receiptResponse = $this->actingAs($this->gudang, 'sanctum')->postJson('/api/v1/penerimaan', [
            'supplier_id' => $this->supplier->id,
            'tgl_terima' => now()->toDateString(),
            'alasan_tanpa_po' => 'Penerimaan uji tanpa PO',
            'items' => [['bahan_id' => $this->bahan->id, 'qty' => 10]],
        ]);
        $receiptResponse->assertCreated();
        $receiptId = $receiptResponse->json('data.id');

        $this->actingAs($this->gudang, 'sanctum')
            ->postJson("/api/v1/penerimaan/{$receiptId}/void", ['alasan' => 'Salah catat barang'])
            ->assertOk();

        $this->assertDatabaseHas('penerimaan_bahan', ['id' => $receiptId, 'status' => 'void']);
        $this->assertDatabaseHas('stok_mutasi', ['bahan_id' => $this->bahan->id, 'tipe' => 'masuk', 'qty' => 10]);
        $this->assertDatabaseHas('stok_mutasi', ['bahan_id' => $this->bahan->id, 'tipe' => 'koreksi', 'qty' => -10]);
        $this->assertDatabaseHas('bahan', ['id' => $this->bahan->id, 'stok' => 50]);
    }

    public function test_menolak_penerimaan_tanpa_po_jika_alasan_tidak_diisi(): void
    {
        $this->actingAs($this->gudang, 'sanctum')->postJson('/api/v1/penerimaan', [
            'supplier_id' => $this->supplier->id,
            'tgl_terima' => now()->toDateString(),
            'items' => [['bahan_id' => $this->bahan->id, 'qty' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['alasan_tanpa_po']);

        $this->assertDatabaseHas('bahan', ['id' => $this->bahan->id, 'stok' => 50]);
    }

    public function test_gudang_can_cancel_ordered_purchase_order_without_receipt(): void
    {
        $po = PurchaseOrder::create([
            'no_po' => 'PO-TEST-CANCEL',
            'supplier_id' => $this->supplier->id,
            'tgl_po' => now()->toDateString(),
            'status' => 'dipesan',
            'dibuat_oleh' => $this->gudang->id,
        ]);

        $this->actingAs($this->gudang, 'sanctum')
            ->postJson("/api/v1/po/{$po->id}/batalkan", ['alasan' => 'Supplier membatalkan pesanan'])
            ->assertOk();

        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'dibatalkan']);
    }

    public function test_gudang_can_create_purchase_order_from_material_shortage(): void
    {
        $this->bahan->update(['supplier_id' => $this->supplier->id]);
        $tahap = Tahap::create(['kode' => 'cutting', 'nama' => 'Pemotongan', 'urutan' => 1]);
        $order = Order::create([
            'no_order' => 'ORD-SHORT-01',
            'pelanggan_id' => \App\Models\Pelanggan::create(['kode' => 'PLG-SHORT', 'nama' => 'Pelanggan', 'alamat' => '-'])->id,
            'tgl_order' => now(),
            'deadline' => now()->addDays(7),
            'status' => 'menunggu_bahan',
            'dibuat_oleh' => $this->gudang->id,
        ]);
        $workOrder = WorkOrder::create([
            'no_wo' => 'WO-SHORT-01', 'order_id' => $order->id, 'status' => 'aktif',
            'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $this->gudang->id,
        ]);
        WoBahan::create([
            'wo_id' => $workOrder->id, 'bahan_id' => $this->bahan->id, 'tahap_id' => $tahap->id,
            'qty_rencana' => 80, 'qty_reservasi' => 50,
        ]);

        $response = $this->actingAs($this->gudang, 'sanctum')->postJson('/api/v1/po/dari-kekurangan', [
            'supplier_id' => $this->supplier->id,
            'tgl_po' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('purchase_order_items', [
            'po_id' => $response->json('data.id'),
            'bahan_id' => $this->bahan->id,
            'qty_pesan' => 30,
        ]);
    }

    public function test_opname_disetujui_membuat_penyesuaian_stok()
    {
        // Kolom dibuat_oleh ditambahkan untuk mencegah error SQL
        $opname = StokOpname::create([
            'no_opname' => 'OPN-TEST', 'tanggal' => now(), 'status' => 'diajukan', 'dibuat_oleh' => $this->gudang->id,
        ]);

        StokOpnameItem::create([
            'opname_id' => $opname->id, 'bahan_id' => $this->bahan->id,
            'stok_sistem' => 50, 'stok_fisik' => 45, 'selisih' => -5, 'alasan' => 'Rusak',
        ]);

        $response = $this->actingAs($this->manajer, 'sanctum')->postJson("/api/v1/opname/{$opname->id}/setujui");

        $response->assertStatus(200);
        $this->assertDatabaseHas('stok_opname', ['id' => $opname->id, 'status' => 'disetujui']);

        $this->assertDatabaseHas('bahan', ['id' => $this->bahan->id, 'stok' => 45]);
        $this->assertDatabaseHas('stok_mutasi', ['bahan_id' => $this->bahan->id, 'tipe' => 'penyesuaian', 'qty' => -5]);
    }

    public function test_opname_ditolak_jika_stok_fisik_di_bawah_reservasi()
    {
        $opname = StokOpname::create([
            'no_opname' => 'OPN-TEST2', 'tanggal' => now(), 'status' => 'diajukan', 'dibuat_oleh' => $this->gudang->id,
        ]);
        StokOpnameItem::create([
            'opname_id' => $opname->id, 'bahan_id' => $this->bahan->id,
            'stok_sistem' => 50, 'stok_fisik' => 5, 'selisih' => -45, 'alasan' => 'Hilang',
        ]);

        $response = $this->actingAs($this->manajer, 'sanctum')->postJson("/api/v1/opname/{$opname->id}/setujui");

        // Memastikan sistem menolak (422) operasi yang membuat stok fisik lebih rendah dari stok reservasi
        $response->assertStatus(422);
        $this->assertStringContainsString('di bawah jumlah reservasi', $response->json('message'));
        $this->assertDatabaseHas('bahan', ['id' => $this->bahan->id, 'stok' => 50]); // Stok tidak berubah
    }
}
