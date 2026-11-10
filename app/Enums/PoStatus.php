<?php

namespace App\Enums;

enum PoStatus: string
{
    case Draft = 'draft';
    case Dipesan = 'dipesan';
    case DiterimaSebagian = 'diterima_sebagian';
    case Diterima = 'diterima';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Dipesan => 'Dipesan',
            self::DiterimaSebagian => 'Diterima Sebagian',
            self::Diterima => 'Diterima',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Dipesan => 'blue',
            self::DiterimaSebagian => 'yellow',
            self::Diterima => 'green',
            self::Dibatalkan => 'gray',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
