<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->hasRole('admin', 'manajer')) abort(403);

        $query = DB::table('activity_log')
                    ->leftJoin('users', 'activity_log.user_id', '=', 'users.id')
                    ->select('activity_log.*', 'users.name as user_name');

        if ($request->user_id) $query->where('activity_log.user_id', $request->user_id);
        if ($request->aksi) $query->where('activity_log.aksi', $request->aksi);
        if ($request->subjek_tipe) $query->where('activity_log.subjek_tipe', $request->subjek_tipe);
        if ($request->tgl_dari) $query->whereDate('activity_log.created_at', '>=', $request->tgl_dari);
        if ($request->tgl_sampai) $query->whereDate('activity_log.created_at', '<=', $request->tgl_sampai);

        $query->orderBy('activity_log.created_at', 'desc');

        return response()->json($query->paginate($request->per_page ?? 25));
    }
}