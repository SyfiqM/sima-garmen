<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Menu;
use App\Enums\DocStatus;
use App\Enums\JenisBahan;
use App\Enums\MutasiTipe;
use App\Enums\OpnameStatus;
use App\Enums\OrderStatus;
use App\Enums\PackingStatus;
use App\Enums\PengeluaranTipe;
use App\Enums\PengirimanStatus;
use App\Enums\PoStatus;
use App\Enums\Prioritas;
use App\Enums\RepairStatus;
use App\Enums\Role;
use App\Enums\TahapKode;
use App\Enums\TindakanCacat;
use App\Enums\WoStatus;
use App\Enums\WoTahapStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function menu(Request $request)
    {
        $role = $request->user()->role->value;

        return response()->json(['data' => Menu::getForRole($role)]);
    }

    public function enums()
    {
        return response()->json([
            'data' => [
                'roles' => Role::options(),
                'order_status' => OrderStatus::options(),
                'prioritas' => Prioritas::options(),
                'jenis_bahan' => JenisBahan::options(),
                'tahap' => TahapKode::options(),
                'work_order_status' => WoStatus::options(),
                'work_order_tahap_status' => WoTahapStatus::options(),
                'purchase_order_status' => PoStatus::options(),
                'pengeluaran_tipe' => PengeluaranTipe::options(),
                'mutasi_tipe' => MutasiTipe::options(),
                'dokumen_status' => DocStatus::options(),
                'opname_status' => OpnameStatus::options(),
                'packing_status' => PackingStatus::options(),
                'pengiriman_status' => PengirimanStatus::options(),
                'repair_status' => RepairStatus::options(),
                'tindakan_cacat' => TindakanCacat::options(),
            ],
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
