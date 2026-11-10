<?php

namespace App\Enums;

enum RepairStatus: string
{
    case Open = 'open';
    case Selesai = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Selesai => 'Selesai',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'orange',
            self::Selesai => 'green',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
