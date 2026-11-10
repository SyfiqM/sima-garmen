<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OptionController extends Controller
{
    public function getOpsi(Request $request, $nama)
    {
        $map = [
            'pelanggan' => \App\Models\Pelanggan::class,
            'supplier' => \App\Models\Supplier::class,
            'ukuran' => \App\Models\Ukuran::class,
            'warna' => \App\Models\Warna::class,
            'satuan' => \App\Models\Satuan::class,
            'jenis-cacat' => \App\Models\JenisCacat::class,
            'line-produksi' => \App\Models\LineProduksi::class,
            'tahap' => \App\Models\Tahap::class,
            'bahan' => \App\Models\Bahan::class,
            'produk' => \App\Models\Produk::class,
        ];

        if (!isset($map[$nama])) abort(404, 'Opsi tidak ditemukan.');

        $modelClass = $map[$nama];
        
        // Ambil data ringan saja (id dan nama), filter aktif
        $data = $modelClass::where('is_active', 1)->get(['id', 'nama as label']);
        
        return response()->json(['data' => $data]);
    }
}