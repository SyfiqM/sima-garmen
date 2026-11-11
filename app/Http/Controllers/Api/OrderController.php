<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Http\Requests\OrderRequest;
use App\Services\OrderService;
use App\Exceptions\BusinessRuleException; // Tambahan untuk 3C

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
        $query = Order::with('pelanggan');
        
        if ($request->status) $query->where('status', $request->status);
        if ($request->pelanggan_id) $query->where('pelanggan_id', $request->pelanggan_id);
        if ($request->prioritas) $query->where('prioritas', $request->prioritas);
        
        $query->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(OrderRequest $request)
    {
        $order = $this->service->store($request->validated(), $request->user()->id);
        return response()->json(['data' => $order], 201);
    }

    public function show($id)
    {
        $order = Order::with(['pelanggan', 'items.produk', 'items.warna', 'items.ukuran', 'lampiran'])->findOrFail($id);
        return response()->json(['data' => $order]);
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

        if (in_array($aksi, $aksiManajerAtauAdmin) && !$user->hasRole('admin', 'manajer')) {
            throw new BusinessRuleException('Anda tidak memiliki hak untuk melakukan aksi ini.', 'TIDAK_ADA_HAK', 403);
        }

        $order = $this->service->ubahStatus($id, $aksi, $request->all(), $user->id);
        return response()->json(['data' => $order, 'message' => "Order berhasil di-{$aksi}."]);
    }
}