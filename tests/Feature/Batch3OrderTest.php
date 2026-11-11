<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Bahan;
use App\Models\Produk;
use App\Models\BOM;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tahap;
use App\Models\Pelanggan;
use App\Models\Warna;
use App\Models\Ukuran;
use App\Models\Satuan;

class Batch3OrderTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $manajer;
    protected $bahan;
    protected $produk;
    protected $satuan;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 1. Setup Tahap Produksi
        Tahap::insert([
            ['id' => 1, 'kode' => 'cutting', 'nama' => 'Cutting', 'urutan' => 1],
            ['id' => 2, 'kode' => 'sewing', 'nama' => 'Sewing', 'urutan' => 2],
            ['id' => 3, 'kode' => 'finishing', 'nama' => 'Finishing', 'urutan' => 3],
        ]);
        
        // 2. Setup User & Role
        // Kita set properti secara manual agar terhindar dari blokir mass-assignment saat testing
        $this->admin = User::factory()->create();
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->manajer = User::factory()->create();
        $this->manajer->role = 'manajer';
        $this->manajer->save();
        
        // 3. Setup Master Pendukung & Bahan
        // Ini perbaikan error SQL 1364: Field 'satuan_id' doesn't have a default value
        $this->satuan = Satuan::create(['kode' => 'MTR', 'nama' => 'Meter']);

        $this->bahan = Bahan::create([
            'kode' => 'B01', 
            'nama' => 'Kain Katun', 
            'jenis' => 'kain', 
            'satuan_id' => $this->satuan->id, // <- satuan_id dimasukkan
            'stok' => 100, 
            'stok_minimum' => 10
        ]);

        // 4. Setup Produk & BOM
        $this->produk = Produk::create(['kode' => 'P01', 'nama' => 'Kaos Polos']);
        
        BOM::create([
            'produk_id' => $this->produk->id,
            'bahan_id' => $this->bahan->id,
            'tahap_id' => 1, // tahap cutting
            'qty_per_pcs' => 1.5,
            'waste_persen' => 0
        ]);
    }

    public function test_order_dapat_disetujui_dan_membuat_wo_beserta_reservasi()
    {
        // 1. Buat Order Draft & Itemnya
        // Ini perbaikan error SQL 1364: Field 'dibuat_oleh' doesn't have a default value
        $order = Order::create([
            'no_order' => 'ORD-TEST-001',
            'pelanggan_id' => Pelanggan::create(['kode' => 'PLG1', 'nama' => 'Test', 'alamat' => '-'])->id,
            'tgl_order' => now(),
            'deadline' => now()->addDays(7),
            'status' => 'diajukan',
            'dibuat_oleh' => $this->admin->id // <- dibuat_oleh dimasukkan
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'produk_id' => $this->produk->id,
            'warna_id' => Warna::create(['nama' => 'Merah'])->id,
            'ukuran_id' => Ukuran::create(['kode' => 'L', 'urutan' => 1])->id,
            'qty' => 10
        ]); 

        // 2. Tembak Endpoint API sebagai Manajer menggunakan Sanctum
        $response = $this->actingAs($this->manajer, 'sanctum')
                         ->postJson("/api/v1/order/{$order->id}/setujui");
        
        // 3. Pengecekan Hasil (Assertions)
        $response->assertStatus(200);
        
        // Pastikan transisi status berhasil dan tabel WO terbentuk
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'siap_produksi']);
        $this->assertDatabaseHas('work_orders', ['order_id' => $order->id, 'status' => 'aktif']);
        
        // Pastikan snapshot BOM menyalin data dengan akurat (10 pcs * 1.5 meter = 15 meter)
        $this->assertDatabaseHas('wo_bahan', [
            'bahan_id' => $this->bahan->id,
            'qty_rencana' => 15,
            'qty_reservasi' => 15
        ]);

        // Pastikan stok ter-reservasi di tabel utama Bahan
        $this->assertDatabaseHas('bahan', [
            'id' => $this->bahan->id,
            'stok_dipesan' => 15
        ]);
    }
}