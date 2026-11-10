<?php

namespace App\Enums;

enum WoTahapStatus: string
{
    case Menunggu = 'menunggu';
    case Berjalan = 'berjalan';
    case Selesai = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::Menunggu => 'Menunggu',
            self::Berjalan => 'Berjalan',
            self::Selesai => 'Selesai',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Menunggu => 'gray',
            self::Berjalan => 'blue',
            self::Selesai => 'green',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
