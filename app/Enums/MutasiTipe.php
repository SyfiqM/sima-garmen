<?php

namespace App\Enums;

enum MutasiTipe: string
{
    case Masuk = 'masuk';
    case Keluar = 'keluar';
    case Retur = 'retur';
    case Penyesuaian = 'penyesuaian';
    case Koreksi = 'koreksi';

    public function label(): string
    {
        return match ($this) {
            self::Masuk => 'Masuk',
            self::Keluar => 'Keluar',
            self::Retur => 'Retur',
            self::Penyesuaian => 'Penyesuaian',
            self::Koreksi => 'Koreksi',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Masuk => 'green',
            self::Keluar => 'red',
            self::Retur => 'blue',
            self::Penyesuaian => 'yellow',
            self::Koreksi => 'gray',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
