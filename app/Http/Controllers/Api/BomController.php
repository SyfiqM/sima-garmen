<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BOM;
use App\Models\Produk;
use App\Services\BomService;
use App\Http\Requests\BomRequest;

class BomController extends Controller
{
    protected $service;

    public function __construct(BomService $service) {
        $this->service = $service;
    }

    public function index($produkId)
    {
        $boms = BOM::with(['bahan', 'tahap', 'ukuran', 'warna'])
                    ->where('produk_id', $produkId)
                    ->get();
        return response()->json(['data' => $boms]);
    }

    public function store(BomRequest $request, $produkId)
    {
        Produk::findOrFail($produkId);
        $bom = $this->service->store($produkId, $request->validated());
        return response()->json(['data' => $bom], 201);
    }

    public function update(BomRequest $request, $bomId)
    {
        $bom = $this->service->update($bomId, $request->validated());
        return response()->json(['data' => $bom]);
    }

    public function destroy($bomId)
    {
        BOM::findOrFail($bomId)->delete();
        return response()->json(null, 204);
    }

    public function salinDari(Request $request, $produkId, $produkAsalId)
    {
        Produk::findOrFail($produkId);
        $this->service->copyFrom($produkId, $produkAsalId);
        return response()->json(['message' => 'BOM berhasil disalin.']);
    }
}