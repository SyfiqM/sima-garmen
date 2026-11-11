<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PenerimaanBahanRequest;
use App\Models\PenerimaanBahan;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PenerimaanController extends Controller
{
    protected $service;

    public function __construct(PurchaseService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('admin', 'gudang'), 403);
        $query = PenerimaanBahan::with('supplier');
        $query->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(PenerimaanBahanRequest $request)
    {
        $penerimaan = $this->service->terimaBahan($request->validated(), $request->user()->id);

        return response()->json(['data' => $penerimaan], 201);
    }

    public function show(Request $request, $id)
    {
        abort_unless($request->user()?->hasRole('admin', 'gudang'), 403);

        return response()->json(['data' => PenerimaanBahan::with(['supplier', 'items.bahan', 'po'])->findOrFail($id)]);
    }

    public function void(Request $request, int $id)
    {
        abort_unless($request->user()?->hasRole('admin', 'gudang'), 403);
        $alasan = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']])['alasan'];
        $penerimaan = $this->service->voidPenerimaan($id, $alasan, $request->user()->id);

        return response()->json(['data' => $penerimaan]);
    }
}
