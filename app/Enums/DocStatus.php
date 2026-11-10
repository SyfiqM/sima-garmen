<?php

namespace App\Enums;

enum DocStatus: string
{
    case Aktif = 'aktif';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Void => 'Void',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Aktif => 'green',
            self::Void => 'gray',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
