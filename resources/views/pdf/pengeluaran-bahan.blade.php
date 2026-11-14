<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #20252b; }
        .kop { border-bottom: 2px solid #20252b; padding-bottom: 7px; margin-bottom: 14px; }
        .nama { font-size: 16px; font-weight: bold; }
        .muted { color: #5b6570; }
        h1 { font-size: 19px; margin: 0 0 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #aeb6bf; padding: 7px; }
        th { background: #e9edf0; text-align: left; }
        .info td { border: 0; padding: 4px 8px 4px 0; vertical-align: top; }
        .angka { text-align: right; white-space: nowrap; }
        .peringatan { color: #8a5700; font-weight: bold; }
        .batal { color: #b00020; font-weight: bold; }
    </style>
</head>
<body>
    <div class="kop">
        <div class="nama">{{ $perusahaan['nama_perusahaan'] ?? 'SIMA Garmen' }}</div>
        <div class="muted">{{ $perusahaan['alamat_perusahaan'] ?? '' }} @if(!empty($perusahaan['telepon_perusahaan'])) &middot; Telp. {{ $perusahaan['telepon_perusahaan'] }} @endif</div>
    </div>

    <h1>BUKTI {{ $dokumen->tipe->label() === 'Retur' ? 'RETUR BAHAN' : 'PENGELUARAN BAHAN' }}</h1>
    <div class="muted">{{ $dokumen->no_pb }} · Status: {{ $dokumen->status->value }}</div>
    @if($dokumen->status->value === 'void')
        <p class="batal">VOID — {{ $dokumen->void_alasan }}</p>
    @elseif($dokumen->status_persetujuan === 'menunggu')
        <p class="peringatan">MENUNGGU PERSETUJUAN MANAJER</p>
    @endif

    <table class="info">
        <tr><td><strong>No. WO</strong></td><td>{{ $dokumen->wo->no_wo }}</td><td><strong>No. Order</strong></td><td>{{ $dokumen->wo->order->no_order }}</td></tr>
        <tr><td><strong>Pelanggan</strong></td><td>{{ $dokumen->wo->order->pelanggan->nama }}</td><td><strong>Tahap</strong></td><td>{{ $dokumen->tahap->nama }}</td></tr>
        <tr><td><strong>Tanggal</strong></td><td>{{ $dokumen->tanggal?->format('d-m-Y') ?? '-' }}</td><td><strong>Dibuat Oleh</strong></td><td>{{ $dokumen->dibuatOleh?->name ?? '-' }}</td></tr>
        <tr><td><strong>Persetujuan</strong></td><td>{{ $dokumen->status_persetujuan ?: '-' }}</td><td><strong>Disetujui Oleh</strong></td><td>{{ $dokumen->disetujuiOleh?->name ?? '-' }}</td></tr>
        <tr><td><strong>Alasan Lebih</strong></td><td colspan="3">{{ $dokumen->alasan_lebih ?: '-' }}</td></tr>
        <tr><td><strong>Catatan</strong></td><td colspan="3">{{ $dokumen->catatan ?: '-' }}</td></tr>
    </table>

    <table>
        <thead><tr><th style="width:28px">No</th><th>Kode Bahan</th><th>Nama Bahan</th><th>Satuan</th><th class="angka">Qty</th></tr></thead>
        <tbody>
            @foreach($dokumen->pengeluaranBahanItems as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->bahan?->kode ?? '-' }}</td>
                    <td>{{ $item->bahan?->nama ?? '-' }}</td>
                    <td>{{ $item->bahan?->satuan?->kode ?? '-' }}</td>
                    <td class="angka">{{ number_format((float) $item->qty, 3, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted">Dicetak {{ now()->format('d-m-Y H:i') }} WIB.</p>
</body>
</html>
