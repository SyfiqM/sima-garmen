<?php

namespace App\Enums;

enum TahapKode: string
{
    case Cutting = 'cutting';
    case Sewing = 'sewing';
    case Finishing = 'finishing';

    public function label(): string
    {
        return match ($this) {
            self::Cutting => 'Pemotongan',
            self::Sewing => 'Penjahitan',
            self::Finishing => 'Finishing',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Cutting => 'indigo',
            self::Sewing => 'indigo',
            self::Finishing => 'indigo',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
