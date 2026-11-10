<?php

namespace App\Enums;

enum WoStatus: string
{
    case Aktif = 'aktif';
    case Selesai = 'selesai';
    case DitutupKurang = 'ditutup_kurang';
    case Dihentikan = 'dihentikan';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Selesai => 'Selesai',
            self::DitutupKurang => 'Ditutup Kurang',
            self::Dihentikan => 'Dihentikan',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Aktif => 'blue',
            self::Selesai => 'green',
            self::DitutupKurang => 'orange',
            self::Dihentikan => 'red',
            self::Dibatalkan => 'gray',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
