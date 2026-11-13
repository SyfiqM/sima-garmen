<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    public function index(Request $request, WorkOrderService $service): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:aktif,selesai,ditutup_kurang,dibatalkan,dihentikan'],
            'order_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($service->daftar(
            $request->user(),
            $request->only(['status', 'order_id', 'search']),
            $request->integer('per_page', 25),
        ));
    }

    public function show(Request $request, int $id, WorkOrderService $service): JsonResponse
    {
        return response()->json(['data' => $service->detail($request->user(), $id)]);
    }

    public function tutupKurang(Request $request, int $id, WorkOrderService $service): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']]);

        $wo = $service->tutupKurang(WorkOrder::findOrFail($id), $data['alasan'], $request->user()->id);

        return response()->json(['message' => 'Work Order ditutup kurang.', 'data' => $wo]);
    }
}