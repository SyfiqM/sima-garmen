<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Bahan;
use App\Models\Order;
use App\Models\Pelanggan;
use App\Models\Produk;
use App\Support\Menu;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Dashboard sementara (Batch 1). Dashboard lengkap per role dibangun di Batch 8. */
    public function index(Request $request): View
    {
        $aktif = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::aktif());

        $stat = [
            'Order aktif' => Order::whereIn('status', $aktif)->count(),
            'Order terlambat' => Order::whereIn('status', $aktif)->whereDate('deadline', '<', today())->count(),
            'Pelanggan' => Pelanggan::aktif()->count(),
            'Produk' => Produk::aktif()->count(),
            'Bahan' => Bahan::aktif()->count(),
            'Bahan di bawah minimum' => Bahan::aktif()
                ->where('stok_minimum', '>', 0)
                ->whereRaw('(stok - stok_dipesan) <= stok_minimum')
                ->count(),
        ];

        return view('dashboard', [
            'stat' => $stat,
            'menu' => Menu::for($request->user()),
        ]);
    }
}
