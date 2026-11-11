<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PenerimaanBahan;
use App\Http\Requests\PenerimaanBahanRequest;
use App\Services\PurchaseService;

class PenerimaanController extends Controller
{
    protected $service;

    public function __construct(PurchaseService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $query = PenerimaanBahan::with('supplier');
        $query->orderBy('created_at', 'desc');
        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(PenerimaanBahanRequest $request)
    {
        $penerimaan = $this->service->terimaBahan($request->validated(), $request->user()->id);
        return response()->json(['data' => $penerimaan], 201);
    }

    public function show($id)
    {
        return response()->json(['data' => PenerimaanBahan::with(['supplier', 'items.bahan', 'purchaseOrder'])->findOrFail($id)]);
    }
}