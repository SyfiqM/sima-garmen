<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Enums\OrderStatus;
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

        $statusValues = array_map(fn (OrderStatus $status) => $status->value, OrderStatus::cases());
        $filter = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', $statusValues)],
            'pelanggan_id' => ['nullable', 'integer', 'exists:pelanggan,id'],
            'prioritas' => ['nullable', 'in:normal,tinggi,urgent'],
            'tgl_dari' => ['nullable', 'date'],
            'tgl_sampai' => ['nullable', 'date', 'after_or_equal:tgl_dari'],
            'terlambat' => ['sometimes', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:created_at,-created_at,deadline,-deadline,tgl_order,-tgl_order,no_order,-no_order'],
        ]);

        $query = Order::query()->with('pelanggan');

        if (! empty($filter['search'])) {
            $search = trim($filter['search']);
            $query->where(function ($q) use ($search) {
                $q->where('no_order', 'like', "%{$search}%")
                    ->orWhereHas('pelanggan', fn ($customer) => $customer->where('nama', 'like', "%{$search}%"));
            });
        }

        $query
            ->when($filter['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filter['pelanggan_id'] ?? null, fn ($q, $value) => $q->where('pelanggan_id', $value))
            ->when($filter['prioritas'] ?? null, fn ($q, $value) => $q->where('prioritas', $value))
            ->when($filter['tgl_dari'] ?? null, fn ($q, $value) => $q->whereDate('tgl_order', '>=', $value))
            ->when($filter['tgl_sampai'] ?? null, fn ($q, $value) => $q->whereDate('tgl_order', '<=', $value));

        if (array_key_exists('terlambat', $filter)) {
            $hariIni = today()->toDateString();
            $query->where(function ($q) use ($filter, $hariIni) {
                if ((bool) $filter['terlambat']) {
                    $q->whereDate('deadline', '<', $hariIni)->where('status', '!=', OrderStatus::Terkirim->value);
                } else {
                    $q->whereDate('deadline', '>=', $hariIni)->orWhere('status', OrderStatus::Terkirim->value);
                }
            });
        }

        $sort = $filter['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        return response()->json(
            $query->orderBy($column, $direction)->orderBy('id', 'desc')->paginate((int) ($filter['per_page'] ?? 25))
        );
    }

    public function store(OrderRequest $request)
    {
        $data = $request->validated();
        $data['prioritas'] = $data['prioritas'] ?? 'normal';
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
