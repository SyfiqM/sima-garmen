<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\StokOpname;
use App\Http\Requests\StokOpnameRequest;
use App\Services\OpnameService;
use App\Exceptions\BusinessRuleException;

class OpnameController extends Controller
{
    protected $service;

    public function __construct(OpnameService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $query = StokOpname::with('pembuat');
        if ($request->status) $query->where('status', $request->status);
        $query->orderBy('created_at', 'desc');
        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(StokOpnameRequest $request)
    {
        $opname = $this->service->store($request->validated(), $request->user()->id);
        return response()->json(['data' => $opname], 201);
    }

    public function show($id)
    {
        return response()->json(['data' => StokOpname::with(['items.bahan', 'pembuat', 'penyetuju'])->findOrFail($id)]);
    }

    public function aksiBisnis(Request $request, $id, $aksi)
    {
        $user = $request->user();

        // Hanya manajer/admin yang bisa menyetujui/menolak
        if (in_array($aksi, ['setujui', 'tolak']) && !$user->hasRole('admin', 'manajer')) {
            throw new BusinessRuleException('Anda tidak memiliki hak untuk melakukan persetujuan opname.', 'TIDAK_ADA_HAK', 403);
        }

        switch ($aksi) {
            case 'ajukan':
                $opname = $this->service->ajukan($id, $user->id);
                break;
            case 'tolak':
                $opname = $this->service->tolak($id, $request->all(), $user->id);
                break;
            case 'setujui':
                $opname = $this->service->setujui($id, $user->id);
                break;
            default:
                return response()->json(['message' => 'Aksi tidak valid'], 400);
        }

        return response()->json(['data' => $opname, 'message' => "Opname berhasil di-{$aksi}."]);
    }
}