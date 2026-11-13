<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\Satuan;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch8HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_ekspor_excel_tidak_membiarkan_teks_berawalan_sama_dengan_menjadi_rumus(): void
    {
        $service = app(ReportService::class);

        $this->assertSame("'=1+1", $service->formatSel('=1+1', 'text', false));
        $this->assertSame("'=HYPERLINK(\"http://x\")", $service->formatSel('=HYPERLINK("http://x")', 'text', false));

        // PDF dan teks biasa tidak berubah; angka tetap numerik
        $this->assertSame('=1+1', $service->formatSel('=1+1', 'text', true));
        $this->assertSame('Kain katun', $service->formatSel('Kain katun', 'text', false));
        $this->assertSame('- catatan', $service->formatSel('- catatan', 'text', false));
        $this->assertSame(5, $service->formatSel(5, 'int', false));
    }

    public function test_filter_stok_di_bawah_minimum_konsisten_dengan_dashboard_gudang(): void
    {
        $gudang = User::factory()->create(['role' => 'gudang']);
        $satuan = Satuan::create(['kode' => 'ROLL', 'nama' => 'Roll']);

        $buat = fn (string $kode, float $stok, float $dipesan, float $min) => Bahan::create([
            'kode' => $kode, 'nama' => "Bahan {$kode}", 'jenis' => 'benang', 'satuan_id' => $satuan->id,
            'stok' => $stok, 'stok_dipesan' => $dipesan, 'stok_minimum' => $min,
        ]);

        $buat('A1', 100, 95, 10); // tersedia 5 <= minimum 10 -> di bawah minimum
        $buat('B1', 100, 0, 10);  // tersedia 100 -> aman
        $buat('C1', 3, 0, 0);     // minimum 0 -> tidak dihitung

        $this->actingAs($gudang, 'sanctum')
            ->getJson('/api/v1/stok?di_bawah_minimum=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.kode', 'A1');

        $this->actingAs($gudang, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.tipe', 'gudang')
            ->assertJsonPath('data.bahan_di_bawah_minimum.total', 1);
    }
}