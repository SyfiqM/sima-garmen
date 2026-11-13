<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\Bom;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Pelanggan;
use App\Models\Produk;
use App\Models\Satuan;
use App\Models\Tahap;
use App\Models\Ukuran;
use App\Models\User;
use App\Models\Warna;
use App\Models\WoBahan;
use App\Models\WorkOrder;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
            'stok_minimum' => 10,
        ]);

        // 4. Setup Produk & BOM
        $this->produk = Produk::create(['kode' => 'P01', 'nama' => 'Kaos Polos']);

        Bom::create([
            'produk_id' => $this->produk->id,
            'bahan_id' => $this->bahan->id,
            'tahap_id' => 1, // tahap cutting
            'qty_per_pcs' => 1.5,
            'waste_persen' => 0,
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
            'dibuat_oleh' => $this->admin->id, // <- dibuat_oleh dimasukkan
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'produk_id' => $this->produk->id,
            'warna_id' => Warna::create(['nama' => 'Merah'])->id,
            'ukuran_id' => Ukuran::create(['kode' => 'L', 'urutan' => 1])->id,
            'qty' => 10,
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
            'qty_reservasi' => 15,
        ]);

        // Pastikan stok ter-reservasi di tabel utama Bahan
        $this->assertDatabaseHas('bahan', [
            'id' => $this->bahan->id,
            'stok_dipesan' => 15,
        ]);
    }

    public function test_persetujuan_ditolak_jika_salah_satu_produk_tidak_memiliki_bom_cutting()
    {
        $pelanggan = Pelanggan::create(['kode' => 'PLG2', 'nama' => 'Pelanggan Kedua', 'alamat' => '-']);
        $warna = Warna::create(['nama' => 'Biru']);
        $ukuran = Ukuran::create(['kode' => 'M', 'urutan' => 2]);
        $produkTanpaBom = Produk::create(['kode' => 'P02', 'nama' => 'Produk Tanpa BOM']);
        $order = Order::create([
            'no_order' => 'ORD-TEST-002',
            'pelanggan_id' => $pelanggan->id,
            'tgl_order' => now(),
            'deadline' => now()->addDays(7),
            'status' => 'diajukan',
            'dibuat_oleh' => $this->admin->id,
        ]);

        foreach ([$this->produk, $produkTanpaBom] as $produk) {
            OrderItem::create([
                'order_id' => $order->id,
                'produk_id' => $produk->id,
                'warna_id' => $warna->id,
                'ukuran_id' => $ukuran->id,
                'qty' => 10,
            ]);
        }

        $response = $this->actingAs($this->manajer, 'sanctum')
            ->postJson("/api/v1/order/{$order->id}/setujui");

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'BOM_BELUM_LENGKAP');
        $this->assertDatabaseMissing('work_orders', ['order_id' => $order->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'diajukan']);
    }

    public function test_alokasi_ulang_mengisi_kebutuhan_reservasi_setelah_keluar_parsial(): void
    {
        $customer = Pelanggan::create(['kode' => 'PLG3', 'nama' => 'Pelanggan Tiga', 'alamat' => '-']);
        $order = Order::create([
            'no_order' => 'ORD-TEST-003',
            'pelanggan_id' => $customer->id,
            'tgl_order' => now(),
            'deadline' => now()->addDays(7),
            'status' => 'produksi',
            'dibuat_oleh' => $this->admin->id,
        ]);
        $workOrder = WorkOrder::create([
            'no_wo' => 'WO-TEST-003', 'order_id' => $order->id, 'status' => 'aktif',
            'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $this->admin->id,
        ]);
        $woBahan = WoBahan::create([
            'wo_id' => $workOrder->id, 'bahan_id' => $this->bahan->id, 'tahap_id' => $this->tahapId('cutting'),
            'qty_rencana' => 20, 'qty_reservasi' => 5, 'qty_keluar' => 5,
        ]);

        app(ReservationService::class)->alokasi($woBahan);

        $this->assertDatabaseHas('wo_bahan', ['id' => $woBahan->id, 'qty_reservasi' => 20]);
        $this->assertDatabaseHas('bahan', ['id' => $this->bahan->id, 'stok_dipesan' => 15]);
    }

    public function test_simulasi_bahan_and_order_pdf_are_available_to_manager(): void
    {
        $customer = Pelanggan::create(['kode' => 'PLG4', 'nama' => 'Pelanggan Empat', 'alamat' => '-']);
        $color = Warna::create(['nama' => 'Hijau']);
        $size = Ukuran::create(['kode' => 'XL', 'urutan' => 4]);
        $order = Order::create([
            'no_order' => 'ORD-TEST-004',
            'pelanggan_id' => $customer->id,
            'tgl_order' => now(),
            'deadline' => now()->addDays(7),
            'status' => 'draft',
            'dibuat_oleh' => $this->admin->id,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'produk_id' => $this->produk->id,
            'warna_id' => $color->id,
            'ukuran_id' => $size->id,
            'qty' => 10,
        ]);

        $this->actingAs($this->manajer, 'sanctum')
            ->getJson("/api/v1/order/{$order->id}/simulasi-bahan")
            ->assertOk()
            ->assertJsonPath('data.0.qty_rencana', 15);

        $this->actingAs($this->manajer, 'sanctum')
            ->get("/api/v1/order/{$order->id}/cetak")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_order_revision_cancels_work_order_and_releases_remaining_reservation(): void
    {
        $customer = Pelanggan::create(['kode' => 'PLG5', 'nama' => 'Pelanggan Lima', 'alamat' => '-']);
        $order = Order::create([
            'no_order' => 'ORD-TEST-005',
            'pelanggan_id' => $customer->id,
            'tgl_order' => now(),
            'deadline' => now()->addDays(7),
            'status' => 'menunggu_bahan',
            'dibuat_oleh' => $this->admin->id,
        ]);
        $workOrder = WorkOrder::create([
            'no_wo' => 'WO-TEST-005', 'order_id' => $order->id, 'status' => 'aktif',
            'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $this->admin->id,
        ]);
        $woBahan = WoBahan::create([
            'wo_id' => $workOrder->id, 'bahan_id' => $this->bahan->id, 'tahap_id' => $this->tahapId('cutting'),
            'qty_rencana' => 20, 'qty_reservasi' => 10,
        ]);
        $this->bahan->update(['stok_dipesan' => 10]);

        $this->actingAs($this->manajer, 'sanctum')
            ->postJson("/api/v1/order/{$order->id}/revisi", ['alasan' => 'Ubah spesifikasi order'])
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'draft', 'revisi_ke' => 1]);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'dibatalkan']);
        $this->assertDatabaseHas('wo_bahan', ['id' => $woBahan->id, 'qty_reservasi' => 0]);
        $this->assertDatabaseHas('bahan', ['id' => $this->bahan->id, 'stok_dipesan' => 0]);
    }

    public function test_manager_can_change_approved_order_deadline_and_priority_with_reason(): void
    {
        $customer = Pelanggan::create(['kode' => 'PLG6', 'nama' => 'Pelanggan Enam', 'alamat' => '-']);
        $order = Order::create([
            'no_order' => 'ORD-TEST-006',
            'pelanggan_id' => $customer->id,
            'tgl_order' => now()->toDateString(),
            'deadline' => now()->addDays(10)->toDateString(),
            'status' => 'menunggu_bahan',
            'dibuat_oleh' => $this->admin->id,
        ]);
        $workOrder = WorkOrder::create([
            'no_wo' => 'WO-TEST-006', 'order_id' => $order->id, 'status' => 'aktif',
            'tgl_target' => now()->addDays(8)->toDateString(), 'dibuat_oleh' => $this->admin->id,
        ]);

        $this->actingAs($this->manajer, 'sanctum')
            ->putJson("/api/v1/order/{$order->id}/deadline", [
                'deadline' => now()->addDays(20)->toDateString(),
                'alasan' => 'Pelanggan minta waktu tambahan',
            ])
            ->assertOk();

        $this->actingAs($this->manajer, 'sanctum')
            ->putJson("/api/v1/order/{$order->id}/prioritas", [
                'prioritas' => 'urgent',
                'alasan' => 'Jadwal produksi dipercepat',
            ])
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'prioritas' => 'urgent']);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'tgl_target' => now()->addDays(18)->toDateString()]);
    }

    private function tahapId(string $kode): int
    {
        return (int) Tahap::where('kode', $kode)->value('id');
    }
}
