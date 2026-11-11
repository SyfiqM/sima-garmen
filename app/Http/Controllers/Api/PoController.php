<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PoController extends Controller
{
    protected $service;

    public function __construct(PurchaseService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang', 'pimpinan'), 403);
        $query = PurchaseOrder::with('supplier');
        if ($request->status) {
            $query->where('status', $request->status);
        }
        $query->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(PurchaseOrderRequest $request)
    {
        $po = $this->service->storePo($request->validated(), $request->user()->id);

        return response()->json(['data' => $po], 201);
    }

    public function dariKekurangan(Request $request)
    {
        abort_unless($request->user()?->hasRole('admin', 'gudang'), 403);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:supplier,id'],
            'tgl_po' => ['required', 'date'],
        ]);
        $po = $this->service->buatPoDariKekurangan($data['supplier_id'], $data['tgl_po'], $request->user()->id);
        return response()->json(['data' => $po], 201);
    }

    public function update(PurchaseOrderRequest $request, int $id)
    {
        $po = $this->service->updatePo($id, $request->validated(), $request->user()->id);

        return response()->json(['data' => $po]);
    }

    public function destroy(Request $request, int $id)
    {
        abort_unless($request->user()?->hasRole('admin', 'gudang'), 403);
        $this->service->destroyPo($id, $request->user()->id);

        return response()->json(null, 204);
    }

    public function show(Request $request, $id)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang', 'pimpinan'), 403);

        return response()->json(['data' => PurchaseOrder::with(['supplier', 'items.bahan'])->findOrFail($id)]);
    }

    public function pesan(Request $request, $id)
    {
        abort_unless($request->user()?->hasRole('admin', 'gudang'), 403);
        $po = $this->service->pesanPo($id, $request->user()->id);

        return response()->json(['data' => $po, 'message' => 'PO berhasil dipesan.']);
    }

    public function batalkan(Request $request, int $id)
    {
        abort_unless($request->user()?->hasRole('admin', 'gudang'), 403);
        $alasan = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']])['alasan'];
        $po = $this->service->batalkanPo($id, $alasan, $request->user()->id);

        return response()->json(['data' => $po]);
    }
}
