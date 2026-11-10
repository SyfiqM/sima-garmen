<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Penomoran dokumen: {KODE}-{YYMM}-{URUT 4 digit}, reset tiap bulan.
 * Aman dipanggil bersamaan (row lock pada tabel `penomoran`).
 * Panggil di dalam transaksi yang sama dengan penyimpanan dokumen.
 */
class NumberingService
{
    public const KODE = ['ORD', 'WO', 'PO', 'BM', 'PB', 'QC', 'PK', 'SJ', 'OPN'];

    public static function next(string $kode, ?CarbonInterface $tanggal = null): string
    {
        if (! in_array($kode, self::KODE, true)) {
            throw new InvalidArgumentException("Kode dokumen tidak dikenal: {$kode}");
        }

        $periode = ($tanggal ?? now())->format('Ym');

        return DB::transaction(function () use ($kode, $periode) {
            DB::table('penomoran')->insertOrIgnore([
                'kode' => $kode,
                'periode' => $periode,
                'nomor_terakhir' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $baris = DB::table('penomoran')
                ->where('kode', $kode)
                ->where('periode', $periode)
                ->lockForUpdate()
                ->first();

            $urut = $baris->nomor_terakhir + 1;

            DB::table('penomoran')
                ->where('id', $baris->id)
                ->update(['nomor_terakhir' => $urut, 'updated_at' => now()]);

            return sprintf('%s-%s-%04d', $kode, substr($periode, 2), $urut);
        });
    }
}
