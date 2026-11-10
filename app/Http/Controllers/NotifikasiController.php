<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotifikasiController extends Controller
{
    public function index(Request $request): View
    {
        $notifs = $request->user()->notifications()->paginate(20);

        return view('notifikasi.index', compact('notifs'));
    }

    /** Tandai satu notifikasi dibaca lalu buka tautannya (hanya tautan internal). */
    public function baca(Request $request, string $id): RedirectResponse
    {
        $notif = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notif->markAsRead();

        $url = $notif->data['url'] ?? null;

        if (is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return redirect($url);
        }

        return redirect()->route('notifikasi.index');
    }

    public function bacaSemua(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
