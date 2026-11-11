<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Bahan;
use App\Models\StokOpname;
use App\Models\StokOpnameItem;
use App\Models\Supplier;
use App\Models\Satuan;

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
            'stok_dipesan' => 10
        ]);
        
        $this->supplier = Supplier::create(['kode' => 'SUP1', 'nama' => 'Toko Benang', 'alamat' => '-']);
    }

    public function test_penerimaan_bahan_tanpa_po_menambah_stok_dan_mencatat_mutasi()
    {
        // Gunakan guard 'sanctum' untuk melewati middleware otentikasi API
        $response = $this->actingAs($this->gudang, 'sanctum')->postJson('/api/v1/penerimaan', [
            'supplier_id' => $this->supplier->id,
            'tgl_terima' => now()->format('Y-m-d'),
            'items' => [
                ['bahan_id' => $this->bahan->id, 'qty' => 100, 'harga_satuan' => 5000]
            ]
        ]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('bahan', [
            'id' => $this->bahan->id,
            'stok' => 150,
            'harga_terakhir' => 5000
        ]);

        $this->assertDatabaseHas('stok_mutasi', [
            'bahan_id' => $this->bahan->id,
            'tipe' => 'masuk',
            'qty' => 100,
            'stok_sebelum' => 50,
            'stok_sesudah' => 150
        ]);
    }

    public function test_opname_disetujui_membuat_penyesuaian_stok()
    {
        // Kolom dibuat_oleh ditambahkan untuk mencegah error SQL
        $opname = StokOpname::create([
            'no_opname' => 'OPN-TEST', 'tanggal' => now(), 'status' => 'diajukan', 'dibuat_oleh' => $this->gudang->id
        ]);
        
        StokOpnameItem::create([
            'opname_id' => $opname->id, 'bahan_id' => $this->bahan->id,
            'stok_sistem' => 50, 'stok_fisik' => 45, 'selisih' => -5, 'alasan' => 'Rusak'
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
            'no_opname' => 'OPN-TEST2', 'tanggal' => now(), 'status' => 'diajukan', 'dibuat_oleh' => $this->gudang->id
        ]);
        StokOpnameItem::create([
            'opname_id' => $opname->id, 'bahan_id' => $this->bahan->id,
            'stok_sistem' => 50, 'stok_fisik' => 5, 'selisih' => -45, 'alasan' => 'Hilang'
        ]);

        $response = $this->actingAs($this->manajer, 'sanctum')->postJson("/api/v1/opname/{$opname->id}/setujui");
        
        // Memastikan sistem menolak (422) operasi yang membuat stok fisik lebih rendah dari stok reservasi
        $response->assertStatus(422);
        $this->assertStringContainsString('di bawah jumlah reservasi', $response->json('message'));
        $this->assertDatabaseHas('bahan', ['id' => $this->bahan->id, 'stok' => 50]); // Stok tidak berubah
    }
}