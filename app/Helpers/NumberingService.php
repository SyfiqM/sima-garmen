<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class NumberingService
{
    /**
     * Men-generate nomor urut dokumen berdasarkan kode dan periode (YYMM)
     *
     * @param string $kode (Contoh: 'ORD', 'WO', 'PO')
     * @return string (Contoh: 'ORD-2611-0001')
     */
    public static function next(string $kode): string
    {
        // Periode format YYMM (contoh 2611 untuk November 2026)
        $periode = date('ym');

        return DB::transaction(function () use ($kode, $periode) {
            // Cek data penomoran saat ini dengan lockForUpdate untuk mencegah duplikasi
            $penomoran = DB::table('penomoran')
                ->where('kode', $kode)
                ->where('periode', $periode)
                ->lockForUpdate()
                ->first();

            if ($penomoran) {
                $nextNumber = $penomoran->nomor_terakhir + 1;
                DB::table('penomoran')
                    ->where('kode', $kode)
                    ->where('periode', $periode)
                    ->update(['nomor_terakhir' => $nextNumber]);
            } else {
                // Jika belum ada di bulan ini, mulai dari 1
                $nextNumber = 1;
                DB::table('penomoran')->insert([
                    'kode' => $kode,
                    'periode' => $periode,
                    'nomor_terakhir' => $nextNumber
                ]);
            }

            // Format: KODE-YYMM-000X
            return sprintf("%s-%s-%04d", $kode, $periode, $nextNumber);
        });
    }
}