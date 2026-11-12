<?php

namespace App\Http\Controllers\Api;

use App\Exports\LaporanExport;
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class LaporanController extends Controller
{
    /** Daftar jenis laporan yang boleh dibuka user yang login. */
    public function index(Request $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->daftarUntuk($request->user())]);
    }

    /**
     * GET /laporan/{jenis}?format=json|pdf|xlsx&tgl_dari=&tgl_sampai=&...
     * JSON dipaginasi di memori (page, per_page); PDF dan Excel memuat seluruh baris (dengan batas).
     */
    public function show(Request $request, string $jenis, ReportService $service)
    {
        $data = $request->validate([
            'format' => ['nullable', 'in:json,pdf,xlsx'],
            'tgl_dari' => ['nullable', 'date'],
            'tgl_sampai' => ['nullable', 'date', 'after_or_equal:tgl_dari'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'status' => ['nullable', 'string', 'max:30'],
            'pelanggan_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'bahan_id' => ['nullable', 'integer'],
            'produk_id' => ['nullable', 'integer'],
            'tahap_id' => ['nullable', 'integer'],
            'line_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'aksi' => ['nullable', 'string', 'max:60'],
            'subjek_tipe' => ['nullable', 'string', 'max:80'],
            'jenis_bahan' => ['nullable', 'in:kain,benang,aksesoris,kemasan'],
            'di_bawah_minimum' => ['nullable', 'boolean'],
            'kelompok' => ['nullable', 'in:tahap,produk,line,jenis_cacat'],
        ]);

        $laporan = $service->laporan($request->user(), $jenis, $data);
        $format = $data['format'] ?? 'json';

        if ($format === 'json') {
            $perPage = (int) ($data['per_page'] ?? 50);
            $page = (int) ($data['page'] ?? 1);
            $total = count($laporan['baris']);
            $baris = array_slice($laporan['baris'], ($page - 1) * $perPage, $perPage);

            return response()->json([
                'data' => [
                    'jenis' => $laporan['jenis'],
                    'judul' => $laporan['judul'],
                    'periode' => $laporan['periode'],
                    'kolom' => array_map(fn (array $k) => ['key' => $k['key'], 'label' => $k['label'], 'tipe' => $k['tipe']], $laporan['kolom']),
                    'ringkasan' => $laporan['ringkasan'],
                    'baris' => $baris,
                ],
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'terpotong' => $laporan['terpotong'],
                ],
            ]);
        }

        $nama = 'laporan-'.$jenis.($laporan['periode'] ? '-'.$laporan['periode']['tgl_dari'].'_'.$laporan['periode']['tgl_sampai'] : '-'.now()->format('Y-m-d'));

        if ($format === 'xlsx') {
            $tabel = $service->tabel($laporan, false);

            return Excel::download(
                new LaporanExport($laporan['judul'], $tabel['kepala'], $tabel['baris'], $tabel['ringkasan']),
                $nama.'.xlsx'
            );
        }

        $tabel = $service->tabel($laporan, true);
        $total = count($tabel['baris']);
        $dipotong = $total > ReportService::MAKS_BARIS_PDF;
        if ($dipotong) {
            $tabel['baris'] = array_slice($tabel['baris'], 0, ReportService::MAKS_BARIS_PDF);
        }

        return Pdf::loadView('pdf.laporan', [
            'laporan' => $laporan,
            'tabel' => $tabel,
            'total_baris' => $total,
            'dipotong' => $dipotong,
            'maks_baris_pdf' => ReportService::MAKS_BARIS_PDF,
            'perusahaan' => [
                'nama_perusahaan' => Setting::get('nama_perusahaan', 'SIMA Garmen'),
                'alamat_perusahaan' => Setting::get('alamat_perusahaan', ''),
                'telepon_perusahaan' => Setting::get('telepon_perusahaan', ''),
            ],
            'dibuat_oleh' => $request->user()->name,
        ])->setPaper('a4', 'landscape')->download($nama.'.pdf');
    }
}
