<?php

namespace App\Enums;

enum PengeluaranTipe: string
{
    case Keluar = 'keluar';
    case Retur = 'retur';

    public function label(): string
    {
        return match ($this) {
            self::Keluar => 'Pengeluaran',
            self::Retur => 'Retur',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Keluar => 'red',
            self::Retur => 'blue',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
