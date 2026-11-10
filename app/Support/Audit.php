<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Pencatat audit log.
 *   Audit::log('order.setujui', $order, ['status' => 'diajukan'], ['status' => 'siap_produksi']);
 *   Audit::log('order.tolak', $order, null, null, 'Alasan penolakan');
 */
class Audit
{
    public static function log(
        string $aksi,
        ?Model $subjek = null,
        mixed $sebelum = null,
        mixed $sesudah = null,
        ?string $alasan = null,
    ): ActivityLog {
        $request = request();

        return ActivityLog::create([
            'user_id' => auth()->id(),
            'aksi' => $aksi,
            'subjek_tipe' => $subjek ? class_basename($subjek) : null,
            'subjek_id' => $subjek?->getKey(),
            'sebelum' => static::normalize($sebelum),
            'sesudah' => static::normalize($sesudah),
            'alasan' => $alasan,
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    protected static function normalize(mixed $nilai): ?array
    {
        if ($nilai === null) {
            return null;
        }

        if ($nilai instanceof Model) {
            $nilai = $nilai->attributesToArray();
        } elseif (! is_array($nilai)) {
            $nilai = ['value' => $nilai];
        }

        return Arr::except($nilai, ['password', 'remember_token']);
    }
}
