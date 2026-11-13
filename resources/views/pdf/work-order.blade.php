<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #20252b; }
        .kop { border-bottom: 2px solid #20252b; padding-bottom: 7px; margin-bottom: 12px; }
        .nama { font-size: 16px; font-weight: bold; }
        .muted { color: #5b6570; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 14px 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-top: 7px; }
        th, td { border: 1px solid #aeb6bf; padding: 5px; vertical-align: top; }
        th { background: #e9edf0; text-align: left; }
        .info td { border: 0; padding: 3px 8px 3px 0; }
        .angka { text-align: right; white-space: nowrap; }
    </style>
</head>
<body>
    <div class="kop">
        <div class="nama">{{ $perusahaan['nama_perusahaan'] ?? 'SIMA Garmen' }}</div>
        <div class="muted">{{ $perusahaan['alamat_perusahaan'] ?? '' }} @if(!empty($perusahaan['telepon_perusahaan'])) &middot; Telp. {{ $perusahaan['telepon_perusahaan'] }} @endif</div>
    </div>

    <h1>WORK ORDER / SURAT PERINTAH KERJA</h1>
    <div class="muted">{{ $wo->no_wo }}</div>

    <table class="info">
        <tr>
            <td><strong>No. Order</strong></td><td>{{ $wo->order->no_order }}</td>
            <td><strong>Pelanggan</strong></td><td>{{ $wo->order->pelanggan->nama }}</td>
            <td><strong>Status WO</strong></td><td>{{ $wo->status->value }}</td>
        </tr>
        <tr>
            <td><strong>Tgl. Order</strong></td><td>{{ $wo->order->tgl_order?->format('d-m-Y') ?? '-' }}</td>
            <td><strong>Deadline</strong></td><td>{{ $wo->order->deadline?->format('d-m-Y') ?? '-' }}</td>
            <td><strong>Target WO</strong></td><td>{{ $wo->tgl_target?->format('d-m-Y') ?? '-' }}</td>
        </tr>
        <tr><td><strong>Catatan</strong></td><td colspan="5">{{ $wo->catatan ?: ($wo->order->catatan ?: '-') }}</td></tr>
    </table>

    <h2>Daftar Item Produksi</h2>
    <table>
        <thead>
            <tr>
                <th style="width:25px">No</th><th>Produk</th><th>Warna</th><th>Ukuran</th>
                <th class="angka">Qty Order</th><th class="angka">Batas Potong</th><th>Spesifikasi</th>
            </tr>
        </thead>
        <tbody>
            @foreach($wo->woItems as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->orderItem?->produk?->nama ?? '-' }}</td>
                    <td>{{ $item->orderItem?->warna?->nama ?? '-' }}</td>
                    <td>{{ $item->orderItem?->ukuran?->kode ?? '-' }}</td>
                    <td class="angka">{{ number_format($item->qty_order) }}</td>
                    <td class="angka">{{ number_format($item->qty_batas_potong) }}</td>
                    <td>{{ $item->orderItem?->spesifikasi ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Kebutuhan Bahan per Tahap</h2>
    <table>
        <thead>
            <tr><th style="width:25px">No</th><th>Tahap</th><th>Kode Bahan</th><th>Nama Bahan</th><th>Satuan</th><th class="angka">Rencana</th><th class="angka">Reservasi</th><th class="angka">Keluar</th><th class="angka">Retur</th></tr>
        </thead>
        <tbody>
            @forelse($wo->woBahan as $i => $bahan)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $bahan->tahap?->nama ?? '-' }}</td>
                    <td>{{ $bahan->bahan?->kode ?? '-' }}</td>
                    <td>{{ $bahan->bahan?->nama ?? '-' }}</td>
                    <td>{{ $bahan->bahan?->satuan?->kode ?? '-' }}</td>
                    <td class="angka">{{ number_format((float) $bahan->qty_rencana, 3, ',', '.') }}</td>
                    <td class="angka">{{ number_format((float) $bahan->qty_reservasi, 3, ',', '.') }}</td>
                    <td class="angka">{{ number_format((float) $bahan->qty_keluar, 3, ',', '.') }}</td>
                    <td class="angka">{{ number_format((float) $bahan->qty_retur, 3, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="9">Tidak ada data kebutuhan bahan.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($wo->woTahap->isNotEmpty())
        <h2>Status Tahap</h2>
        <table>
            <thead><tr><th>Tahap</th><th>Status</th><th>Mulai</th><th>Selesai</th><th>Catatan Penutupan</th></tr></thead>
            <tbody>
                @foreach($wo->woTahap as $tahap)
                    <tr>
                        <td>{{ $tahap->tahap?->nama ?? '-' }}</td>
                        <td>{{ $tahap->status->value }}</td>
                        <td>{{ $tahap->mulai_at?->format('d-m-Y H:i') ?? '-' }}</td>
                        <td>{{ $tahap->selesai_at?->format('d-m-Y H:i') ?? '-' }}</td>
                        <td>{{ $tahap->catatan_penutupan ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="muted">Dicetak {{ now()->format('d-m-Y H:i') }} WIB. Dokumen ini merupakan instruksi kerja internal.</p>
</body>
</html>
