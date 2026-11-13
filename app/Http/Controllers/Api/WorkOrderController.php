<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use App\Support\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public function cetak(Request $request, int $id, WorkOrderService $service)
    {
        // Detail memakai scope yang sama dengan endpoint show, termasuk supervisor per tahap.
        $wo = $service->detail($request->user(), $id);
        $wo->loadMissing(['woBahan.bahan.satuan', 'woBahan.tahap', 'dibuatOleh']);

        $perusahaan = [
            'nama_perusahaan' => Setting::get('nama_perusahaan', 'SIMA Garmen'),
            'alamat_perusahaan' => Setting::get('alamat_perusahaan', ''),
            'telepon_perusahaan' => Setting::get('telepon_perusahaan', ''),
        ];

        return Pdf::loadView('pdf.work-order', compact('wo', 'perusahaan'))
            ->setPaper('a4', 'landscape')
            ->download("work-order-{$wo->no_wo}.pdf");
    }

    public function tutupKurang(Request $request, int $id, WorkOrderService $service): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']]);

        $wo = $service->tutupKurang(WorkOrder::findOrFail($id), $data['alasan'], $request->user()->id);

        return response()->json(['message' => 'Work Order ditutup kurang.', 'data' => $wo]);
    }
}