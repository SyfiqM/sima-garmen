<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QcInspeksiRequest;
use App\Models\WorkOrder;
use App\Services\QcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QcController extends Controller
{
    public function antrean(Request $request, QcService $service): JsonResponse
    {
        return response()->json(['data' => $service->antrean($request->user()->id)]);
    }

    public function index(Request $request, QcService $service): JsonResponse
    {
        $request->validate([
            'wo_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:aktif,void'],
            'tgl_dari' => ['nullable', 'date'],
            'tgl_sampai' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($service->riwayat(
            $request->user()->id,
            $request->only(['wo_id', 'status', 'tgl_dari', 'tgl_sampai']),
            $request->integer('per_page', 25),
        ));
    }

    public function store(QcInspeksiRequest $request, QcService $service): JsonResponse
    {
        $data = $request->validated();

        $qc = $service->catatInspeksi(
            WorkOrder::findOrFail($data['wo_id']),
            $data['items'],
            $data['tanggal'],
            $request->user()->id,
            $data['catatan'] ?? null,
        );

        return response()->json(['message' => 'Inspeksi QC berhasil dicatat.', 'data' => $qc], 201);
    }

    public function void(Request $request, int $id, QcService $service): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']]);

        $qc = $service->voidInspeksi($id, $data['alasan'], $request->user()->id);

        return response()->json(['message' => 'Inspeksi QC berhasil di-void.', 'data' => $qc]);
    }
}