<?php

namespace Database\Seeders;

use App\Models\Pelanggan;
use App\Models\Produk;
use App\Models\Ukuran;
use App\Models\User;
use App\Models\Warna;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Membuat dataset uji performa sesuai skema SIMA Garmen saat ini:
 * 500 order, masing-masing satu WO/wo_item, dan 50.000 log produksi aktif.
 *
 * Jalankan HANYA di database lokal/pengujian yang memang disiapkan untuk load test:
 *   php artisan db:seed --class=PerformanceSeeder
 *
 * Seeder ini tidak dipanggil oleh DatabaseSeeder dan tidak boleh dijalankan di produksi.
 * Data dibuat idempotent: bila ORD-PERF sudah ada, seeder berhenti tanpa menambah duplikasi.
 */
class PerformanceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('PerformanceSeeder hanya boleh dijalankan pada environment local/testing.');
        }

        if (DB::table('orders')->where('no_order', 'like', 'ORD-PERF-%')->exists()) {
            $this->command?->warn('Data ORD-PERF sudah ada. Seeder dilewati agar tidak membuat duplikasi.');

            return;
        }

        $admin = User::query()->where('role', 'admin')->first();
        if (! $admin) {
            throw new RuntimeException('Akun Admin belum tersedia. Jalankan seeder dasar terlebih dahulu.');
        }

        $tahap = DB::table('tahap')->pluck('id', 'kode');
        foreach (['cutting', 'sewing', 'finishing'] as $kode) {
            if (! isset($tahap[$kode])) {
                throw new RuntimeException("Master tahap {$kode} belum tersedia. Jalankan seeder dasar terlebih dahulu.");
            }
        }

        $pelanggan = Pelanggan::firstOrCreate(
            ['kode' => 'PLG-PERF'],
            ['nama' => 'Pelanggan Uji Performa', 'alamat' => 'Jl. Uji Performa No. 123', 'is_active' => true],
        );
        $produk = Produk::firstOrCreate(
            ['kode' => 'PRD-PERF'],
            ['nama' => 'Produk Uji Performa', 'is_active' => true],
        );
        $warna = Warna::firstOrCreate(
            ['nama' => 'Warna Performa'],
            ['is_active' => true],
        );
        $ukuran = Ukuran::firstOrCreate(
            ['kode' => 'PF'],
            ['urutan' => 999, 'is_active' => true],
        );

        $now = now();
        $orderIds = [];
        $orderItemIds = [];
        $workOrderIds = [];
        $woItemIds = [];

        DB::transaction(function () use (
            $admin, $pelanggan, $produk, $warna, $ukuran, $tahap, $now,
            &$orderIds, &$orderItemIds, &$workOrderIds, &$woItemIds,
        ): void {
            // 500 order.
            $orders = [];
            for ($i = 1; $i <= 500; $i++) {
                $orders[] = [
                    'no_order' => sprintf('ORD-PERF-%05d', $i),
                    'pelanggan_id' => $pelanggan->id,
                    'tgl_order' => $now->toDateString(),
                    'deadline' => $now->copy()->addDays(14)->toDateString(),
                    'prioritas' => 'normal',
                    'status' => 'produksi',
                    'alamat_kirim' => 'Alamat uji performa',
                    'total_qty' => 100,
                    'dibuat_oleh' => $admin->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($orders, 100) as $chunk) {
                DB::table('orders')->insert($chunk);
            }
            $orderIds = DB::table('orders')->where('no_order', 'like', 'ORD-PERF-%')->orderBy('id')->pluck('id')->all();

            // Satu item pada setiap order.
            $orderItems = [];
            foreach ($orderIds as $orderId) {
                $orderItems[] = [
                    'order_id' => $orderId,
                    'produk_id' => $produk->id,
                    'warna_id' => $warna->id,
                    'ukuran_id' => $ukuran->id,
                    'qty' => 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($orderItems, 100) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }
            $orderItemIds = DB::table('order_items')
                ->whereIn('order_id', $orderIds)->orderBy('id')->pluck('id')->all();

            // Satu WO aktif dan tiga tahap per order.
            $workOrders = [];
            foreach ($orderIds as $index => $orderId) {
                $workOrders[] = [
                    'no_wo' => sprintf('WO-PERF-%05d', $index + 1),
                    'order_id' => $orderId,
                    'revisi_ke' => 0,
                    'status' => 'aktif',
                    'tgl_mulai' => $now->toDateString(),
                    'tgl_target' => $now->copy()->addDays(12)->toDateString(),
                    'dibuat_oleh' => $admin->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($workOrders, 100) as $chunk) {
                DB::table('work_orders')->insert($chunk);
            }
            $workOrderIds = DB::table('work_orders')
                ->where('no_wo', 'like', 'WO-PERF-%')->orderBy('id')->pluck('id')->all();

            $woStages = [];
            foreach ($workOrderIds as $workOrderId) {
                foreach (['cutting', 'sewing', 'finishing'] as $kode) {
                    $woStages[] = [
                        'wo_id' => $workOrderId,
                        'tahap_id' => $tahap[$kode],
                        'status' => $kode === 'cutting' ? 'berjalan' : 'menunggu',
                        'mulai_at' => $kode === 'cutting' ? $now : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($woStages, 300) as $chunk) {
                DB::table('wo_tahap')->insert($chunk);
            }

            // Counter cutting disamakan dengan 100 log aktif per item.
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
            $woItemIds = DB::table('wo_items')->whereIn('wo_id', $workOrderIds)->orderBy('id')->pluck('id')->all();

            // 50.000 log produksi aktif (100 log x 500 item, qty hasil total 100/item).
            for ($batch = 0; $batch < 50; $batch++) {
                $logs = [];
                for ($i = 0; $i < 1000; $i++) {
                    $sequence = ($batch * 1000) + $i;
                    $itemIndex = intdiv($sequence, 100);
                    $logs[] = [
                        'wo_item_id' => $woItemIds[$itemIndex],
                        'tahap_id' => $tahap['cutting'],
                        'tanggal' => $now->copy()->subDays($sequence % 30)->toDateString(),
                        'qty_hasil' => 1,
                        'qty_reject' => 0,
                        'status' => 'aktif',
                        'dibuat_oleh' => $admin->id,
                        'created_at' => $now->copy()->subSeconds($sequence),
                        'updated_at' => $now,
                    ];
                }
                DB::table('produksi_log')->insert($logs);
            }
        }, 3);

        $this->command?->info('Dataset performa selesai: 500 order, 500 WO, 500 item WO, dan 50.000 log produksi aktif.');
        $this->command?->info('Jalankan php artisan sima:check-integrity untuk memastikan counter tetap konsisten.');
    }
}
