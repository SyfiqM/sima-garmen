<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #20252b; }
        .kop { border-bottom: 2px solid #20252b; padding-bottom: 6px; margin-bottom: 12px; }
        .kop .nama { font-size: 16px; font-weight: bold; }
        .muted { color: #5b6570; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        table.info { width: 100%; border-collapse: collapse; margin: 10px 0 14px; }
        .info td { padding: 3px 8px 3px 0; vertical-align: top; }
        .info td.k { width: 95px; color: #5b6570; }
        table.items { border-collapse: collapse; width: 100%; margin-top: 8px; }
        .items th, .items td { border: 1px solid #aeb6bf; padding: 6px; text-align: left; }
        .items th { background: #e9edf0; }
        .number { text-align: right !important; }
        .koli { margin-top: 12px; }
        .ttd { width: 100%; margin-top: 40px; text-align: center; }
        .ttd td { width: 33%; padding-top: 4px; }
        .batal { color: #b00020; font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>
    <div class="kop">
        <div class="nama">{{ $perusahaan['nama_perusahaan'] ?? 'SIMA Garmen' }}</div>
        <div class="muted">
            {{ $perusahaan['alamat_perusahaan'] ?? '' }}
            @if (!empty($perusahaan['telepon_perusahaan'])) &middot; Telp. {{ $perusahaan['telepon_perusahaan'] }} @endif
        </div>
    </div>

    <h1>SURAT JALAN</h1>
    <div class="muted">{{ $pengiriman->no_sj }}</div>
    @if ($pengiriman->status->value === 'dibatalkan')
        <div class="batal">DIBATALKAN &mdash; {{ $pengiriman->alasan_batal }}</div>
    @endif

    <table class="info">
        <tr>
            <td class="k">Kepada</td><td>{{ $pengiriman->order->pelanggan->nama }}</td>
            <td class="k">Tanggal Kirim</td><td>{{ $pengiriman->tgl_kirim ? $pengiriman->tgl_kirim->format('d-m-Y') : '-' }}</td>
        </tr>
        <tr>
            <td class="k">Alamat Tujuan</td><td>{{ $pengiriman->alamat_tujuan ?: '-' }}</td>
            <td class="k">No. Order</td><td>{{ $pengiriman->order->no_order }}</td>
        </tr>
        <tr>
            <td class="k">Ekspedisi</td><td>{{ $pengiriman->ekspedisi ?: '-' }}</td>
            <td class="k">No. Resi</td><td>{{ $pengiriman->no_resi ?: '-' }}</td>
        </tr>
        <tr>
            <td class="k">Kendaraan</td><td>{{ $pengiriman->no_kendaraan ?: '-' }}</td>
            <td class="k">Sopir</td><td>{{ $pengiriman->nama_sopir ?: '-' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th style="width:28px">No</th><th>Produk</th><th>Warna</th><th>Ukuran</th><th class="number">Qty (pcs)</th></tr>
        </thead>
        <tbody>
            @foreach ($ringkasan as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r['produk'] }}</td>
                    <td>{{ $r['warna'] }}</td>
                    <td>{{ $r['ukuran'] }}</td>
                    <td class="number">{{ number_format($r['qty']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="4"><strong>Total</strong></td>
                <td class="number"><strong>{{ number_format(collect($ringkasan)->sum('qty')) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <div class="koli">
        <strong>Jumlah koli: {{ $pengiriman->pengirimanKoli->count() }}</strong><br>
        <span class="muted">{{ $pengiriman->pengirimanKoli->map(fn ($pk) => $pk->packing->no_koli)->sort()->implode(', ') }}</span>
    </div>

    @if ($pengiriman->catatan)
        <p><strong>Catatan:</strong> {{ $pengiriman->catatan }}</p>
    @endif

    <table class="ttd">
        <tr><td>Pengirim</td><td>Sopir</td><td>Penerima</td></tr>
        <tr><td style="height:60px"></td><td></td><td></td></tr>
        <tr><td>( {{ $pengiriman->dibuatOleh->name ?? '................' }} )</td><td>( {{ $pengiriman->nama_sopir ?: '................' }} )</td><td>( {{ $pengiriman->nama_penerima ?: '................' }} )</td></tr>
    </table>
</body>
</html>