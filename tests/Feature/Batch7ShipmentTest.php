<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Packing;
use App\Models\PackingItem;
use App\Models\Pelanggan;
use App\Models\Produk;
use App\Models\Tahap;
use App\Models\Ukuran;
use App\Models\User;
use App\Models\Warna;
use App\Models\WoItem;
use App\Models\WorkOrder;
use App\Models\WoTahap;
use App\Services\QcService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Batch7ShipmentTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected $manajer;

    protected $gudang;

    protected $stafOrder;

    protected $pimpinan;

    protected $qc;

    protected $supervisorFinishing;

    protected $order;

    protected $orderItem;

    protected $wo;

    protected $woItem;

    protected array $tahapIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['cutting', 'Pemotongan', 1], ['sewing', 'Penjahitan', 2], ['finishing', 'Finishing', 3]] as [$kode, $nama, $urutan]) {
            $this->tahapIds[$kode] = Tahap::create(['kode' => $kode, 'nama' => $nama, 'urutan' => $urutan])->id;
        }

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->manajer = User::factory()->create(['role' => 'manajer']);
        $this->gudang = User::factory()->create(['role' => 'gudang']);
        $this->stafOrder = User::factory()->create(['role' => 'staf_order']);
        $this->pimpinan = User::factory()->create(['role' => 'pimpinan']);
        $this->qc = User::factory()->create(['role' => 'qc']);
        $this->supervisorFinishing = User::factory()->create(['role' => 'supervisor', 'tahap_id' => $this->tahapIds['finishing']]);

        $pelanggan = Pelanggan::create(['kode' => 'PLG-001', 'nama' => 'Pelanggan Tes', 'alamat' => 'Alamat Tes']);
        $produk = Produk::create(['kode' => 'PRD-001', 'nama' => 'Produk Tes']);
        $warna = Warna::create(['nama' => 'Hitam']);
        $ukuran = Ukuran::create(['kode' => 'M']);

        $this->order = Order::create([
            'no_order' => 'ORD-TEST-07', 'pelanggan_id' => $pelanggan->id, 'tgl_order' => now(),
            'deadline' => now()->addDays(7), 'status' => 'produksi', 'dibuat_oleh' => $this->admin->id,
            'alamat_kirim' => 'Jl. Tujuan No. 1',
        ]);
        $this->orderItem = OrderItem::create([
            'order_id' => $this->order->id, 'produk_id' => $produk->id,
            'warna_id' => $warna->id, 'ukuran_id' => $ukuran->id, 'qty' => 100,
        ]);

        $this->wo = $this->buatWo($this->order, 'WO-TEST-07');
        $this->woItem = $this->buatWoItem($this->wo, $this->orderItem);
    }

    private function buatWo(Order $order, string $noWo): WorkOrder
    {
        $wo = WorkOrder::create([
            'no_wo' => $noWo, 'order_id' => $order->id, 'revisi_ke' => 0,
            'status' => 'aktif', 'tgl_target' => now()->addDays(5), 'dibuat_oleh' => $this->admin->id,
        ]);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['cutting'], 'status' => 'selesai']);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['sewing'], 'status' => 'selesai']);
        WoTahap::create(['wo_id' => $wo->id, 'tahap_id' => $this->tahapIds['finishing'], 'status' => 'berjalan']);

        return $wo;
    }

    private function buatWoItem(WorkOrder $wo, OrderItem $orderItem): WoItem
    {
        return WoItem::create([
            'wo_id' => $wo->id, 'order_item_id' => $orderItem->id, 'qty_order' => 100,
            'qty_batas_potong' => 100, 'qty_cutting' => 100, 'qty_sewing' => 100, 'qty_finishing' => 100,
            'qty_qc_diperiksa' => 100, 'qty_qc_lolos' => 100,
        ]);
    }

    /** Membuat koli lewat API (supervisor finishing). Mengembalikan id koli. */
    private function koli(int $qty): int
    {
        return $this->actingAs($this->supervisorFinishing, 'sanctum')
            ->postJson('/api/v1/packing', [
                'wo_id' => $this->wo->id,
                'tanggal' => now()->format('Y-m-d'),
                'items' => [['wo_item_id' => $this->woItem->id, 'qty' => $qty]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function buatSj(array $koliIds, ?User $user = null): int
    {
        return $this->actingAs($user ?? $this->gudang, 'sanctum')
            ->postJson('/api/v1/pengiriman', [
                'order_id' => $this->order->id,
                'koli_ids' => $koliIds,
                'ekspedisi' => 'JNE',
                'nama_penerima' => 'Budi',
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function kirim(int $sjId): void
    {
        $this->actingAs($this->gudang, 'sanctum')->postJson("/api/v1/pengiriman/{$sjId}/kirim", [])->assertOk();
    }

    private function statusOrder(): string
    {
        return Order::findOrFail($this->order->id)->status->value;
    }

    public function test_pengiriman_parsial_lalu_sisa_dan_status_order_di_tiap_tahap(): void
    {
        $a = $this->koli(40);

        $sj1 = $this->buatSj([$a]);
        $this->assertDatabaseHas('packing', ['id' => $a, 'status' => 'dalam_pengiriman']);
        $this->assertDatabaseHas('pengiriman', ['id' => $sj1, 'status' => 'disiapkan', 'alamat_tujuan' => 'Jl. Tujuan No. 1']);
        $this->assertEquals('produksi', $this->statusOrder());

        $this->kirim($sj1);
        $this->assertDatabaseHas('packing', ['id' => $a, 'status' => 'terkirim']);
        $this->assertDatabaseHas('pengiriman', ['id' => $sj1, 'status' => 'dikirim']);
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_terkirim' => 40]);
        // Produksi belum selesai: status order tidak berubah
        $this->assertEquals('produksi', $this->statusOrder());

        // Packing sisa 60 -> produksi selesai, sebagian sudah terkirim
        $b = $this->koli(60);
        $this->assertDatabaseHas('work_orders', ['id' => $this->wo->id, 'status' => 'selesai']);
        $this->assertEquals('dikirim_sebagian', $this->statusOrder());

        $sj2 = $this->buatSj([$b]);
        $this->assertEquals('dikirim_sebagian', $this->statusOrder());

        $this->kirim($sj2);
        $this->assertEquals('terkirim', $this->statusOrder());
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_terkirim' => 100]);

        $item = WoItem::findOrFail($this->woItem->id);
        $this->assertTrue($item->qty_terkirim <= $item->qty_packing && $item->qty_packing <= $item->qty_order);
    }

    public function test_siap_kirim_bila_produksi_selesai_dan_belum_ada_yang_terkirim(): void
    {
        $this->koli(100);

        $this->assertEquals('siap_kirim', $this->statusOrder());
    }

    public function test_koli_yang_sudah_masuk_sj_lain_tidak_bisa_dipilih(): void
    {
        $a = $this->koli(40);
        $this->buatSj([$a]);

        $this->actingAs($this->gudang, 'sanctum')
            ->postJson('/api/v1/pengiriman', ['order_id' => $this->order->id, 'koli_ids' => [$a]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'KOLI_TIDAK_TERSEDIA');

        $this->assertDatabaseCount('pengiriman', 1);

        $b = $this->koli(30);
        $this->actingAs($this->gudang, 'sanctum')
            ->getJson("/api/v1/order/{$this->order->id}/koli-tersedia")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $b);
    }

    public function test_koli_milik_order_lain_ditolak(): void
    {
        $this->koli(40);

        $order2 = Order::create([
            'no_order' => 'ORD-TEST-08', 'pelanggan_id' => $this->order->pelanggan_id, 'tgl_order' => now(),
            'deadline' => now()->addDays(7), 'status' => 'produksi', 'dibuat_oleh' => $this->admin->id,
        ]);
        $oi2 = OrderItem::create([
            'order_id' => $order2->id, 'produk_id' => $this->orderItem->produk_id,
            'warna_id' => $this->orderItem->warna_id, 'ukuran_id' => $this->orderItem->ukuran_id, 'qty' => 100,
        ]);
        $wo2 = $this->buatWo($order2, 'WO-TEST-08');
        $item2 = $this->buatWoItem($wo2, $oi2);
        $koliLain = Packing::create([
            'no_koli' => 'PK-TEST-0001', 'wo_id' => $wo2->id, 'urutan_koli' => 1, 'tanggal' => now(),
            'status' => 'tersedia', 'dibuat_oleh' => $this->admin->id,
        ]);
        PackingItem::create(['packing_id' => $koliLain->id, 'wo_item_id' => $item2->id, 'qty' => 10]);

        $this->actingAs($this->gudang, 'sanctum')
            ->postJson('/api/v1/pengiriman', ['order_id' => $this->order->id, 'koli_ids' => [$koliLain->id]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDASI_GAGAL');

        $this->assertDatabaseHas('packing', ['id' => $koliLain->id, 'status' => 'tersedia']);
    }

    public function test_batal_sebelum_dikirim_koli_kembali_tersedia(): void
    {
        $a = $this->koli(50);
        $sj = $this->buatSj([$a]);

        $this->actingAs($this->manajer, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Salah pilih koli'])
            ->assertForbidden();

        $this->actingAs($this->gudang, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'abc'])
            ->assertUnprocessable();

        $this->actingAs($this->gudang, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Salah pilih koli'])
            ->assertOk();

        $this->assertDatabaseHas('pengiriman', ['id' => $sj, 'status' => 'dibatalkan', 'alasan_batal' => 'Salah pilih koli']);
        $this->assertDatabaseHas('packing', ['id' => $a, 'status' => 'tersedia']);

        // SJ yang sudah dibatalkan tidak dapat dibatalkan lagi
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Salah pilih koli'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'STATUS_TIDAK_VALID');
    }

    public function test_batal_setelah_dikirim_hanya_manajer_atau_admin_dan_qty_terkirim_dikurangi(): void
    {
        $a = $this->koli(50);
        $sj = $this->buatSj([$a]);
        $this->kirim($sj);
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_terkirim' => 50]);

        $this->actingAs($this->gudang, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Barang ditolak pelanggan'])
            ->assertForbidden();

        $this->actingAs($this->manajer, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Barang ditolak pelanggan'])
            ->assertOk();

        $this->assertDatabaseHas('pengiriman', ['id' => $sj, 'status' => 'dibatalkan']);
        $this->assertDatabaseHas('packing', ['id' => $a, 'status' => 'tersedia']);
        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_terkirim' => 0, 'qty_packing' => 50]);
        $this->assertDatabaseHas('activity_log', ['aksi' => 'pengiriman.batal', 'subjek_tipe' => 'Pengiriman', 'subjek_id' => $sj]);
    }

    public function test_batal_setelah_dikirim_menurunkan_status_order_terkirim(): void
    {
        $a = $this->koli(100);
        $sj = $this->buatSj([$a]);
        $this->kirim($sj);
        $this->assertEquals('terkirim', $this->statusOrder());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Salah kirim ke alamat lain'])
            ->assertOk();

        $this->assertEquals('siap_kirim', $this->statusOrder());
    }

    public function test_konfirmasi_diterima_dengan_bukti_dan_otorisasi(): void
    {
        Storage::fake('local');

        $a = $this->koli(100);
        $sj = $this->buatSj([$a]);

        // Belum dikirim -> tidak bisa diterima
        $this->actingAs($this->stafOrder, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/diterima", [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        $this->kirim($sj);

        $this->actingAs($this->qc, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/diterima", [])
            ->assertForbidden();
        $this->actingAs($this->manajer, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/diterima", [])
            ->assertForbidden();

        $this->actingAs($this->stafOrder, 'sanctum')
            ->post("/api/v1/pengiriman/{$sj}/diterima", [
                'tgl_diterima' => now()->format('Y-m-d'),
                'bukti' => UploadedFile::fake()->image('bukti.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertDatabaseHas('pengiriman', ['id' => $sj, 'status' => 'diterima']);
        $path = \App\Models\Pengiriman::findOrFail($sj)->bukti_terima_path;
        $this->assertNotNull($path);
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->actingAs($this->stafOrder, 'sanctum')->get("/api/v1/pengiriman/{$sj}/bukti")->assertOk();

        // Setelah diterima tidak dapat dibatalkan
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/batal", ['alasan' => 'Salah kirim ke alamat lain'])
            ->assertStatus(409);
    }

    public function test_bukti_terima_menolak_berkas_berbahaya(): void
    {
        Storage::fake('local');

        $a = $this->koli(100);
        $sj = $this->buatSj([$a]);
        $this->kirim($sj);

        $this->actingAs($this->stafOrder, 'sanctum')
            ->post("/api/v1/pengiriman/{$sj}/diterima", [
                'bukti' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertDatabaseHas('pengiriman', ['id' => $sj, 'status' => 'dikirim']);
    }

    public function test_pdf_surat_jalan_terbentuk_dan_tanpa_harga(): void
    {
        $a = $this->koli(100);
        $sj = $this->buatSj([$a]);

        $response = $this->actingAs($this->gudang, 'sanctum')->get("/api/v1/pengiriman/{$sj}/cetak");
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));

        $service = app(ShipmentService::class);
        $pengiriman = $service->detail($sj, $this->gudang->id);
        $html = view('pdf.surat-jalan', [
            'pengiriman' => $pengiriman,
            'ringkasan' => $pengiriman->ringkasan_item,
            'perusahaan' => collect(),
        ])->render();

        $this->assertStringNotContainsStringIgnoringCase('harga', $html);
        $this->assertStringContainsString('Produk Tes', $html);
        $this->assertStringContainsString($pengiriman->no_sj, $html);
    }

    public function test_hak_akses_pengiriman_sesuai_matriks(): void
    {
        $a = $this->koli(40);

        // Buat SJ hanya Admin dan Gudang
        foreach ([$this->manajer, $this->stafOrder, $this->pimpinan, $this->qc, $this->supervisorFinishing] as $user) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/pengiriman', ['order_id' => $this->order->id, 'koli_ids' => [$a]])
                ->assertForbidden();
        }
        $this->assertDatabaseCount('pengiriman', 0);

        $sj = $this->buatSj([$a], $this->admin);

        // Lihat: Admin, Manajer, Staf Order, Gudang, Pimpinan
        foreach ([$this->admin, $this->manajer, $this->stafOrder, $this->gudang, $this->pimpinan] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/pengiriman')->assertOk()->assertJsonPath('total', 1);
            $this->actingAs($user, 'sanctum')->getJson("/api/v1/pengiriman/{$sj}")->assertOk();
        }
        foreach ([$this->qc, $this->supervisorFinishing] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/pengiriman')->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/v1/pengiriman/{$sj}")->assertForbidden();
        }

        // Kirim hanya Admin dan Gudang
        $this->actingAs($this->stafOrder, 'sanctum')->postJson("/api/v1/pengiriman/{$sj}/kirim", [])->assertForbidden();
        $this->actingAs($this->manajer, 'sanctum')->postJson("/api/v1/pengiriman/{$sj}/kirim", [])->assertForbidden();
    }

    public function test_order_dihentikan_tetap_boleh_mengirim_koli_tanpa_mengubah_status(): void
    {
        $a = $this->koli(40);
        Order::whereKey($this->order->id)->update(['status' => 'dihentikan']);

        $sj = $this->buatSj([$a]);
        $this->kirim($sj);

        $this->assertDatabaseHas('packing', ['id' => $a, 'status' => 'terkirim']);
        $this->assertEquals('dihentikan', $this->statusOrder());
    }

    public function test_kirim_dua_kali_ditolak_dan_notifikasi_ke_staf_order(): void
    {
        $a = $this->koli(40);
        $sj = $this->buatSj([$a]);
        $this->kirim($sj);

        $this->actingAs($this->gudang, 'sanctum')
            ->postJson("/api/v1/pengiriman/{$sj}/kirim", [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        $this->assertDatabaseHas('wo_items', ['id' => $this->woItem->id, 'qty_terkirim' => 40]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->stafOrder->id]);
    }
}