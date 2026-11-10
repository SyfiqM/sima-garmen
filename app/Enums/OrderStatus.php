<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Diajukan = 'diajukan';
    case Ditolak = 'ditolak';
    case MenungguBahan = 'menunggu_bahan';
    case SiapProduksi = 'siap_produksi';
    case Produksi = 'produksi';
    case SiapKirim = 'siap_kirim';
    case DikirimSebagian = 'dikirim_sebagian';
    case Terkirim = 'terkirim';
    case Dibatalkan = 'dibatalkan';
    case Dihentikan = 'dihentikan';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Diajukan => 'Diajukan',
            self::Ditolak => 'Ditolak',
            self::MenungguBahan => 'Menunggu Bahan',
            self::SiapProduksi => 'Siap Produksi',
            self::Produksi => 'Produksi',
            self::SiapKirim => 'Siap Kirim',
            self::DikirimSebagian => 'Dikirim Sebagian',
            self::Terkirim => 'Terkirim',
            self::Dibatalkan => 'Dibatalkan',
            self::Dihentikan => 'Dihentikan',
        };
    }

    /** Warna badge (lihat komponen x-ui.badge). */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Diajukan => 'yellow',
            self::Ditolak => 'red',
            self::MenungguBahan => 'orange',
            self::SiapProduksi => 'blue',
            self::Produksi => 'indigo',
            self::SiapKirim => 'teal',
            self::DikirimSebagian => 'purple',
            self::Terkirim => 'green',
            self::Dibatalkan => 'gray',
            self::Dihentikan => 'red',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }

    /** Status akhir: tidak ada transisi lanjutan. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Terkirim, self::Dibatalkan, self::Dihentikan], true);
    }

    /** Order hanya boleh diedit pada status ini. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Ditolak], true);
    }

    /** Status order yang dianggap masih berjalan (untuk dashboard dan laporan). */
    public static function aktif(): array
    {
        return [self::Diajukan, self::MenungguBahan, self::SiapProduksi, self::Produksi, self::SiapKirim, self::DikirimSebagian];
    }
}
