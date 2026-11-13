<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bahan;
use App\Models\JenisCacat;
use App\Models\LineProduksi;
use App\Models\Pelanggan;
use App\Models\Produk;
use App\Models\Satuan;
use App\Models\Supplier;
use App\Models\Tahap;
use App\Models\Ukuran;
use App\Models\User;
use App\Models\Warna;
use Illuminate\Http\Request;

class OptionController extends Controller
{
    public function getOpsi(Request $request, $nama)
    {
        $roles = match ($nama) {
            'pelanggan' => ['admin', 'manajer', 'staf_order', 'pimpinan'],
            'supplier' => ['admin', 'manajer', 'gudang', 'pimpinan'],
            'bahan' => ['admin', 'manajer', 'gudang', 'pimpinan'],
            'produk' => ['admin', 'manajer', 'staf_order', 'pimpinan'],
            'jenis-cacat', 'line-produksi' => ['admin', 'manajer', 'supervisor', 'qc', 'pimpinan'],
            'ukuran', 'warna', 'satuan', 'tahap' => ['admin', 'manajer', 'staf_order', 'gudang', 'supervisor', 'qc', 'pimpinan'],
            'supervisor-per-tahap' => ['admin', 'manajer'],
            default => [],
        };
        abort_unless($request->user()?->hasRole(...$roles), 403);

        if ($nama === 'supervisor-per-tahap') {
            $data = User::with('tahap')
                ->where('role', 'supervisor')
                ->where('is_active', true)
                ->get()
                ->map(fn ($user) => ['id' => $user->id, 'label' => $user->name, 'tahap' => $user->tahap?->nama]);

            return response()->json(['data' => $data]);
        }

        $map = [
            'pelanggan' => Pelanggan::class,
            'supplier' => Supplier::class,
            'ukuran' => Ukuran::class,
            'warna' => Warna::class,
            'satuan' => Satuan::class,
            'jenis-cacat' => JenisCacat::class,
            'line-produksi' => LineProduksi::class,
            'tahap' => Tahap::class,
            'bahan' => Bahan::class,
            'produk' => Produk::class,
        ];

        if (! isset($map[$nama])) {
            abort(404, 'Opsi tidak ditemukan.');
        }

        $modelClass = $map[$nama];
        $query = $modelClass::query();
        if ($nama !== 'satuan') {
            $query->where('is_active', true);
        }
        $data = $query->get(['id', 'nama as label']);

        return response()->json(['data' => $data]);
    }
}
