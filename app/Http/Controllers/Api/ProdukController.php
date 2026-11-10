<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Produk;
use App\Services\MasterDataService;
use App\Http\Requests\ProdukRequest;

class ProdukController extends Controller
{
    protected $service;

    public function __construct(MasterDataService $service) {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $query = Produk::query();
        if ($request->search) $query->where('nama', 'like', '%' . $request->search . '%');
        if ($request->has('is_active')) $query->where('is_active', $request->boolean('is_active'));
        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(ProdukRequest $request)
    {
        $model = $this->service->store(Produk::class, $request->validated(), $request->user()->id);
        return response()->json(['data' => $model], 201);
    }

    public function show($id) { return response()->json(['data' => Produk::findOrFail($id)]); }

    public function update(ProdukRequest $request, $id)
    {
        $model = Produk::findOrFail($id);
        $model = $this->service->update($model, $request->validated(), $request->user()->id);
        return response()->json(['data' => $model]);
    }

    public function destroy(Request $request, $id)
    {
        $this->service->destroy(Produk::findOrFail($id), $request->user()->id);
        return response()->json(null, 204);
    }

    public function aktifkan(Request $request, $id)
    {
        $this->service->setStatus(Produk::findOrFail($id), true, $request->user()->id);
        return response()->json(['message' => 'Data diaktifkan.']);
    }

    public function nonaktifkan(Request $request, $id)
    {
        $this->service->setStatus(Produk::findOrFail($id), false, $request->user()->id);
        return response()->json(['message' => 'Data dinonaktifkan.']);
    }
}