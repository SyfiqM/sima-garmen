<?php

namespace App\Enums;

enum PengirimanStatus: string
{
    case Disiapkan = 'disiapkan';
    case Dikirim = 'dikirim';
    case Diterima = 'diterima';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Disiapkan => 'Disiapkan',
            self::Dikirim => 'Dikirim',
            self::Diterima => 'Diterima',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Disiapkan => 'yellow',
            self::Dikirim => 'blue',
            self::Diterima => 'green',
            self::Dibatalkan => 'gray',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
