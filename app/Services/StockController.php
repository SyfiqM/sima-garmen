<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Bahan;
use App\Models\StokMutasi;
use App\Models\WoBahan;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    // Melihat daftar stok bahan (Bisa difilter jenis dan status di bawah minimum)
    public function index(Request $request)
    {
        $query = Bahan::with(['satuan', 'warna']);

        if ($request->jenis) {
            $query->where('jenis', $request->jenis);
        }

        if ($request->di_bawah_minimum === 'true') {
            $query->whereRaw('stok <= stok_minimum');
        }

        $query->orderBy('nama', 'asc');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    // Kartu Stok: Riwayat mutasi per bahan
    public function kartuStok(Request $request, $bahanId)
    {
        $bahan = Bahan::findOrFail($bahanId);
        
        $query = StokMutasi::where('bahan_id', $bahanId)
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc');

        if ($request->tgl_dari) {
            $query->whereDate('tanggal', '>=', $request->tgl_dari);
        }
        if ($request->tgl_sampai) {
            $query->whereDate('tanggal', '<=', $request->tgl_sampai);
        }

        $mutasi = $query->paginate($request->per_page ?? 25);

        return response()->json([
            'bahan' => $bahan,
            'mutasi' => $mutasi
        ]);
    }

    // Hitung agregat kekurangan bahan untuk disarankan menjadi PO
    public function kekuranganBahan()
    {
        // Kebutuhan = sum(rencana - reservasi) pada WO aktif
        $kebutuhan = WoBahan::whereHas('workOrder', function($q) {
                $q->where('status', 'aktif');
            })
            ->whereRaw('qty_rencana > qty_reservasi')
            ->select('bahan_id', DB::raw('SUM(qty_rencana - qty_reservasi) as total_kekurangan'))
            ->groupBy('bahan_id')
            ->get()
            ->keyBy('bahan_id');

        // PO dalam perjalanan = (qty_pesan - qty_diterima) dari PO dipesan/sebagian
        $poTransit = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->whereIn('po.status', ['dipesan', 'diterima_sebagian'])
            ->select('poi.bahan_id', DB::raw('SUM(poi.qty_pesan - poi.qty_diterima) as total_transit'))
            ->groupBy('poi.bahan_id')
            ->get()
            ->keyBy('bahan_id');

        $hasil = [];
        foreach ($kebutuhan as $bahanId => $keb) {
            $transit = $poTransit->has($bahanId) ? (float) $poTransit[$bahanId]->total_transit : 0;
            $kekuranganMurni = round((float) $keb->total_kekurangan - $transit, 3);
            
            if ($kekuranganMurni > 0) {
                $bahan = Bahan::with('satuan')->find($bahanId);
                $hasil[] = [
                    'bahan_id' => $bahanId,
                    'kode_bahan' => $bahan->kode,
                    'nama_bahan' => $bahan->nama,
                    'satuan' => $bahan->satuan->nama ?? '',
                    'total_kebutuhan_wo' => (float) $keb->total_kekurangan,
                    'po_dalam_perjalanan' => $transit,
                    'kekurangan_final' => $kekuranganMurni,
                    'saran_qty_po' => $kekuranganMurni
                ];
            }
        }

        return response()->json(['data' => $hasil]);
    }
}