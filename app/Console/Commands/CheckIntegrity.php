<?php

namespace App\Console\Commands;

use App\Services\IntegrityService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckIntegrity extends Command
{
    protected $signature = 'sima:check-integrity {--tanpa-notifikasi : Jangan kirim notifikasi ke Admin dan Manajer bila ada temuan}';

    protected $description = 'Bandingkan stok, reservasi, counter produksi, WO aktif, dan koli pengiriman dengan data sumbernya';

    public function handle(IntegrityService $service): int
    {
        $temuan = $service->periksa();

        if ($temuan === []) {
            $this->info('Integritas data bersih: tidak ada selisih stok, reservasi, atau counter.');

            return self::SUCCESS;
        }

        $this->error(count($temuan).' temuan integritas data:');
        $this->table(
            ['Kategori', 'Rujukan', 'Keterangan'],
            array_map(fn ($t) => [$t['kategori'], $t['rujukan'], $t['pesan']], $temuan),
        );
        Log::warning('sima:check-integrity menemukan selisih', ['jumlah' => count($temuan), 'contoh' => array_slice($temuan, 0, 20)]);

        if (! $this->option('tanpa-notifikasi')) {
            $kunci = 'integritas-'.today()->toDateString();
            if (! NotificationService::sudahTerkirimHariIni($kunci)) {
                NotificationService::toRoles(
                    ['admin', 'manajer'],
                    'Selisih integritas data',
                    count($temuan).' selisih ditemukan. Jalankan php artisan sima:check-integrity untuk rincian.',
                    null,
                    'bahaya',
                    null,
                    null,
                    $kunci,
                );
            }
        }

        return self::FAILURE;
    }
}