<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function log($userId, $aksi, $subjekTipe, $subjekId, $sebelum = null, $sesudah = null, $alasan = null)
    {
        DB::table('activity_log')->insert([
            'user_id' => $userId,
            'aksi' => $aksi,
            'subjek_tipe' => $subjekTipe,
            'subjek_id' => $subjekId,
            'sebelum' => $sebelum ? json_encode($sebelum) : null,
            'sesudah' => $sesudah ? json_encode($sesudah) : null,
            'alasan' => $alasan,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}