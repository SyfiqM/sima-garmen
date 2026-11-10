<?php

namespace App\Enums;

enum PackingStatus: string
{
    case Tersedia = 'tersedia';
    case DalamPengiriman = 'dalam_pengiriman';
    case Terkirim = 'terkirim';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Tersedia => 'Tersedia',
            self::DalamPengiriman => 'Dalam Pengiriman',
            self::Terkirim => 'Terkirim',
            self::Void => 'Void',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Tersedia => 'green',
            self::DalamPengiriman => 'yellow',
            self::Terkirim => 'blue',
            self::Void => 'gray',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
