<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PengeluaranBahan;
use App\Models\StokOpname;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SkenarioProduksi;
use Tests\TestCase;

/**
 * Pemeriksaan keamanan Batch 9 (langkah 5): autentikasi, hak akses lintas role lewat ID langsung,
 * token, batas login, upload berkas, mass assignment, SQL injection, dan kebocoran data sensitif.
 */
class Batch9SecurityTest extends TestCase
{
    use RefreshDatabase, SkenarioProduksi;

    /** Satu wakil per role (supervisor diwakili tahap sewing). */
    private array $semuaPeran = ['admin', 'manajer', 'staf_order', 'gudang', 'spv_sewing', 'qc', 'pimpinan'];

    // ------------------------------------------------------------------ pembantu

    /** Data lengkap: order sampai surat jalan, PO, penerimaan, opname, dan pengeluaran bahan. */
    private function fixtureLengkap(): array
    {
        $this->siapkanSkenario(stokKain: 200);

        $order = $this->orderDisetujui(10);
        $wo = $this->woDari($order);
        $item = $this->itemDari($wo);
        $this->keluarkanKain($wo, 10)->assertSuccessful();
        $this->produksiPenuh($wo, $item, 10);
        $this->inspeksi($wo, $item, 10, 10)->assertSuccessful();
        $this->buatKoli($wo, $item, 10)->assertSuccessful();
        $koliId = $this->koliIds($wo)[0];
        $sjId = $this->buatSuratJalan($order, [$koliId])->assertCreated()->json('data.id');

        $poId = $this->api('gudang')->postJson('/api/v1/po', [
            'supplier_id' => $this->supplier->id, 'tgl_po' => now()->toDateString(),
            'items' => [['bahan_id' => $this->kain->id, 'qty_pesan' => 5, 'harga_satuan' => 1000]],
        ])->assertSuccessful()->json('data.id');

        $penerimaanId = $this->api('gudang')->postJson('/api/v1/penerimaan', [
            'supplier_id' => $this->supplier->id, 'tgl_terima' => now()->toDateString(),
            'alasan_tanpa_po' => 'Pembelian langsung untuk uji',
            'items' => [['bahan_id' => $this->kain->id, 'qty' => 5, 'harga_satuan' => 1000]],
        ])->assertSuccessful()->json('data.id');

        $this->api('gudang')->postJson('/api/v1/opname', [
            'tanggal' => now()->toDateString(),
            'items' => [['bahan_id' => $this->kain->id, 'stok_fisik' => 150, 'alasan' => 'Selisih hitung']],
        ])->assertSuccessful();

        return [
            'order' => $order->id, 'wo' => $wo->id, 'item' => $item->id, 'koli' => $koliId, 'sj' => $sjId,
            'po' => $poId, 'penerimaan' => $penerimaanId,
            'opname' => StokOpname::latest('id')->value('id'),
            'pb' => PengeluaranBahan::latest('id')->value('id'),
            'user' => $this->u['spv_sewing']->id, 'bahan' => $this->kain->id, 'produk' => $this->produk->id,
        ];
    }

    /**
     * Setiap role di luar $boleh HARUS mendapat 403. Semua kegagalan dikumpulkan lalu dilaporkan sekaligus.
     *
     * @param  array<int, array{0:string, 1:string, 2:array<int,string>, 3?:array}>  $kasus  [method, uri, role yang boleh, payload]
     */
    private function periksaMatriks(array $kasus): void
    {
        $gagal = [];
        foreach ($kasus as $kasusItem) {
            [$method, $uri, $boleh] = $kasusItem;
            $payload = $kasusItem[3] ?? [];

            foreach (array_diff($this->semuaPeran, $boleh) as $peran) {
                $status = $this->api($peran)->json($method, $uri, $payload)->status();
                if ($status !== 403) {
                    $gagal[] = "{$peran} {$method} {$uri} => {$status} (seharusnya 403)";
                }
            }
        }

        $this->assertSame([], $gagal, "Hak akses bocor:\n".implode("\n", $gagal));
    }

    /**
     * Berkas unggahan asli di disk (bukan UploadedFile::fake). Fake menebak tipe dari ekstensi, sedangkan
     * unggahan nyata dideteksi dari ISI berkas; hanya yang asli yang menguji penyamaran ekstensi.
     */
    private function berkasAsli(string $namaTampil, string $isi): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'uat');
        file_put_contents($path, $isi);

        return new UploadedFile($path, $namaTampil, null, null, true);
    }

    /** Permintaan dengan token Bearer sungguhan (bukan actingAs) pada guard yang bersih. */
    private function denganToken(string $token)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token);
    }

    // ============================================================ autentikasi

    public function test_seluruh_endpoint_api_kecuali_publik_menolak_permintaan_tanpa_token(): void
    {
        $publik = ['api/v1/auth/login', 'api/v1/enum', 'api/v1/pengaturan-umum'];
        $diperiksa = 0;
        $gagal = [];

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1') || in_array($uri, $publik, true)) {
                continue;
            }

            $url = '/'.str_replace(['{tipe_master}', '{kode}'], ['pelanggan', 'cutting'], $uri);
            $url = '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $url), '/');

            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $diperiksa++;
                $status = $this->json($method, $url)->status();
                if ($status !== 401) {
                    $gagal[] = "{$method} {$url} => {$status}";
                }
            }
        }

        $this->assertGreaterThan(100, $diperiksa, 'jumlah route yang diperiksa tidak masuk akal');
        $this->assertSame([], $gagal, "Endpoint tanpa proteksi token:\n".implode("\n", $gagal));
    }

    public function test_endpoint_publik_tidak_membocorkan_data_sensitif(): void
    {
        $this->siapkanSkenario();

        $data = $this->getJson('/api/v1/pengaturan-umum')->assertOk()->json('data');
        $this->assertEmpty(array_diff(array_keys($data), ['nama_perusahaan', 'alamat_perusahaan', 'logo_path']));

        $this->assertStringNotContainsString('password', strtolower($this->getJson('/api/v1/enum')->assertOk()->getContent()));
    }

    public function test_token_kedaluwarsa_ditolak(): void
    {
        $this->siapkanSkenario();
        $token = $this->u['admin']->createToken('uji')->plainTextToken;

        $this->denganToken($token)->getJson('/api/v1/me')->assertOk();

        $this->travel(config('sanctum.expiration') + 1)->minutes();
        $this->denganToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_membuat_token_tidak_berlaku(): void
    {
        $this->siapkanSkenario();
        $token = $this->u['admin']->createToken('uji')->plainTextToken;

        $this->denganToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->denganToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_token_user_yang_dinonaktifkan_langsung_ditolak(): void
    {
        $this->siapkanSkenario();
        $target = User::factory()->create(['role' => 'gudang']);
        $token = $target->createToken('uji')->plainTextToken;
        $this->denganToken($token)->getJson('/api/v1/me')->assertOk();

        $this->api('admin')->postJson("/api/v1/pengguna/{$target->id}/nonaktifkan")->assertOk();

        $this->denganToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, $target->tokens()->count(), 'token ikut dicabut saat user dinonaktifkan');
    }

    public function test_reset_password_oleh_admin_mencabut_semua_token_lama(): void
    {
        $this->siapkanSkenario();
        $target = User::factory()->create(['role' => 'gudang']);
        $token = $target->createToken('uji')->plainTextToken;
        $this->denganToken($token)->getJson('/api/v1/me')->assertOk();

        $this->api('admin')->postJson("/api/v1/pengguna/{$target->id}/reset-password", ['password' => 'PasswordBaru123'])->assertOk();

        $this->denganToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_ganti_password_mencabut_token_perangkat_lain_tetapi_bukan_token_yang_sedang_dipakai(): void
    {
        $this->siapkanSkenario();
        $user = User::factory()->create(['role' => 'gudang', 'password' => bcrypt('password123')]);
        $tokenIni = $user->createToken('perangkat-ini')->plainTextToken;
        $tokenLain = $user->createToken('perangkat-lain')->plainTextToken;

        $this->denganToken($tokenIni)->putJson('/api/v1/me/password', [
            'password_lama' => 'password123',
            'password_baru' => 'PasswordBaru123',
            'password_baru_confirmation' => 'PasswordBaru123',
        ])->assertOk();

        $this->denganToken($tokenIni)->getJson('/api/v1/me')->assertOk();
        $this->denganToken($tokenLain)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_ganti_password_wajib_password_lama_yang_benar(): void
    {
        $this->siapkanSkenario();

        $this->api('gudang')->putJson('/api/v1/me/password', [
            'password_lama' => 'bukan-password-saya',
            'password_baru' => 'PasswordBaru123',
            'password_baru_confirmation' => 'PasswordBaru123',
        ])->assertStatus(422)->assertJsonValidationErrors(['password_lama']);
    }

    public function test_batas_login_5_percobaan_lalu_429_dan_tidak_mengunci_akun_lain(): void
    {
        User::factory()->create(['username' => 'korban', 'password' => bcrypt('password123'), 'is_active' => 1]);
        User::factory()->create(['username' => 'rekan', 'password' => bcrypt('password123'), 'is_active' => 1]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'korban', 'password' => 'salah'])->assertStatus(422);
        }

        // percobaan ke-6 diblokir meskipun password benar
        $this->postJson('/api/v1/auth/login', ['login' => 'korban', 'password' => 'password123'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN')
            ->assertHeader('Retry-After');

        // akun lain dari IP yang sama tetap bisa login
        $this->postJson('/api/v1/auth/login', ['login' => 'rekan', 'password' => 'password123'])->assertOk();
    }

    public function test_pesan_login_gagal_tidak_membedakan_user_tidak_ada_dan_password_salah(): void
    {
        User::factory()->create(['username' => 'ada', 'password' => bcrypt('password123'), 'is_active' => 1]);

        $salahPassword = $this->postJson('/api/v1/auth/login', ['login' => 'ada', 'password' => 'salah'])->json();
        $tidakAda = $this->postJson('/api/v1/auth/login', ['login' => 'tidak-ada', 'password' => 'salah'])->json();

        $this->assertSame($salahPassword, $tidakAda);
    }

    // ============================================================ hak akses lintas role

    public function test_matriks_hak_akses_pengguna_pengaturan_dan_audit_log(): void
    {
        $id = $this->fixtureLengkap();
        $admin = ['admin'];

        $this->periksaMatriks([
            ['GET', '/api/v1/pengguna', $admin],
            ['GET', "/api/v1/pengguna/{$id['user']}", $admin],
            ['POST', '/api/v1/pengguna', $admin],
            ['PUT', "/api/v1/pengguna/{$id['user']}", $admin],
            ['DELETE', "/api/v1/pengguna/{$id['user']}", $admin],
            ['POST', "/api/v1/pengguna/{$id['user']}/reset-password", $admin, ['password' => 'PasswordBaru123']],
            ['POST', "/api/v1/pengguna/{$id['user']}/aktifkan", $admin],
            ['POST', "/api/v1/pengguna/{$id['user']}/nonaktifkan", $admin],
            ['GET', '/api/v1/pengaturan', $admin],
            ['PUT', '/api/v1/pengaturan', $admin],
            ['GET', '/api/v1/audit-log', ['admin', 'manajer']],
        ]);
    }

    public function test_matriks_hak_akses_master_data_dan_bom(): void
    {
        $id = $this->fixtureLengkap();

        $this->periksaMatriks([
            ['GET', '/api/v1/bahan', ['admin', 'manajer', 'gudang', 'pimpinan']],
            ['GET', "/api/v1/bahan/{$id['bahan']}", ['admin', 'manajer', 'gudang', 'pimpinan']],
            ['POST', '/api/v1/bahan', ['admin', 'gudang']],
            ['PUT', "/api/v1/bahan/{$id['bahan']}", ['admin', 'gudang']],
            ['DELETE', "/api/v1/bahan/{$id['bahan']}", ['admin', 'gudang']],
            ['GET', '/api/v1/produk', ['admin', 'manajer', 'staf_order', 'pimpinan']],
            ['POST', '/api/v1/produk', ['admin', 'manajer']],
            ['DELETE', "/api/v1/produk/{$id['produk']}", ['admin', 'manajer']],
            ['GET', "/api/v1/produk/{$id['produk']}/bom", ['admin', 'manajer', 'pimpinan']],
            ['POST', "/api/v1/produk/{$id['produk']}/bom", ['admin', 'manajer']],
            ['GET', '/api/v1/pelanggan', ['admin', 'manajer', 'staf_order', 'pimpinan']],
            ['POST', '/api/v1/pelanggan', ['admin', 'manajer', 'staf_order']],
            ['GET', '/api/v1/supplier', ['admin', 'manajer', 'gudang', 'pimpinan']],
            ['POST', '/api/v1/supplier', ['admin', 'manajer', 'gudang']],
            ['POST', '/api/v1/ukuran', ['admin', 'manajer']],
            ['POST', '/api/v1/warna', ['admin', 'manajer']],
            ['POST', '/api/v1/jenis-cacat', ['admin', 'manajer']],
            ['POST', '/api/v1/line-produksi', ['admin', 'manajer']],
        ]);
    }

    public function test_matriks_hak_akses_order_dan_work_order(): void
    {
        $id = $this->fixtureLengkap();
        $baca = ['admin', 'manajer', 'staf_order', 'gudang', 'qc', 'pimpinan'];
        $manajer = ['admin', 'manajer'];
        $alasan = ['alasan' => 'Alasan uji coba keamanan'];

        $this->periksaMatriks([
            ['GET', '/api/v1/order', $baca],
            ['GET', "/api/v1/order/{$id['order']}", $baca],
            ['GET', "/api/v1/order/{$id['order']}/riwayat", $baca],
            ['GET', "/api/v1/order/{$id['order']}/simulasi-bahan", $baca],
            ['GET', "/api/v1/order/{$id['order']}/cetak", $baca],
            ['POST', '/api/v1/order', ['admin', 'manajer', 'staf_order']],
            ['POST', "/api/v1/order/{$id['order']}/setujui", $manajer],
            ['POST', "/api/v1/order/{$id['order']}/tolak", $manajer, $alasan],
            ['POST', "/api/v1/order/{$id['order']}/revisi", $manajer, $alasan],
            ['POST', "/api/v1/order/{$id['order']}/batalkan", $manajer, $alasan],
            ['POST', "/api/v1/order/{$id['order']}/hentikan", $manajer, $alasan],
            ['PUT', "/api/v1/order/{$id['order']}/deadline", $manajer, $alasan + ['deadline' => now()->addDays(30)->toDateString()]],
            ['PUT', "/api/v1/order/{$id['order']}/prioritas", $manajer, $alasan + ['prioritas' => 'urgent']],
            ['POST', "/api/v1/work-order/{$id['wo']}/tambah-potong", $manajer, $alasan + ['wo_item_id' => $id['item'], 'qty' => 1]],
            ['POST', "/api/v1/work-order/{$id['wo']}/tutup-kurang", $manajer, $alasan],
            ['POST', "/api/v1/work-order/{$id['wo']}/tahap/cutting/buka", $manajer, $alasan],
        ]);
    }

    public function test_matriks_hak_akses_stok_pembelian_dan_opname(): void
    {
        $id = $this->fixtureLengkap();
        $lihatGudang = ['admin', 'manajer', 'gudang', 'pimpinan'];
        $gudang = ['admin', 'gudang'];
        $alasan = ['alasan' => 'Alasan uji coba keamanan'];

        $this->periksaMatriks([
            ['GET', '/api/v1/stok', $lihatGudang],
            ['GET', "/api/v1/bahan/{$id['bahan']}/kartu-stok", $lihatGudang],
            ['GET', '/api/v1/kekurangan-bahan', ['admin', 'manajer', 'gudang']],
            ['GET', '/api/v1/po', $lihatGudang],
            ['GET', "/api/v1/po/{$id['po']}", $lihatGudang],
            ['POST', '/api/v1/po', $gudang],
            ['POST', "/api/v1/po/{$id['po']}/pesan", $gudang],
            ['POST', "/api/v1/po/{$id['po']}/batalkan", $gudang, $alasan],
            ['POST', '/api/v1/po/dari-kekurangan', $gudang, ['supplier_id' => $this->supplier->id, 'tgl_po' => now()->toDateString()]],
            ['GET', '/api/v1/penerimaan', $lihatGudang],
            ['GET', "/api/v1/penerimaan/{$id['penerimaan']}", $lihatGudang],
            ['POST', '/api/v1/penerimaan', $gudang],
            ['POST', "/api/v1/penerimaan/{$id['penerimaan']}/void", $gudang, $alasan],
            ['GET', '/api/v1/opname', ['admin', 'manajer', 'gudang']],
            ['GET', "/api/v1/opname/{$id['opname']}", ['admin', 'manajer', 'gudang']],
            ['POST', '/api/v1/opname', $gudang],
            ['POST', "/api/v1/opname/{$id['opname']}/ajukan", $gudang],
            ['POST', "/api/v1/opname/{$id['opname']}/setujui", ['admin', 'manajer']],
            ['POST', "/api/v1/opname/{$id['opname']}/tolak", ['admin', 'manajer'], $alasan],
            ['GET', '/api/v1/pengeluaran-bahan', ['admin', 'manajer', 'gudang', 'spv_sewing', 'pimpinan']],
            ['POST', '/api/v1/pengeluaran-bahan', $gudang],
            ['POST', "/api/v1/pengeluaran-bahan/{$id['pb']}/setujui", ['admin', 'manajer']],
            ['GET', "/api/v1/pengeluaran-bahan/bahan-wo?wo_id={$id['wo']}&tahap_id={$this->tahapIds['cutting']}", ['admin', 'gudang']],
        ]);
    }

    public function test_matriks_hak_akses_produksi_qc_packing_dan_pengiriman(): void
    {
        $id = $this->fixtureLengkap();
        $alasan = ['alasan' => 'Alasan uji coba keamanan'];
        $logCutting = [
            'wo_id' => $id['wo'], 'tahap_id' => $this->tahapIds['cutting'], 'tanggal' => now()->toDateString(),
            'items' => [['wo_item_id' => $id['item'], 'qty_hasil' => 1, 'qty_reject' => 0]],
        ];
        $lihatKirim = ['admin', 'manajer', 'staf_order', 'gudang', 'pimpinan'];

        $this->periksaMatriks([
            ['POST', '/api/v1/produksi/log', ['admin'], $logCutting], // supervisor sewing/finishing tidak boleh tahap cutting
            ['GET', '/api/v1/produksi/antrean', ['admin', 'spv_sewing']],
            ['GET', '/api/v1/produksi/log', ['admin', 'manajer', 'spv_sewing']],
            ['POST', "/api/v1/work-order/{$id['wo']}/tahap/cutting/tutup", ['admin', 'manajer']],
            ['GET', '/api/v1/qc/antrean', ['admin', 'manajer', 'qc']],
            ['GET', '/api/v1/qc/inspeksi', ['admin', 'manajer', 'qc', 'pimpinan']],
            ['POST', '/api/v1/qc/inspeksi', ['admin', 'qc']],
            ['GET', '/api/v1/repair', ['admin', 'manajer', 'spv_sewing', 'qc', 'pimpinan']],
            ['GET', '/api/v1/packing', ['admin', 'manajer', 'gudang', 'spv_sewing', 'staf_order', 'pimpinan']],
            ['POST', '/api/v1/packing', ['admin']], // spv_sewing bukan finishing
            ['POST', "/api/v1/packing/{$id['koli']}/void", ['admin'], $alasan],
            ['GET', '/api/v1/pengiriman', $lihatKirim],
            ['GET', "/api/v1/pengiriman/{$id['sj']}", $lihatKirim],
            ['GET', "/api/v1/pengiriman/{$id['sj']}/cetak", $lihatKirim],
            ['GET', "/api/v1/pengiriman/{$id['sj']}/bukti", $lihatKirim],
            ['POST', '/api/v1/pengiriman', ['admin', 'gudang']],
            ['POST', "/api/v1/pengiriman/{$id['sj']}/kirim", ['admin', 'gudang']],
            ['POST', "/api/v1/pengiriman/{$id['sj']}/diterima", ['admin', 'staf_order', 'gudang']],
        ]);
    }

    public function test_matriks_hak_akses_laporan_per_jenis(): void
    {
        $this->fixtureLengkap();

        $this->periksaMatriks([
            ['GET', '/api/v1/laporan/order', ['admin', 'manajer', 'pimpinan', 'staf_order']],
            ['GET', '/api/v1/laporan/stok-bahan', ['admin', 'manajer', 'pimpinan', 'gudang']],
            ['GET', '/api/v1/laporan/pembelian', ['admin', 'manajer', 'pimpinan', 'gudang']],
            ['GET', '/api/v1/laporan/pengiriman', ['admin', 'manajer', 'pimpinan', 'staf_order', 'gudang']],
            ['GET', '/api/v1/laporan/produksi-harian', ['admin', 'manajer', 'pimpinan', 'spv_sewing']],
            ['GET', '/api/v1/laporan/reject', ['admin', 'manajer', 'pimpinan', 'spv_sewing', 'qc']],
            ['GET', '/api/v1/laporan/on-time-delivery', ['admin', 'manajer', 'pimpinan']],
            ['GET', '/api/v1/laporan/audit-log', ['admin', 'manajer']],
        ]);

        $jenis = collect($this->api('staf_order')->getJson('/api/v1/laporan')->assertOk()->json('data'))->pluck('jenis')->all();
        sort($jenis);
        $this->assertSame(['order', 'pengiriman'], $jenis);
    }

    public function test_supervisor_tahap_lain_tidak_bisa_menutup_tahap_atau_mengeluarkan_data_tahap_lain(): void
    {
        $id = $this->fixtureLengkap();

        $this->api('spv_sewing')->postJson("/api/v1/work-order/{$id['wo']}/tahap/cutting/tutup")->assertForbidden();
        $this->api('spv_finishing')->postJson("/api/v1/work-order/{$id['wo']}/tahap/sewing/tutup")->assertForbidden();
        $this->api('spv_sewing')
            ->getJson("/api/v1/pengeluaran-bahan/bahan-wo?wo_id={$id['wo']}&tahap_id={$this->tahapIds['cutting']}")
            ->assertForbidden();
    }

    // ============================================================ upload berkas

    public function test_upload_lampiran_menolak_berkas_berbahaya_dan_terlalu_besar(): void
    {
        Storage::fake('local');
        $this->siapkanSkenario();
        $order = $this->buatOrder(5);
        $url = "/api/v1/order/{$order->id}/lampiran";

        $berbahaya = [
            'skrip php' => $this->berkasAsli('shell.php', '<?php system($_GET["c"]); ?>'),
            'php menyamar jpg' => $this->berkasAsli('foto.jpg', '<?php system($_GET["c"]); ?>'),
            'html menyamar pdf' => $this->berkasAsli('dok.pdf', '<html><script>alert(1)</script></html>'),
            'svg' => $this->berkasAsli('gambar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'exe' => UploadedFile::fake()->create('program.exe', 10, 'application/octet-stream'),
        ];
        foreach ($berbahaya as $nama => $berkas) {
            $this->api('staf_order')->post($url, ['file' => $berkas], ['Accept' => 'application/json'])
                ->assertStatus(422);
        }

        $maks = (int) DB::table('pengaturan')->where('key', 'maks_lampiran_mb')->value('value') ?: 5;
        $this->api('staf_order')->post($url, [
            'file' => UploadedFile::fake()->create('besar.pdf', ($maks * 1024) + 1, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, DB::table('order_lampiran')->count());
    }

    public function test_lampiran_disimpan_di_disk_privat_dengan_nama_acak_dan_hak_akses_dijaga(): void
    {
        Storage::fake('local');
        $this->siapkanSkenario();
        $order = $this->buatOrder(5);

        $respons = $this->api('staf_order')->post("/api/v1/order/{$order->id}/lampiran", [
            'file' => UploadedFile::fake()->create('../../../etc/passwd.pdf', 12, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $respons->json('data.path');
        $this->assertStringStartsWith("orders/{$order->id}/attachments/", $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('passwd', $path, 'nama berkas dari klien tidak dipakai sebagai nama di disk');
        $this->assertStringNotContainsString(public_path(), config('filesystems.disks.local.root'));

        $lampiranId = $respons->json('data.id');
        $unduh = "/api/v1/order/{$order->id}/lampiran/{$lampiranId}/unduh";

        $this->api('spv_sewing')->get($unduh)->assertForbidden();
        $this->api('pimpinan')->get($unduh)->assertOk();
    }

    public function test_staf_order_lain_tidak_bisa_mengunggah_atau_menghapus_lampiran_order_orang_lain(): void
    {
        Storage::fake('local');
        $this->siapkanSkenario();
        $lain = User::factory()->create(['role' => 'staf_order']);
        $order = $this->buatOrder(5); // dibuat staf_order bawaan skenario

        $berkas = fn () => UploadedFile::fake()->create('spek.pdf', 12, 'application/pdf');

        $this->actingAs($lain, 'sanctum')->post("/api/v1/order/{$order->id}/lampiran", ['file' => $berkas()], ['Accept' => 'application/json'])
            ->assertForbidden();

        $lampiranId = $this->api('staf_order')->post("/api/v1/order/{$order->id}/lampiran", ['file' => $berkas()], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $this->actingAs($lain, 'sanctum')->deleteJson("/api/v1/order/{$order->id}/lampiran/{$lampiranId}")->assertForbidden();
    }

    public function test_lampiran_order_a_tidak_bisa_diunduh_lewat_url_order_b(): void
    {
        Storage::fake('local');
        $this->siapkanSkenario();
        $a = $this->buatOrder(5);
        $b = $this->buatOrder(5);

        $lampiranId = $this->api('staf_order')->post("/api/v1/order/{$a->id}/lampiran", [
            'file' => UploadedFile::fake()->create('spek.pdf', 12, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->api('admin')->get("/api/v1/order/{$b->id}/lampiran/{$lampiranId}/unduh")->assertNotFound();
        $this->api('admin')->get("/api/v1/order/{$a->id}/lampiran/{$lampiranId}/unduh")->assertOk();
    }

    // ============================================================ mass assignment

    public function test_order_baru_mengabaikan_field_sensitif_dari_klien(): void
    {
        $this->siapkanSkenario();

        $this->api('staf_order')->postJson('/api/v1/order', [
            'pelanggan_id' => $this->pelanggan->id,
            'tgl_order' => now()->toDateString(),
            'deadline' => now()->addDays(10)->toDateString(),
            'items' => [[
                'produk_id' => $this->produk->id, 'warna_id' => $this->warna->id, 'ukuran_id' => $this->ukuran->id, 'qty' => 5,
            ]],
            // field yang tidak boleh ditentukan klien
            'status' => 'terkirim',
            'no_order' => 'HACK-0001',
            'total_qty' => 999999,
            'dibuat_oleh' => $this->u['admin']->id,
            'disetujui_oleh' => $this->u['admin']->id,
            'disetujui_at' => now()->toDateTimeString(),
            'ditutup_kurang' => true,
            'revisi_ke' => 9,
        ])->assertSuccessful();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('draft', $order->status->value);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{4}$/', $order->no_order);
        $this->assertSame(5, (int) $order->total_qty);
        $this->assertSame($this->u['staf_order']->id, (int) $order->dibuat_oleh);
        $this->assertNull($order->disetujui_oleh);
        $this->assertFalse((bool) $order->ditutup_kurang);
        $this->assertSame(0, (int) $order->revisi_ke);
    }

    public function test_ubah_profil_tidak_bisa_menaikkan_role_atau_mengubah_status_akun(): void
    {
        $this->siapkanSkenario();
        $user = $this->u['gudang'];

        $this->api('gudang')->putJson('/api/v1/me/profil', [
            'name' => 'Nama Baru', 'no_hp' => '0812',
            'role' => 'admin', 'is_active' => false, 'tahap_id' => $this->tahapIds['cutting'], 'username' => 'diganti',
        ])->assertOk();

        $segar = $user->fresh();
        $this->assertSame('Nama Baru', $segar->name);
        $this->assertSame('gudang', $segar->role->value);
        $this->assertTrue((bool) $segar->is_active);
        $this->assertNull($segar->tahap_id);
        $this->assertSame($user->username, $segar->username);
    }

    public function test_bahan_tidak_bisa_diisi_stok_lewat_crud(): void
    {
        $this->siapkanSkenario(stokKain: 0);

        $id = $this->api('gudang')->postJson('/api/v1/bahan', [
            'kode' => 'BHN-HACK', 'nama' => 'Bahan Uji', 'jenis' => 'kain', 'satuan_id' => $this->satuan->id,
            'stok_minimum' => 1, 'stok' => 999, 'stok_dipesan' => 500,
        ])->assertSuccessful()->json('data.id');

        $this->assertEquals(0, (float) DB::table('bahan')->where('id', $id)->value('stok'));
        $this->assertEquals(0, (float) DB::table('bahan')->where('id', $id)->value('stok_dipesan'));

        $this->api('gudang')->putJson("/api/v1/bahan/{$id}", [
            'kode' => 'BHN-HACK', 'nama' => 'Bahan Uji 2', 'jenis' => 'kain', 'satuan_id' => $this->satuan->id,
            'stok_minimum' => 1, 'stok' => 777,
        ])->assertSuccessful();
        $this->assertEquals(0, (float) DB::table('bahan')->where('id', $id)->value('stok'));
    }

    public function test_order_yang_sudah_disetujui_tidak_bisa_diubah_lewat_put(): void
    {
        $this->siapkanSkenario();
        $order = $this->orderDisetujui(10);

        $this->api('staf_order')->putJson("/api/v1/order/{$order->id}", [
            'pelanggan_id' => $this->pelanggan->id,
            'tgl_order' => now()->toDateString(),
            'deadline' => now()->addDays(10)->toDateString(),
            'items' => [[
                'produk_id' => $this->produk->id, 'warna_id' => $this->warna->id, 'ukuran_id' => $this->ukuran->id, 'qty' => 5000,
            ]],
        ])->assertStatus(422)->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        $this->assertSame(10, (int) $order->fresh()->total_qty);
    }

    // ============================================================ injeksi

    public function test_parameter_pencarian_aman_dari_sql_injection(): void
    {
        $this->siapkanSkenario();
        $this->buatOrder(5);

        $payload = ["' OR '1'='1", "%'; DROP TABLE orders; --", '" OR 1=1 --', "1' UNION SELECT password FROM users --"];
        $endpoint = [
            ['admin', '/api/v1/order'], ['admin', '/api/v1/bahan'], ['admin', '/api/v1/pengguna'],
            ['admin', '/api/v1/pelanggan'], ['admin', '/api/v1/produk'], ['admin', '/api/v1/po'],
            ['admin', '/api/v1/work-order'], ['admin', '/api/v1/pengiriman'],
        ];

        foreach ($endpoint as [$peran, $uri]) {
            foreach ($payload as $nilai) {
                $status = $this->api($peran)->getJson($uri.'?'.http_build_query(['search' => $nilai, 'status' => $nilai]))->status();
                $this->assertContains($status, [200, 422], "{$uri} dengan payload {$nilai} => {$status}");
            }
        }

        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertSame(1, Order::count());

        // pencarian harafiah yang tidak ada tidak boleh mengembalikan seluruh data
        $this->assertCount(0, $this->api('admin')->getJson('/api/v1/order?search='.urlencode("' OR '1'='1"))->assertOk()->json('data'));
        $this->assertCount(0, $this->api('admin')->getJson('/api/v1/bahan?search='.urlencode("' OR '1'='1"))->assertOk()->json('data'));
    }

    // ============================================================ kebocoran data

    public function test_respons_tidak_memuat_password_atau_hash(): void
    {
        $id = $this->fixtureLengkap();

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => $this->u['admin']->username, 'password' => 'password',
        ]);
        $respons = [
            $this->api('admin')->getJson('/api/v1/me')->getContent(),
            $this->api('admin')->getJson('/api/v1/pengguna')->getContent(),
            $this->api('admin')->getJson("/api/v1/pengguna/{$id['user']}")->getContent(),
            $this->api('admin')->getJson("/api/v1/order/{$id['order']}")->getContent(),
            $this->api('admin')->getJson('/api/v1/audit-log')->getContent(),
            $login->getContent(),
        ];

        foreach ($respons as $i => $isi) {
            $this->assertStringNotContainsString('$2y$', $isi, "respons #{$i} memuat hash password");
            $this->assertStringNotContainsString('"password":', $isi, "respons #{$i} memuat field password");
            $this->assertStringNotContainsString('remember_token', $isi, "respons #{$i} memuat remember_token");
        }
    }

    public function test_audit_log_pembuatan_dan_perubahan_user_tidak_menyimpan_hash_password(): void
    {
        $this->siapkanSkenario();

        $id = $this->api('admin')->postJson('/api/v1/pengguna', [
            'name' => 'User Baru', 'username' => 'userbaru', 'email' => 'baru@example.test',
            'password' => 'PasswordBaru123', 'role' => 'gudang',
        ])->assertCreated()->json('data.id');
        $this->api('admin')->postJson("/api/v1/pengguna/{$id}/reset-password", ['password' => 'PasswordLain123'])->assertOk();

        $log = json_encode(DB::table('activity_log')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('$2y$', $log);
        $this->assertStringNotContainsString('PasswordBaru123', $log);
        $this->assertStringNotContainsString('PasswordLain123', $log);
    }
}