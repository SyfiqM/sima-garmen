<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\MasterDataService;
use App\Http\Requests\MasterRequest;

class MasterController extends Controller
{
    protected $service;

    public function __construct(MasterDataService $service)
    {
        $this->service = $service;
    }

    private function getModelClass($tipe)
    {
        $map = [
            'pelanggan' => \App\Models\Pelanggan::class,
            'supplier' => \App\Models\Supplier::class,
            'ukuran' => \App\Models\Ukuran::class,
            'warna' => \App\Models\Warna::class,
            'satuan' => \App\Models\Satuan::class,
            'jenis-cacat' => \App\Models\JenisCacat::class,
            'line-produksi' => \App\Models\LineProduksi::class,
            'tahap' => \App\Models\Tahap::class,
        ];
        return $map[$tipe] ?? null;
    }

    public function index(Request $request, $tipe)
    {
        $modelClass = $this->getModelClass($tipe);
        if (!$modelClass) abort(404, 'Tipe master tidak valid.');

        $query = $modelClass::query();
        
        if ($request->has('search')) {
            $query->where('nama', 'like', '%' . $request->search . '%');
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(MasterRequest $request, $tipe)
    {
        $modelClass = $this->getModelClass($tipe);
        if (!$modelClass) abort(404);

        $model = $this->service->store($modelClass, $request->validated(), $request->user()->id);
        return response()->json(['data' => $model], 201);
    }

    public function show($tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        if (!$modelClass) abort(404);

        return response()->json(['data' => $modelClass::findOrFail($id)]);
    }

    public function update(MasterRequest $request, $tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        if (!$modelClass) abort(404);

        $model = $modelClass::findOrFail($id);
        $model = $this->service->update($model, $request->validated(), $request->user()->id);
        return response()->json(['data' => $model]);
    }

    public function destroy(Request $request, $tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        if (!$modelClass) abort(404);

        $model = $modelClass::findOrFail($id);
        $this->service->destroy($model, $request->user()->id);
        
        return response()->json(null, 204);
    }

    public function aktifkan(Request $request, $tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        $model = $modelClass::findOrFail($id);
        $this->service->setStatus($model, true, $request->user()->id);
        return response()->json(['message' => 'Data diaktifkan.']);
    }

    public function nonaktifkan(Request $request, $tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        $model = $modelClass::findOrFail($id);
        $this->service->setStatus($model, false, $request->user()->id);
        return response()->json(['message' => 'Data dinonaktifkan.']);
    }
}