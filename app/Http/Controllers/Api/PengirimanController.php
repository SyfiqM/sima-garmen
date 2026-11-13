<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PengirimanDiterimaRequest;
use App\Http\Requests\PengirimanRequest;
use App\Services\ShipmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PengirimanController extends Controller
{
    public function index(Request $request, ShipmentService $service): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:disiapkan,dikirim,diterima,dibatalkan'],
            'order_id' => ['nullable', 'integer'],
            'tgl_dari' => ['nullable', 'date'],
            'tgl_sampai' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($service->daftar(
            $request->user()->id,
            $request->only(['status', 'order_id', 'tgl_dari', 'tgl_sampai']),
            $request->integer('per_page', 25),
        ));
    }

    public function show(Request $request, int $id, ShipmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->detail($id, $request->user()->id)]);
    }

    public function koliTersedia(Request $request, int $id, ShipmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->koliTersedia($id, $request->user()->id)]);
    }

    public function store(PengirimanRequest $request, ShipmentService $service): JsonResponse
    {
        $pengiriman = $service->buat($request->validated(), $request->user()->id);

        return response()->json(['message' => 'Surat jalan berhasil dibuat.', 'data' => $pengiriman], 201);
    }

    public function kirim(Request $request, int $id, ShipmentService $service): JsonResponse
    {
        $data = $request->validate(['tgl_kirim' => ['nullable', 'date', 'before_or_equal:today']]);

        $pengiriman = $service->kirim($id, $data['tgl_kirim'] ?? null, $request->user()->id);

        return response()->json(['message' => 'Pengiriman dikirim.', 'data' => $pengiriman]);
    }

    public function diterima(PengirimanDiterimaRequest $request, int $id, ShipmentService $service): JsonResponse
    {
        $data = $request->validated();

        $pengiriman = $service->diterima(
            $id,
            $data['tgl_diterima'] ?? null,
            $request->file('bukti'),
            $data['catatan'] ?? null,
            $request->user()->id,
        );

        return response()->json(['message' => 'Penerimaan dikonfirmasi.', 'data' => $pengiriman]);
    }

    public function batal(Request $request, int $id, ShipmentService $service): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']]);

        $pengiriman = $service->batal($id, $data['alasan'], $request->user()->id);

        return response()->json(['message' => 'Pengiriman dibatalkan.', 'data' => $pengiriman]);
    }

    public function cetak(Request $request, int $id, ShipmentService $service)
    {
        $pengiriman = $service->detail($id, $request->user()->id);

        $perusahaan = DB::table('pengaturan')
            ->whereIn('key', ['nama_perusahaan', 'alamat_perusahaan', 'telepon_perusahaan'])
            ->pluck('value', 'key');

        return Pdf::loadView('pdf.surat-jalan', [
            'pengiriman' => $pengiriman,
            'ringkasan' => $pengiriman->ringkasan_item,
            'perusahaan' => $perusahaan,
        ])->setPaper('a4')->download("surat-jalan-{$pengiriman->no_sj}.pdf");
    }

    public function bukti(Request $request, int $id, ShipmentService $service)
    {
        [$path, $nama] = $service->pathBukti($id, $request->user()->id);

        return response()->download($path, $nama);
    }
}