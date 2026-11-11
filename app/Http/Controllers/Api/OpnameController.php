<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StokOpnameRequest;
use App\Models\StokOpname;
use App\Services\OpnameService;
use Illuminate\Http\Request;

class OpnameController extends Controller
{
    protected $service;

    public function __construct(OpnameService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang'), 403);
        $query = StokOpname::with('pembuat');
        if ($request->status) {
            $query->where('status', $request->status);
        }
        $query->orderBy('created_at', 'desc');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(StokOpnameRequest $request)
    {
        $opname = $this->service->store($request->validated(), $request->user()->id);

        return response()->json(['data' => $opname], 201);
    }

    public function show(Request $request, $id)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang'), 403);

        return response()->json(['data' => StokOpname::with(['items.bahan', 'pembuat', 'penyetuju'])->findOrFail($id)]);
    }

    public function aksiBisnis(Request $request, $id, $aksi)
    {
        $user = $request->user();

        // Hanya manajer/admin yang bisa menyetujui/menolak
        if (in_array($aksi, ['setujui', 'tolak']) && ! $user->hasRole('admin', 'manajer')) {
            throw new BusinessRuleException('Anda tidak memiliki hak untuk melakukan persetujuan opname.', 'TIDAK_ADA_HAK', 403);
        }
        if ($aksi === 'ajukan' && ! $user->hasRole('admin', 'gudang')) {
            throw new BusinessRuleException('Hanya Gudang atau Admin yang dapat mengajukan opname.', 'TIDAK_ADA_HAK', 403);
        }

        switch ($aksi) {
            case 'ajukan':
                $opname = $this->service->ajukan($id, $user->id);
                break;
            case 'tolak':
                $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']]);
                $opname = $this->service->tolak($id, $data, $user->id);
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
