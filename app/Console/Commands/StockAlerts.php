<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StockAlerts extends Command
{
    protected $signature = 'sima:stock-alerts';

    protected $description = 'Notifikasi bahan dengan stok tersedia di bawah atau sama dengan stok minimum (maksimal satu per bahan per hari)';

    public function handle(): int
    {
        $tanggal = today()->toDateString();

        $daftar = DB::table('bahan')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw('(stok - stok_dipesan) <= stok_minimum')
            ->get(['id', 'kode', 'nama', 'stok', 'stok_dipesan', 'stok_minimum']);

        $terkirim = 0;
        foreach ($daftar as $b) {
            $kunci = "stok-{$b->id}-{$tanggal}";
            if (NotificationService::sudahTerkirimHariIni($kunci)) {
                continue;
            }

            $tersedia = (float) $b->stok - (float) $b->stok_dipesan;
            NotificationService::toRoles(
                ['gudang', 'manajer'],
                'Stok mencapai atau di bawah minimum',
                "{$b->kode} {$b->nama}: tersedia {$this->angka($tersedia)}, minimum {$this->angka($b->stok_minimum)}.",
                null,
                'peringatan',
                null,
                null,
                $kunci,
            );
            $terkirim++;
        }

        $this->info("Alert stok terkirim untuk {$terkirim} bahan.");

        return self::SUCCESS;
    }

    private function angka(mixed $nilai): string
    {
        return rtrim(rtrim(number_format((float) $nilai, 3, ',', '.'), '0'), ',') ?: '0';
    }
}