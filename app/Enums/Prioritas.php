<?php

namespace App\Enums;

enum Prioritas: string
{
    case Normal = 'normal';
    case Tinggi = 'tinggi';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Tinggi => 'Tinggi',
            self::Urgent => 'Urgent',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Normal => 'gray',
            self::Tinggi => 'orange',
            self::Urgent => 'red',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
