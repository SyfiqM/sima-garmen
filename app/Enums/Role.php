<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manajer = 'manajer';
    case StafOrder = 'staf_order';
    case Gudang = 'gudang';
    case Supervisor = 'supervisor';
    case Qc = 'qc';
    case Pimpinan = 'pimpinan';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manajer => 'Manajer Produksi',
            self::StafOrder => 'Staf Order',
            self::Gudang => 'Staf Gudang',
            self::Supervisor => 'Supervisor Produksi',
            self::Qc => 'Quality Control',
            self::Pimpinan => 'Pimpinan',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Admin => 'red',
            self::Manajer => 'indigo',
            self::StafOrder => 'blue',
            self::Gudang => 'orange',
            self::Supervisor => 'teal',
            self::Qc => 'purple',
            self::Pimpinan => 'gray',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }

    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
