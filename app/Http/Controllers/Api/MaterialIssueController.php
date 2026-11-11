<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaterialIssueRequest;
use App\Models\PengeluaranBahan;
use App\Services\MaterialIssueService;
use Illuminate\Http\Request;

class MaterialIssueController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang', 'supervisor'), 403);

        $query = PengeluaranBahan::with(['wo.order', 'tahap', 'pengeluaranBahanItems.bahan'])
            ->latest('id');

        if ($request->user()->hasRole('supervisor')) {
            $query->where('tahap_id', $request->user()->tahap_id);
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MaterialIssueRequest $request, MaterialIssueService $service)
    {
        $dokumen = $service->store($request->validated(), $request->user()->id);

        return response()->json(['data' => $dokumen], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $id)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer', 'gudang', 'supervisor'), 403);
        $query = PengeluaranBahan::with(['wo.order', 'tahap', 'pengeluaranBahanItems.bahan']);
        if ($request->user()->hasRole('supervisor')) {
            $query->where('tahap_id', $request->user()->tahap_id);
        }

        return response()->json(['data' => $query->findOrFail($id)]);
    }

    public function bahanWo(Request $request, MaterialIssueService $service)
    {
        abort_unless($request->user()?->hasRole('admin', 'gudang', 'supervisor'), 403);
        $validated = $request->validate([
            'wo_id' => ['required', 'integer', 'exists:work_orders,id'],
            'tahap_id' => ['required', 'integer', 'exists:tahap,id'],
        ]);

        if ($request->user()->hasRole('supervisor') && (int) $request->user()->tahap_id !== (int) $validated['tahap_id']) {
            abort(403);
        }

        return response()->json(['data' => $service->bahanUntukWo($validated['wo_id'], $validated['tahap_id'])]);
    }

    public function setujui(Request $request, int $id, MaterialIssueService $service)
    {
        abort_unless($request->user()?->hasRole('admin', 'manajer'), 403);

        return response()->json(['data' => $service->setujui($id, $request->user()->id)]);
    }

    public function void(Request $request, int $id, MaterialIssueService $service)
    {
        $alasan = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']])['alasan'];

        return response()->json(['data' => $service->void($id, $alasan, $request->user()->id)]);
    }
}
