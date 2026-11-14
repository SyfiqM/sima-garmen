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
        .batal { color: #b00020; font-weight: bold; }
    </style>
</head>
<body>
    <div class="kop">
        <div class="nama">{{ $perusahaan['nama_perusahaan'] ?? 'SIMA Garmen' }}</div>
        <div class="muted">{{ $perusahaan['alamat_perusahaan'] ?? '' }} @if(!empty($perusahaan['telepon_perusahaan'])) &middot; Telp. {{ $perusahaan['telepon_perusahaan'] }} @endif</div>
    </div>

    <h1>BUKTI PENERIMAAN BAHAN</h1>
    <div class="muted">{{ $penerimaan->no_bm }} · Status: {{ $penerimaan->status->value }}</div>
    @if($penerimaan->status->value === 'void')
        <p class="batal">DIBATALKAN — {{ $penerimaan->void_alasan }}</p>
    @endif

    <table class="info">
        <tr><td><strong>Supplier</strong></td><td>{{ $penerimaan->supplier->nama }}</td><td><strong>Tanggal Terima</strong></td><td>{{ $penerimaan->tgl_terima?->format('d-m-Y') ?? '-' }}</td></tr>
        <tr><td><strong>No. PO</strong></td><td>{{ $penerimaan->po?->no_po ?? 'Tanpa PO' }}</td><td><strong>No. SJ Supplier</strong></td><td>{{ $penerimaan->no_sj_supplier ?: '-' }}</td></tr>
        <tr><td><strong>Diterima Oleh</strong></td><td>{{ $penerimaan->diterimaOleh?->name ?? '-' }}</td><td><strong>Catatan</strong></td><td>{{ $penerimaan->catatan ?: '-' }}</td></tr>
    </table>

    <table>
        <thead><tr><th style="width:28px">No</th><th>Kode Bahan</th><th>Nama Bahan</th><th>Satuan</th><th class="angka">Qty Diterima</th><th class="angka">Harga Satuan (Rp)</th><th>Referensi PO Item</th></tr></thead>
        <tbody>
            @foreach($penerimaan->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->bahan?->kode ?? '-' }}</td>
                    <td>{{ $item->bahan?->nama ?? '-' }}</td>
                    <td>{{ $item->bahan?->satuan?->kode ?? '-' }}</td>
                    <td class="angka">{{ number_format((float) $item->qty, 3, ',', '.') }}</td>
                    <td class="angka">{{ $item->harga_satuan === null ? '-' : number_format((float) $item->harga_satuan, 2, ',', '.') }}</td>
                    <td>{{ $item->poItem?->id ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted">Dicetak {{ now()->format('d-m-Y H:i') }} WIB.</p>
</body>
</html>
