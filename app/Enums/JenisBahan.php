<?php

namespace App\Enums;

enum JenisBahan: string
{
    case Kain = 'kain';
    case Benang = 'benang';
    case Aksesoris = 'aksesoris';
    case Kemasan = 'kemasan';

    public function label(): string
    {
        return match ($this) {
            self::Kain => 'Kain',
            self::Benang => 'Benang',
            self::Aksesoris => 'Aksesoris',
            self::Kemasan => 'Kemasan',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Kain => 'gray',
            self::Benang => 'gray',
            self::Aksesoris => 'gray',
            self::Kemasan => 'gray',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
