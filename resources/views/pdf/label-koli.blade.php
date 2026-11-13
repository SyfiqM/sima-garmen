<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        .no { font-size: 26px; font-weight: bold; letter-spacing: 1px; }
        .koli { font-size: 15px; font-weight: bold; margin: 2px 0 8px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .info td { padding: 2px 6px 2px 0; vertical-align: top; }
        .info td.k { width: 78px; color: #555; }
        table.items { width: 100%; border-collapse: collapse; }
        .items th, .items td { border: 1px solid #333; padding: 4px 6px; text-align: left; }
        .items th { background: #e6e6e6; }
        .number { text-align: right !important; }
    </style>
</head>
<body>
    <div class="no">{{ $packing->no_koli }}</div>
    <div class="koli">KOLI {{ $packing->urutan_koli }} DARI {{ $totalKoli }}</div>

    <table class="info">
        <tr><td class="k">Pelanggan</td><td>{{ $packing->wo->order->pelanggan->nama }}</td><td class="k">Tanggal</td><td>{{ $packing->tanggal->format('d-m-Y') }}</td></tr>
        <tr><td class="k">No. Order</td><td>{{ $packing->wo->order->no_order }}</td><td class="k">Berat</td><td>{{ $packing->berat_kg !== null ? number_format((float) $packing->berat_kg, 2, ',', '.') . ' kg' : '-' }}</td></tr>
        <tr><td class="k">No. WO</td><td>{{ $packing->wo->no_wo }}</td><td class="k">Tujuan</td><td>{{ $packing->wo->order->alamat_kirim ?: '-' }}</td></tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Produk</th><th>Warna</th><th>Ukuran</th><th class="number">Qty</th></tr>
        </thead>
        <tbody>
            @foreach ($packing->packingItems as $pi)
                <tr>
                    <td>{{ $pi->woItem->orderItem->produk->nama }}</td>
                    <td>{{ $pi->woItem->orderItem->warna->nama }}</td>
                    <td>{{ $pi->woItem->orderItem->ukuran->kode }}</td>
                    <td class="number">{{ number_format($pi->qty) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3"><strong>Total</strong></td>
                <td class="number"><strong>{{ number_format($packing->packingItems->sum('qty')) }}</strong></td>
            </tr>
        </tbody>
    </table>
    @if ($packing->catatan)
        <p><strong>Catatan:</strong> {{ $packing->catatan }}</p>
    @endif
</body>
</html>