<?php

namespace App\Console\Commands;

use App\Helpers\Audit;
use App\Services\IntegrityService;
use Illuminate\Console\Command;

class RecalcCounters extends Command
{
    protected $signature = 'sima:recalc-counters {--wo= : ID Work Order (kosong = semua WO)} {--dry-run : Tampilkan perubahan tanpa menyimpan}';

    protected $description = 'Hitung ulang counter wo_items dari log produksi, inspeksi QC, repair, dan koli';

    public function handle(IntegrityService $service): int
    {
        $woId = $this->option('wo') !== null ? (int) $this->option('wo') : null;
        $dryRun = (bool) $this->option('dry-run');

        $laporan = $service->hitungUlangCounter($woId, $dryRun);

        if ($laporan === []) {
            $this->info('Semua counter sudah sesuai dengan data sumber.');

            return self::SUCCESS;
        }

        $baris = [];
        foreach ($laporan as $item) {
            foreach ($item['perubahan'] as $kolom => $p) {
                $baris[] = [$item['rujukan'], $kolom, $p['dari'], $p['ke']];
            }
        }
        $this->table(['Item', 'Kolom', 'Dari', 'Ke'], $baris);

        if ($dryRun) {
            $this->warn('Dry run: tidak ada data yang diubah.');
        } else {
            Audit::log(null, 'sistem.recalc_counters', 'WoItem', $woId, null, ['jumlah_item' => count($laporan)], 'Hitung ulang counter dari data sumber');
            $this->info(count($laporan).' item diperbarui.');
        }

        return self::SUCCESS;
    }
}