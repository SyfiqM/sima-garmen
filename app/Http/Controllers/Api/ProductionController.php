<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductionLogRequest;
use App\Models\WorkOrder;
use App\Services\ProductionService;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, ProductionService $service)
    {
        return response()->json(['data' => $service->antrean($request->user()->id)]);
    }

    public function logs(Request $request, ProductionService $service)
    {
        $workOrderId = $request->integer('wo_id') ?: null;

        return response()->json($service->riwayat($request->user()->id, $workOrderId));
    }

    public function store(ProductionLogRequest $request, ProductionService $service)
    {
        $data = $request->validated();
        $logIds = $service->catatProduksi(
            WorkOrder::findOrFail($data['wo_id']),
            $data['tahap_id'],
            $data['items'],
            $data['tanggal'],
            $request->user()->id,
        );

        return response()->json(['data' => ['log_ids' => $logIds]], 201);
    }

    public function voidLog(Request $request, int $id, ProductionService $service)
    {
        $alasan = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']])['alasan'];

        return response()->json(['data' => $service->voidLog($id, $alasan, $request->user()->id)]);
    }

    public function tutupTahap(Request $request, int $workOrderId, string $kode, ProductionService $service)
    {
        $data = $request->validate(['alasan' => ['nullable', 'string', 'min:5', 'max:255']]);

        return response()->json(['data' => $service->tutupTahap($workOrderId, $kode, $data['alasan'] ?? null, $request->user()->id)]);
    }

    public function bukaTahap(Request $request, int $workOrderId, string $kode, ProductionService $service)
    {
        $alasan = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']])['alasan'];

        return response()->json(['data' => $service->bukaTahap($workOrderId, $kode, $alasan, $request->user()->id)]);
    }

    public function tambahPotong(Request $request, ProductionService $service)
    {
        $data = $request->validate([
            'wo_item_id' => ['required', 'integer', 'exists:wo_items,id'],
            'qty' => ['required', 'integer', 'min:1'],
            'alasan' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        return response()->json(['data' => $service->tambahPotong($data['wo_item_id'], $data['qty'], $data['alasan'], $request->user()->id)]);
    }
}
