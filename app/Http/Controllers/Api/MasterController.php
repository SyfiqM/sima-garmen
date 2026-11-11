<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterRequest;
use App\Models\JenisCacat;
use App\Models\LineProduksi;
use App\Models\Pelanggan;
use App\Models\Satuan;
use App\Models\Supplier;
use App\Models\Tahap;
use App\Models\Ukuran;
use App\Models\Warna;
use App\Services\MasterDataService;
use Illuminate\Http\Request;

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
            'pelanggan' => Pelanggan::class,
            'supplier' => Supplier::class,
            'ukuran' => Ukuran::class,
            'warna' => Warna::class,
            'satuan' => Satuan::class,
            'jenis-cacat' => JenisCacat::class,
            'line-produksi' => LineProduksi::class,
            'tahap' => Tahap::class,
        ];

        return $map[$tipe] ?? null;
    }

    public function index(Request $request, $tipe)
    {
        $modelClass = $this->getModelClass($tipe);
        if (! $modelClass) {
            abort(404, 'Tipe master tidak valid.');
        }
        $this->authorizeMaster($request, $tipe);

        $query = $modelClass::query();

        if ($request->has('search')) {
            $query->where('nama', 'like', '%'.$request->search.'%');
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(MasterRequest $request, $tipe)
    {
        $modelClass = $this->getModelClass($tipe);
        if (! $modelClass) {
            abort(404);
        }
        $this->authorizeMaster($request, $tipe, true);
        abort_if($tipe === 'tahap', 422, 'Tahap bersifat tetap dan tidak dapat ditambah.');

        $model = $this->service->store($modelClass, $request->validated(), $request->user()->id);

        return response()->json(['data' => $model], 201);
    }

    public function show($tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        if (! $modelClass) {
            abort(404);
        }
        $this->authorizeMaster(request(), $tipe);

        return response()->json(['data' => $modelClass::findOrFail($id)]);
    }

    public function update(MasterRequest $request, $tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        if (! $modelClass) {
            abort(404);
        }
        $this->authorizeMaster($request, $tipe, true);

        $model = $modelClass::findOrFail($id);
        $model = $this->service->update($model, $request->validated(), $request->user()->id);

        return response()->json(['data' => $model]);
    }

    public function destroy(Request $request, $tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        if (! $modelClass) {
            abort(404);
        }
        $this->authorizeMaster($request, $tipe, true);
        abort_if($tipe === 'tahap', 422, 'Tahap bersifat tetap dan tidak dapat dihapus.');

        $model = $modelClass::findOrFail($id);
        $this->service->destroy($model, $request->user()->id);

        return response()->json(null, 204);
    }

    public function aktifkan(Request $request, $tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        if (! $modelClass) {
            abort(404);
        }
        $this->authorizeMaster($request, $tipe, true);
        abort_if($tipe === 'tahap', 422, 'Tahap tidak dapat diaktifkan atau dinonaktifkan.');
        $model = $modelClass::findOrFail($id);
        $this->service->setStatus($model, true, $request->user()->id);

        return response()->json(['message' => 'Data diaktifkan.']);
    }

    public function nonaktifkan(Request $request, $tipe, $id)
    {
        $modelClass = $this->getModelClass($tipe);
        if (! $modelClass) {
            abort(404);
        }
        $this->authorizeMaster($request, $tipe, true);
        abort_if($tipe === 'tahap', 422, 'Tahap tidak dapat diaktifkan atau dinonaktifkan.');
        $model = $modelClass::findOrFail($id);
        $this->service->setStatus($model, false, $request->user()->id);

        return response()->json(['message' => 'Data dinonaktifkan.']);
    }

    private function authorizeMaster(Request $request, string $tipe, bool $write = false): void
    {
        $roles = $write ? match ($tipe) {
            'pelanggan' => ['admin', 'manajer', 'staf_order'],
            'supplier' => ['admin', 'manajer', 'gudang'],
            'ukuran', 'warna', 'satuan', 'jenis-cacat', 'line-produksi', 'tahap' => ['admin', 'manajer'],
            default => [],
        } : match ($tipe) {
            'pelanggan' => ['admin', 'manajer', 'staf_order', 'pimpinan'],
            'supplier' => ['admin', 'manajer', 'gudang', 'pimpinan'],
            'ukuran', 'warna', 'satuan' => ['admin', 'manajer', 'staf_order', 'gudang', 'supervisor', 'qc', 'pimpinan'],
            'jenis-cacat', 'line-produksi', 'tahap' => ['admin', 'manajer', 'supervisor', 'qc', 'pimpinan'],
            default => [],
        };

        abort_unless($request->user()?->hasRole(...$roles), 403);
    }
}
