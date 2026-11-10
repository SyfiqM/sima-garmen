<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifikasi = $request->user()->notifications()->paginate(25);
        return response()->json($notifikasi);
    }

    public function unreadCount(Request $request)
    {
        return response()->json([
            'data' => ['count' => $request->user()->unreadNotifications()->count()]
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        return response()->json(null, 204);
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(null, 204);
    }
}