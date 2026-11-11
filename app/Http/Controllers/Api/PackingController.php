<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PackingRequest;
use App\Models\Packing;
use App\Models\WorkOrder;
use App\Services\PackingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackingController extends Controller
{
    public function index(Request $request, PackingService $service): JsonResponse
    {
        $request->validate([
            'wo_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:tersedia,dalam_pengiriman,terkirim,void'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($service->daftar(
            $request->user()->id,
            $request->only(['wo_id', 'order_id', 'status']),
            $request->integer('per_page', 25),
        ));
    }

    public function show(Request $request, int $id, PackingService $service): JsonResponse
    {
        return response()->json(['data' => $service->detail($id, $request->user()->id)]);
    }

    public function store(PackingRequest $request, PackingService $service): JsonResponse
    {
        $data = $request->validated();

        $packing = $service->buatKoli(
            WorkOrder::findOrFail($data['wo_id']),
            $data['items'],
            $data['tanggal'],
            isset($data['berat_kg']) ? (float) $data['berat_kg'] : null,
            $request->user()->id,
            $data['catatan'] ?? null,
        );

        return response()->json(['message' => 'Koli berhasil dibuat.', 'data' => $packing], 201);
    }

    public function void(Request $request, int $id, PackingService $service): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']]);

        $packing = $service->voidKoli($id, $data['alasan'], $request->user()->id);

        return response()->json(['message' => 'Koli berhasil di-void.', 'data' => $packing]);
    }

    public function label(Request $request, int $id, PackingService $service)
    {
        $packing = $service->detail($id, $request->user()->id);
        $totalKoli = Packing::where('wo_id', $packing->wo_id)->where('status', '!=', 'void')->count();

        return Pdf::loadView('pdf.label-koli', ['packing' => $packing, 'totalKoli' => $totalKoli])
            ->setPaper('a6', 'landscape')
            ->download("label-{$packing->no_koli}.pdf");
    }
}