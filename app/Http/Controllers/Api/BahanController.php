<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Bahan;
use App\Services\MasterDataService;
use App\Http\Requests\BahanRequest;

class BahanController extends Controller
{
    protected $service;

    public function __construct(MasterDataService $service) {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $query = Bahan::with(['satuan', 'warna', 'supplier']);
        if ($request->search) $query->where('nama', 'like', '%' . $request->search . '%');
        if ($request->has('is_active')) $query->where('is_active', $request->boolean('is_active'));
        
        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(BahanRequest $request)
    {
        $model = $this->service->store(Bahan::class, $request->validated(), $request->user()->id);
        return response()->json(['data' => $model], 201);
    }

    public function show($id) { return response()->json(['data' => Bahan::with(['satuan', 'warna', 'supplier'])->findOrFail($id)]); }

    public function update(BahanRequest $request, $id)
    {
        $model = Bahan::findOrFail($id);
        $model = $this->service->update($model, $request->validated(), $request->user()->id);
        return response()->json(['data' => $model]);
    }

    public function destroy(Request $request, $id)
    {
        $this->service->destroy(Bahan::findOrFail($id), $request->user()->id);
        return response()->json(null, 204);
    }

    public function aktifkan(Request $request, $id)
    {
        $this->service->setStatus(Bahan::findOrFail($id), true, $request->user()->id);
        return response()->json(['message' => 'Data diaktifkan.']);
    }

    public function nonaktifkan(Request $request, $id)
    {
        $this->service->setStatus(Bahan::findOrFail($id), false, $request->user()->id);
        return response()->json(['message' => 'Data dinonaktifkan.']);
    }
}