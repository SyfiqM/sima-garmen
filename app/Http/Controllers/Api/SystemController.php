<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function enums()
    {
        // Data struktur enum (contoh dasar untuk Frontend)
        return response()->json([
            'data' => [
                'roles' => [
                    ['value' => 'admin', 'label' => 'Admin'],
                    ['value' => 'manajer', 'label' => 'Manajer Produksi'],
                    ['value' => 'staf_order', 'label' => 'Staf Order'],
                    ['value' => 'gudang', 'label' => 'Staf Gudang'],
                    ['value' => 'supervisor', 'label' => 'Supervisor Produksi'],
                    ['value' => 'qc', 'label' => 'Quality Control'],
                    ['value' => 'pimpinan', 'label' => 'Pimpinan'],
                ],
                'order_status' => [
                    ['value' => 'draft', 'label' => 'Draft', 'color' => 'gray'],
                    ['value' => 'diajukan', 'label' => 'Diajukan', 'color' => 'yellow'],
                    ['value' => 'menunggu_bahan', 'label' => 'Menunggu Bahan', 'color' => 'orange'],
                    ['value' => 'siap_produksi', 'label' => 'Siap Produksi', 'color' => 'blue'],
                    ['value' => 'produksi', 'label' => 'Produksi', 'color' => 'indigo'],
                    ['value' => 'siap_kirim', 'label' => 'Siap Kirim', 'color' => 'teal'],
                    ['value' => 'dikirim_sebagian', 'label' => 'Dikirim Sebagian', 'color' => 'cyan'],
                    ['value' => 'terkirim', 'label' => 'Terkirim', 'color' => 'green'],
                    ['value' => 'dibatalkan', 'label' => 'Dibatalkan', 'color' => 'red'],
                ]
            ]
        ]);
    }

    public function pengaturanUmum()
    {
        // Mengembalikan pengaturan non-sensitif untuk UI (nama PT, logo, dll)
        $pengaturan = DB::table('pengaturan')
            ->whereIn('key', ['nama_perusahaan', 'alamat_perusahaan', 'logo_path'])
            ->pluck('value', 'key');
            
        return response()->json(['data' => $pengaturan]);
    }
}