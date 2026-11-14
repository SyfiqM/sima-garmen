<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Batch9PerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_performance_under_heavy_load(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $now = now();

        $pelangganId = DB::table('pelanggan')->insertGetId([
            'kode' => 'PLG-PERF',
            'nama' => 'Pelanggan Uji Performa',
            'alamat' => 'Jl. Uji Performa No. 123',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $produkId = DB::table('produk')->insertGetId([
            'kode' => 'PRD-PERF', 'nama' => 'Produk Uji Performa', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $warnaId = DB::table('warna')->insertGetId([
            'nama' => 'Warna Performa', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $ukuranId = DB::table('ukuran')->insertGetId([
            'kode' => 'PF', 'urutan' => 1, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $tahapId = DB::table('tahap')->insertGetId([
            'kode' => 'cutting', 'nama' => 'Pemotongan', 'urutan' => 1, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // 500 order dengan masing-masing satu item, satu WO, dan satu wo_item.
        $orders = [];
        for ($i = 1; $i <= 500; $i++) {
            $orders[] = [
                'no_order' => sprintf('ORD-PERF-%05d', $i),
                'pelanggan_id' => $pelangganId,
                'tgl_order' => $now->toDateString(),
                'deadline' => $now->copy()->addDays(14)->toDateString(),
                'prioritas' => 'normal',
                'status' => 'produksi',
                'alamat_kirim' => 'Alamat uji performa',
                'total_qty' => 100,
                'dibuat_oleh' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($orders, 100) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        $orderIds = DB::table('orders')->orderBy('id')->pluck('id')->all();

        $orderItems = [];
        foreach ($orderIds as $orderId) {
            $orderItems[] = [
                'order_id' => $orderId,
                'produk_id' => $produkId,
                'warna_id' => $warnaId,
                'ukuran_id' => $ukuranId,
                'qty' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($orderItems, 100) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }
        $orderItemIds = DB::table('order_items')->orderBy('id')->pluck('id')->all();

        $workOrders = [];
        foreach ($orderIds as $index => $orderId) {
            $workOrders[] = [
                'no_wo' => sprintf('WO-PERF-%05d', $index + 1),
                'order_id' => $orderId,
                'revisi_ke' => 0,
                'status' => 'aktif',
                'tgl_target' => $now->copy()->addDays(12)->toDateString(),
                'dibuat_oleh' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($workOrders, 100) as $chunk) {
            DB::table('work_orders')->insert($chunk);
        }
        $workOrderIds = DB::table('work_orders')->orderBy('id')->pluck('id')->all();

        $woItems = [];
        foreach ($workOrderIds as $index => $workOrderId) {
            $woItems[] = [
                'wo_id' => $workOrderId,
                'order_item_id' => $orderItemIds[$index],
                'qty_order' => 100,
                'qty_batas_potong' => 103,
                'qty_cutting' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($woItems, 100) as $chunk) {
            DB::table('wo_items')->insert($chunk);
        }
        $woItemIds = DB::table('wo_items')->orderBy('id')->pluck('id')->all();

        // 50.000 log PRODUKSI aktif, tersebar merata: 100 log per wo_item.
        for ($batch = 0; $batch < 50; $batch++) {
            $logs = [];
            for ($i = 0; $i < 1000; $i++) {
                $sequence = ($batch * 1000) + $i;
                $itemIndex = intdiv($sequence, 100);
                $logs[] = [
                    'wo_item_id' => $woItemIds[$itemIndex],
                    'tahap_id' => $tahapId,
                    'tanggal' => $now->copy()->subDays($sequence % 30)->toDateString(),
                    'qty_hasil' => 1,
                    'qty_reject' => 0,
                    'status' => 'aktif',
                    'dibuat_oleh' => $user->id,
                    'created_at' => $now->copy()->subSeconds($sequence),
                    'updated_at' => $now,
                ];
            }
            DB::table('produksi_log')->insert($logs);
        }

        $this->assertDatabaseCount('orders', 500);
        $this->assertDatabaseCount('produksi_log', 50000);

        // Pemanasan: request pertama menanggung biaya boot aplikasi.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertOk();

        $sqlMs = 0.0;
        DB::listen(function ($query) use (&$sqlMs) {
            $sqlMs += $query->time;
        });

        $endpoints = [
            'daftar order' => '/api/v1/order?per_page=15',
            'daftar log produksi' => '/api/v1/produksi/log?per_page=15',
            'dashboard' => '/api/v1/dashboard',
            'laporan order' => '/api/v1/laporan/order?format=json&per_page=15',
        ];

        $waktu = [];
        $sql = [];
        foreach ($endpoints as $nama => $url) {
            $hasil = [];
            for ($k = 0; $k < 3; $k++) {
                $sqlMs = 0.0;
                $start = microtime(true);
                $this->actingAs($user, 'sanctum')->getJson($url)->assertOk();
                $hasil[] = ['t' => microtime(true) - $start, 'sql' => $sqlMs];
            }

            // Median 3 request lebih representatif daripada mengambil request tercepat.
            usort($hasil, fn (array $a, array $b) => $a['t'] <=> $b['t']);
            $median = $hasil[1];
            $waktu[$nama] = $median['t'];
            $sql[$nama] = $median['sql'];
        }

        fwrite(STDERR, "\n".collect($waktu)
            ->map(fn (float $time, string $name) => sprintf('%-22s total %.3f detik | SQL %.1f ms', $name, $time, $sql[$name]))
            ->implode("\n")."\n");

        foreach ($waktu as $nama => $time) {
            $this->assertLessThan(1.5, $time, "Endpoint '{$nama}' terlalu lambat: {$time} detik (SQL {$sql[$nama]} ms).");
        }
    }
}
