<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\SimaNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Pengirim notifikasi in-app (lonceng di header).
 * $tipe: info | sukses | peringatan | bahaya
 *
 * $kunci (opsional) adalah penanda peristiwa unik untuk alert terjadwal,
 * dipakai bersama sudahTerkirimHariIni() agar tidak terkirim ganda dalam sehari.
 */
class NotificationService
{
    /**
     * Kirim ke semua user aktif dengan role tertentu.
     *
     * @param  array<int, Role|string>  $roles
     */
    public static function toRoles(
        array $roles,
        string $judul,
        string $pesan,
        ?string $url = null,
        string $tipe = 'info',
        ?int $kecualiUserId = null,
        ?int $tahapId = null,
        ?string $kunci = null,
    ): int {
        $nilai = array_map(fn ($r) => $r instanceof Role ? $r->value : $r, $roles);

        $users = User::query()
            ->where('is_active', true)
            ->whereIn('role', $nilai)
            ->when($tahapId, fn ($q) => $q->where('tahap_id', $tahapId))
            ->when($kecualiUserId, fn ($q) => $q->where('id', '!=', $kecualiUserId))
            ->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, new SimaNotification($judul, $pesan, $url, $tipe, $kunci));
        }

        return $users->count();
    }

    /** Kirim ke user tertentu (satu id atau banyak). */
    public static function toUsers(
        int|array $userIds,
        string $judul,
        string $pesan,
        ?string $url = null,
        string $tipe = 'info',
        ?string $kunci = null,
    ): int {
        $users = User::query()->where('is_active', true)->whereIn('id', (array) $userIds)->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, new SimaNotification($judul, $pesan, $url, $tipe, $kunci));
        }

        return $users->count();
    }

    /** Apakah notifikasi dengan kunci ini sudah dikirim hari ini. */
    public static function sudahTerkirimHariIni(string $kunci): bool
    {
        return DB::table('notifications')
            ->whereDate('created_at', today())
            ->where('data', 'like', '%"kunci":"'.$kunci.'"%')
            ->exists();
    }
}