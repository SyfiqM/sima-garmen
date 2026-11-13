<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Models\Bahan;
use App\Models\Order;
use App\Services\BomService;
use App\Services\OrderService; // Tambahan untuk 3C
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    protected $service;

    public function __construct(OrderService $service)
    {
        $this->service = $service;
    }

    // =================================================================
    // KODE LAMA DARI TAHAP 3A
    // =================================================================

    public function index(Request $request)
    {
        $this->authorizeRead($request);
        $query = Order::with('pelanggan');

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->pelanggan_id) {
            $query->where('pelanggan_id', $request->pelanggan_id);
        }
        if ($request->prioritas) {
            $query->where('prioritas', $request->prioritas);
        }

        $query->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(OrderRequest $request)
    {
        $order = $this->service->store($request->validated(), $request->user()->id);

        return response()->json(['data' => $order], 201);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeRead($request);
        $order = Order::with(['pelanggan', 'items.produk', 'items.warna', 'items.ukuran', 'lampiran'])->findOrFail($id);

        return response()->json(['data' => $order]);
    }

    public function simulasiBahan(Request $request, int $id, BomService $bomService)
    {
        $this->authorizeRead($request);
        $order = Order::with('items')->findOrFail($id);
        $data = collect($bomService->hitungKebutuhan($order))->map(function (array $kebutuhan) {
            $bahan = Bahan::with('satuan')->findOrFail($kebutuhan['bahan_id']);
            $tersedia = (float) $bahan->stok - (float) $bahan->stok_dipesan;

            return [
                'bahan_id' => $bahan->id,
                'kode' => $bahan->kode,
                'nama' => $bahan->nama,
                'satuan' => $bahan->satuan?->nama,
                'tahap_id' => $kebutuhan['tahap_id'],
                'qty_rencana' => $kebutuhan['qty_rencana'],
                'stok_tersedia' => round($tersedia, 3),
                'kekurangan' => max(0, round($kebutuhan['qty_rencana'] - $tersedia, 3)),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function riwayat(Request $request, int $id)
    {
        $this->authorizeRead($request);
        Order::findOrFail($id);
        $data = DB::table('activity_log')
            ->where('subjek_tipe', 'Order')
            ->where('subjek_id', $id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($data);
    }

    public function cetak(Request $request, int $id)
    {
        $this->authorizeRead($request);
        $order = Order::with(['pelanggan', 'items.produk', 'items.warna', 'items.ukuran'])->findOrFail($id);

        return Pdf::loadView('pdf.order', ['order' => $order])->download("lembar-order-{$order->no_order}.pdf");
    }

    public function update(OrderRequest $request, $id)
    {
        $order = $this->service->update($id, $request->validated(), $request->user()->id);

        return response()->json(['data' => $order]);
    }

    public function destroy(Request $request, $id)
    {
        $this->service->destroy($id, $request->user()->id);

        return response()->json(null, 204);
    }

    // =================================================================
    // KODE BARU TAHAP 3C: ENDPOINT TRANSISI STATUS
    // =================================================================

    public function aksiBisnis(Request $request, $id, $aksi)
    {
        // Validasi Otorisasi
        $user = $request->user();
        $aksiManajerAtauAdmin = ['tolak', 'setujui', 'revisi', 'batalkan', 'hentikan'];

        if (in_array($aksi, $aksiManajerAtauAdmin) && ! $user->hasRole('admin', 'manajer')) {
            throw new BusinessRuleException('Anda tidak memiliki hak untuk melakukan aksi ini.', 'TIDAK_ADA_HAK', 403);
        }

        $requiresReason = in_array($aksi, ['tolak', 'revisi', 'batalkan', 'hentikan'], true);
        $data = $request->validate([
            'alasan' => [$requiresReason ? 'required' : 'nullable', 'string', 'min:5', 'max:255'],
        ]);

        $order = $this->service->ubahStatus($id, $aksi, $data, $user->id);

        return response()->json(['data' => $order, 'message' => "Order berhasil di-{$aksi}."]);
    }

    public function deadline(Request $request, int $id)
    {
        $data = $request->validate([
            'deadline' => ['required', 'date'],
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $order = $this->service->ubahDeadline($id, $data['deadline'], $data['alasan'], $request->user()->id);
        return response()->json(['data' => $order]);
    }

    public function prioritas(Request $request, int $id)
    {
        $data = $request->validate([
            'prioritas' => ['required', 'in:normal,tinggi,urgent'],
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $order = $this->service->ubahPrioritas($id, $data['prioritas'], $data['alasan'], $request->user()->id);
        return response()->json(['data' => $order]);
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'staf_order', 'gudang', 'qc', 'pimpinan'), 403);
    }
}
