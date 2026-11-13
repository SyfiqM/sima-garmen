<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderAttachmentRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrderAttachmentController extends Controller
{
    public function index(Request $request, int $orderId)
    {
        $order = $this->findOrder($request, $orderId, false);

        return response()->json(['data' => $order->lampiran()->latest('id')->get()]);
    }

    public function store(OrderAttachmentRequest $request, int $orderId)
    {
        $order = Order::findOrFail($orderId);
        $file = $request->file('file');
        $path = $file->store("orders/{$order->id}/attachments", 'local');
        $lampiran = $order->lampiran()->create([
            'nama_file' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'ukuran_kb' => (int) ceil($file->getSize() / 1024),
            'keterangan' => $request->validated('keterangan'),
            'diunggah_oleh' => $request->user()->id,
        ]);

        return response()->json(['data' => $lampiran], 201);
    }

    public function unduh(Request $request, int $orderId, int $lampiranId)
    {
        $order = $this->findOrder($request, $orderId, false);
        $lampiran = $order->lampiran()->findOrFail($lampiranId);
        abort_unless(Storage::disk('local')->exists($lampiran->path), 404);

        return response()->download(Storage::disk('local')->path($lampiran->path), $lampiran->nama_file);
    }

    public function destroy(Request $request, int $orderId, int $lampiranId)
    {
        $order = $this->findOrder($request, $orderId, true);
        $lampiran = $order->lampiran()->findOrFail($lampiranId);
        Storage::disk('local')->delete($lampiran->path);
        $lampiran->delete();

        return response()->json(null, 204);
    }

    private function findOrder(Request $request, int $orderId, bool $write): Order
    {
        $user = $request->user();
        $order = Order::findOrFail($orderId);
        $allowed = $write
            ? ($user->hasRole('admin', 'manajer') || ($user->hasRole('staf_order') && (int) $order->dibuat_oleh === (int) $user->id))
            : $user->hasRole('admin', 'manajer', 'staf_order', 'gudang', 'qc', 'pimpinan');
        abort_unless($allowed, 403);

        return $order;
    }
}
