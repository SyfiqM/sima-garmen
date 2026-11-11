<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bahan;
use App\Models\StokMutasi;
use App\Models\WoBahan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang', 'pimpinan'), 403);
        $query = Bahan::with(['satuan', 'warna']);

        if ($request->jenis) {
            $query->where('jenis', $request->jenis);
        }

        if ($request->boolean('di_bawah_minimum')) {
            $query->whereRaw('stok <= stok_minimum');
        }

        return response()->json($query->orderBy('nama')->paginate($request->integer('per_page', 25)));
    }

    public function kartuStok(Request $request, int $bahanId)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang', 'pimpinan'), 403);
        $bahan = Bahan::findOrFail($bahanId);
        $query = StokMutasi::where('bahan_id', $bahanId)
            ->orderByDesc('tanggal')
            ->orderByDesc('id');

        if ($request->tgl_dari) {
            $query->whereDate('tanggal', '>=', $request->tgl_dari);
        }

        if ($request->tgl_sampai) {
            $query->whereDate('tanggal', '<=', $request->tgl_sampai);
        }

        return response()->json([
            'data' => [
                'bahan' => $bahan,
                'mutasi' => $query->paginate($request->integer('per_page', 25)),
            ],
        ]);
    }

    public function kekuranganBahan(Request $request)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang'), 403);
        $kebutuhan = WoBahan::whereHas('workOrder', function ($query) {
            $query->where('status', 'aktif')
                ->whereHas('order', fn ($orderQuery) => $orderQuery->where('status', 'menunggu_bahan'));
        })
            ->whereRaw('qty_rencana > qty_reservasi')
            ->select('bahan_id', DB::raw('SUM(qty_rencana - qty_reservasi) as total_kekurangan'))
            ->groupBy('bahan_id')
            ->get()
            ->keyBy('bahan_id');

        $poTransit = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->whereIn('po.status', ['dipesan', 'diterima_sebagian'])
            ->select('poi.bahan_id', DB::raw('SUM(poi.qty_pesan - poi.qty_diterima) as total_transit'))
            ->groupBy('poi.bahan_id')
            ->get()
            ->keyBy('bahan_id');

        $data = [];

        foreach ($kebutuhan as $bahanId => $kebutuhanBahan) {
            $transit = (float) ($poTransit[$bahanId]->total_transit ?? 0);
            $kekurangan = round((float) $kebutuhanBahan->total_kekurangan - $transit, 3);

            if ($kekurangan <= 0) {
                continue;
            }

            $bahan = Bahan::with('satuan')->findOrFail($bahanId);
            $data[] = [
                'bahan_id' => $bahan->id,
                'kode_bahan' => $bahan->kode,
                'nama_bahan' => $bahan->nama,
                'satuan' => $bahan->satuan?->nama,
                'total_kebutuhan_wo' => (float) $kebutuhanBahan->total_kekurangan,
                'po_dalam_perjalanan' => $transit,
                'kekurangan_final' => $kekurangan,
                'saran_qty_po' => $kekurangan,
            ];
        }

        return response()->json(['data' => $data]);
    }
}
