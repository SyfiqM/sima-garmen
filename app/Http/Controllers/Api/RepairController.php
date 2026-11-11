<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RepairController extends Controller
{
    public function index(Request $request, QcService $service): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:open,selesai'],
            'tahap_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($service->daftarRepair(
            $request->user()->id,
            $request->only(['status', 'tahap_id']),
            $request->integer('per_page', 25),
        ));
    }

    public function selesai(Request $request, int $id, QcService $service): JsonResponse
    {
        $data = $request->validate([
            'qty_selesai' => ['required', 'integer', 'min:1'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);

        $repair = $service->selesaikanRepair($id, (int) $data['qty_selesai'], $request->user()->id, $data['catatan'] ?? null);

        return response()->json(['message' => 'Repair dicatat selesai.', 'data' => $repair]);
    }
}