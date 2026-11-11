<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\PengaturanRequest;
use Illuminate\Support\Facades\DB;
use App\Helpers\Audit;

class PengaturanController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->hasRole('admin')) abort(403);
        $pengaturan = DB::table('pengaturan')->get();
        return response()->json(['data' => $pengaturan]);
    }

    public function updateMassal(PengaturanRequest $request)
    {
        $data = $request->validated();
        $sebelum = DB::table('pengaturan')->whereIn('key', array_keys($data))->pluck('value', 'key')->toArray();

        DB::transaction(function () use ($data) {
            foreach ($data as $key => $value) {
                if ($value !== null) {
                    DB::table('pengaturan')->where('key', $key)->update(['value' => $value]);
                }
            }
        });

        if (class_exists(Audit::class)) {
            Audit::log($request->user()->id, 'pengaturan.update', 'Pengaturan', 0, $sebelum, $data, 'Update pengaturan massal');
        }

        return response()->json(['message' => 'Pengaturan berhasil diperbarui.']);
    }
}