<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #20252b; }
        .kop { border-bottom: 2px solid #20252b; padding-bottom: 5px; margin-bottom: 8px; }
        .kop .nama { font-size: 14px; font-weight: bold; }
        .muted { color: #5b6570; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        table.data { border-collapse: collapse; width: 100%; margin-top: 8px; }
        .data th, .data td { border: 1px solid #aeb6bf; padding: 3px 4px; text-align: left; vertical-align: top; }
        .data th { background: #e9edf0; }
        .data tr:nth-child(even) td { background: #f7f8f9; }
        .num { text-align: right !important; }
        table.ring { border-collapse: collapse; margin-top: 12px; }
        .ring td { padding: 2px 10px 2px 0; }
        .ring td.k { color: #5b6570; }
        .peringatan { margin-top: 8px; color: #b00020; }
    </style>
</head>
<body>
    <div class="kop">
        <div class="nama">{{ $perusahaan['nama_perusahaan'] }}</div>
        <div class="muted">
            {{ $perusahaan['alamat_perusahaan'] }}
            @if (!empty($perusahaan['telepon_perusahaan'])) &middot; Telp. {{ $perusahaan['telepon_perusahaan'] }} @endif
        </div>
    </div>

    <h1>{{ $laporan['judul'] }}</h1>
    <div class="muted">
        @if ($laporan['periode'])
            Periode {{ \Carbon\Carbon::parse($laporan['periode']['tgl_dari'])->format('d-m-Y') }}
            s.d. {{ \Carbon\Carbon::parse($laporan['periode']['tgl_sampai'])->format('d-m-Y') }}
        @else
            Posisi per {{ \Carbon\Carbon::parse($laporan['dibuat_pada'])->format('d-m-Y H:i') }}
        @endif
        &middot; Dicetak oleh {{ $dibuat_oleh }} pada {{ now()->format('d-m-Y H:i') }}
    </div>

    <table class="data">
        <thead>
            <tr>
                @foreach ($tabel['kepala'] as $kepala)
                    <th class="{{ in_array($tabel['tipe'][$loop->index], ['int', 'dec', 'money', 'pct'], true) ? 'num' : '' }}">{{ $kepala }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($tabel['baris'] as $baris)
                <tr>
                    @foreach ($baris as $sel)
                        <td class="{{ in_array($tabel['tipe'][$loop->index], ['int', 'dec', 'money', 'pct'], true) ? 'num' : '' }}">{{ $sel }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ max(1, count($tabel['kepala'])) }}" style="text-align:center">Tidak ada data pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($dipotong)
        <div class="peringatan">Hanya {{ $maks_baris_pdf }} dari {{ $total_baris }} baris yang ditampilkan. Gunakan ekspor Excel untuk data lengkap.</div>
    @endif
    @if ($laporan['terpotong'])
        <div class="peringatan">Data melebihi batas {{ \App\Services\ReportService::MAKS_BARIS }} baris dan dipotong. Persempit periode atau filter.</div>
    @endif

    @if (count($tabel['ringkasan']))
        <table class="ring">
            @foreach ($tabel['ringkasan'] as $r)
                <tr><td class="k">{{ $r[0] }}</td><td><strong>{{ $r[1] }}</strong></td></tr>
            @endforeach
        </table>
    @endif
</body>
</html>
