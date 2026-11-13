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
        $periode = now()->format('ym');

        return DB::transaction(function () use ($kode, $periode) {
            // Baris dijamin ada lebih dulu (insertOrIgnore aman dari balapan karena unique kode+periode),
            // sehingga lockForUpdate benar-benar mengunci baris yang sama untuk semua transaksi.
            DB::table('penomoran')->insertOrIgnore([
                'kode' => $kode,
                'periode' => $periode,
                'nomor_terakhir' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $penomoran = DB::table('penomoran')
                ->where('kode', $kode)
                ->where('periode', $periode)
                ->lockForUpdate()
                ->first();

            $nextNumber = $penomoran->nomor_terakhir + 1;
            DB::table('penomoran')
                ->where('kode', $kode)
                ->where('periode', $periode)
                ->update(['nomor_terakhir' => $nextNumber, 'updated_at' => now()]);

            // Format: KODE-YYMM-000X
            return sprintf("%s-%s-%04d", $kode, $periode, $nextNumber);
        });
    }
}