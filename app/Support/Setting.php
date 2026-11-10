<?php

namespace App\Support;

use App\Models\Pengaturan;

/**
 * Akses tabel `pengaturan` (key-value).
 * Contoh: Setting::int('toleransi_potong_persen', 3)
 */
class Setting
{
    protected static ?array $cache = null;

    public static function all(): array
    {
        return static::$cache ??= Pengaturan::query()->pluck('value', 'key')->all();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $nilai = static::all()[$key] ?? null;

        return ($nilai === null || $nilai === '') ? $default : $nilai;
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) static::get($key, $default);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return (float) static::get($key, $default);
    }

    public static function set(string $key, mixed $value, ?string $deskripsi = null): void
    {
        $data = ['value' => (string) $value];
        if ($deskripsi !== null) {
            $data['deskripsi'] = $deskripsi;
        }

        Pengaturan::updateOrCreate(['key' => $key], $data);
        static::flush();
    }

    public static function flush(): void
    {
        static::$cache = null;
    }
}
