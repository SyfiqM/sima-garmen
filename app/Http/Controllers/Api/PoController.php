<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PurchaseOrder;
use App\Http\Requests\PurchaseOrderRequest;
use App\Services\PurchaseService;

class PoController extends Controller
{
    protected $service;

    public function __construct(PurchaseService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $query = PurchaseOrder::with('supplier');
        if ($request->status) $query->where('status', $request->status);
        $query->orderBy('created_at', 'desc');
        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(PurchaseOrderRequest $request)
    {
        $po = $this->service->storePo($request->validated(), $request->user()->id);
        return response()->json(['data' => $po], 201);
    }

    public function show($id)
    {
        return response()->json(['data' => PurchaseOrder::with(['supplier', 'items.bahan'])->findOrFail($id)]);
    }

    public function pesan(Request $request, $id)
    {
        $po = $this->service->pesanPo($id, $request->user()->id);
        return response()->json(['data' => $po, 'message' => 'PO berhasil dipesan.']);
    }
}