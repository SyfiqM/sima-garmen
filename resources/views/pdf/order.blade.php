<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #20252b; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .muted { color: #5b6570; }
        .summary { width: 100%; margin: 18px 0; }
        .summary td { padding: 3px 8px 3px 0; vertical-align: top; }
        table.items { border-collapse: collapse; width: 100%; margin-top: 16px; }
        .items th, .items td { border: 1px solid #aeb6bf; padding: 7px; text-align: left; }
        .items th { background: #e9edf0; }
        .number { text-align: right !important; }
    </style>
</head>
<body>
    <h1>Lembar Order</h1>
    <div class="muted">{{ $order->no_order }}</div>

    <table class="summary">
        <tr><td><strong>Pelanggan</strong></td><td>{{ $order->pelanggan->nama }}</td><td><strong>Tanggal Order</strong></td><td>{{ $order->tgl_order->format('d-m-Y') }}</td></tr>
        <tr><td><strong>Deadline</strong></td><td>{{ $order->deadline->format('d-m-Y') }}</td><td><strong>Status</strong></td><td>{{ $order->status->label() }}</td></tr>
        <tr><td><strong>Alamat Kirim</strong></td><td colspan="3">{{ $order->alamat_kirim }}</td></tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Produk</th><th>Warna</th><th>Ukuran</th><th class="number">Qty</th><th class="number">Harga Satuan</th><th class="number">Jumlah</th></tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->produk->nama }}</td>
                    <td>{{ $item->warna->nama }}</td>
                    <td>{{ $item->ukuran->kode }}</td>
                    <td class="number">{{ number_format($item->qty) }}</td>
                    <td class="number">{{ $item->harga_satuan === null ? '-' : number_format((float) $item->harga_satuan, 2, ',', '.') }}</td>
                    <td class="number">{{ $item->harga_satuan === null ? '-' : number_format($item->qty * (float) $item->harga_satuan, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p><strong>Total Qty:</strong> {{ number_format($order->total_qty) }}</p>
    <p><strong>Catatan:</strong> {{ $order->catatan ?: '-' }}</p>
</body>
</html>