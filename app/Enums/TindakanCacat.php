<?php

namespace App\Enums;

enum TindakanCacat: string
{
    case Repair = 'repair';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Repair => 'Repair',
            self::Reject => 'Reject',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Repair => 'yellow',
            self::Reject => 'red',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
