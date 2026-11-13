<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export Excel generik untuk semua laporan Batch 8.
 * Isi sudah diformat oleh ReportService::tabel() (angka tetap numerik, tanggal d-m-Y).
 */
class LaporanExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array<int,string>  $kepala
     * @param  array<int,array<int,mixed>>  $baris
     * @param  array<int,array{0:string,1:mixed}>  $ringkasan
     */
    public function __construct(
        protected string $judul,
        protected array $kepala,
        protected array $baris,
        protected array $ringkasan = [],
    ) {}

    public function array(): array
    {
        $isi = $this->baris;

        if ($this->ringkasan !== []) {
            $isi[] = [];
            $isi[] = ['RINGKASAN'];
            foreach ($this->ringkasan as $r) {
                $isi[] = [$r[0], $r[1]];
            }
        }

        return $isi;
    }

    public function headings(): array
    {
        return $this->kepala;
    }

    public function title(): string
    {
        // Nama sheet Excel: maksimal 31 karakter dan tanpa karakter terlarang.
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->judul), 0, 31);
    }
}
