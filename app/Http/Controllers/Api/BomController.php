<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BomRequest;
use App\Models\BOM;
use App\Models\Produk;
use App\Services\BomService;
use Illuminate\Http\Request;

class BomController extends Controller
{
    protected $service;

    public function __construct(BomService $service)
    {
        $this->service = $service;
    }

    public function index($produkId)
    {
        abort_unless(request()->user()?->hasRole('admin', 'manajer', 'pimpinan'), 403);
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
        abort_unless(request()->user()?->hasRole('admin', 'manajer'), 403);
        BOM::findOrFail($bomId)->delete();

        return response()->json(null, 204);
    }

    public function salinDari(Request $request, $produkId, $produkAsalId)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer'), 403);
        Produk::findOrFail($produkId);
        $this->service->copyFrom($produkId, $produkAsalId);

        return response()->json(['message' => 'BOM berhasil disalin.']);
    }

    public function pratinjau(Request $request, int $produkId)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'pimpinan'), 403);
        $data = $request->validate([
            'ukuran' => ['required', 'integer', 'exists:ukuran,id'],
            'warna' => ['required', 'integer', 'exists:warna,id'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);

        Produk::findOrFail($produkId);

        return response()->json(['data' => $this->service->pratinjau($produkId, $data['ukuran'], $data['warna'], $data['qty'])]);
    }
}
