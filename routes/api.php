<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SystemController;

Route::prefix('v1')->group(function () {
    // Endpoint Publik
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/enum', [SystemController::class, 'enums']);
    Route::get('/pengaturan-umum', [SystemController::class, 'pengaturanUmum']);

    // Endpoint Terproteksi
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        
        Route::get('/me', [ProfileController::class, 'me']);
        Route::put('/me/profil', [ProfileController::class, 'updateProfil']);
        Route::put('/me/password', [ProfileController::class, 'updatePassword']);

        Route::get('/notifikasi', [NotificationController::class, 'index']);
        Route::get('/notifikasi/belum-dibaca', [NotificationController::class, 'unreadCount']);
        Route::post('/notifikasi/{id}/baca', [NotificationController::class, 'markAsRead']);
        Route::post('/notifikasi/baca-semua', [NotificationController::class, 'markAllAsRead']);

        // Dropdown Opsi
        Route::get('/opsi/{nama}', [App\Http\Controllers\Api\OptionController::class, 'getOpsi']);

        // Master Bahan & Produk
        Route::apiResource('bahan', \App\Http\Controllers\Api\BahanController::class);
        Route::post('bahan/{id}/aktifkan', [\App\Http\Controllers\Api\BahanController::class, 'aktifkan']);
        Route::post('bahan/{id}/nonaktifkan', [\App\Http\Controllers\Api\BahanController::class, 'nonaktifkan']);

        Route::apiResource('produk', \App\Http\Controllers\Api\ProdukController::class);
        Route::post('produk/{id}/aktifkan', [\App\Http\Controllers\Api\ProdukController::class, 'aktifkan']);
        Route::post('produk/{id}/nonaktifkan', [\App\Http\Controllers\Api\ProdukController::class, 'nonaktifkan']);

        // BOM Produk
        Route::get('produk/{produk_id}/bom', [\App\Http\Controllers\Api\BomController::class, 'index']);
        Route::post('produk/{produk_id}/bom', [\App\Http\Controllers\Api\BomController::class, 'store']);
        Route::put('bom/{id}', [\App\Http\Controllers\Api\BomController::class, 'update']);
        Route::delete('bom/{id}', [\App\Http\Controllers\Api\BomController::class, 'destroy']);
        Route::post('produk/{produk_id}/bom/salin-dari/{produk_asal_id}', [\App\Http\Controllers\Api\BomController::class, 'salinDari']);

        // Pengguna (Khusus Admin)
        Route::apiResource('pengguna', \App\Http\Controllers\Api\UserController::class);
        Route::post('pengguna/{id}/reset-password', [\App\Http\Controllers\Api\UserController::class, 'resetPassword']);

        // Pengaturan (Khusus Admin)
        Route::get('pengaturan', [\App\Http\Controllers\Api\PengaturanController::class, 'index']);
        Route::put('pengaturan', [\App\Http\Controllers\Api\PengaturanController::class, 'updateMassal']);

        // Audit Log (Admin & Manajer)
        Route::get('audit-log', [\App\Http\Controllers\Api\AuditLogController::class, 'index']);

        // Order (Tahap 1 CRUD)
        Route::apiResource('order', \App\Http\Controllers\Api\OrderController::class);

        // Order (Tahap 1 CRUD)
        Route::apiResource('order', \App\Http\Controllers\Api\OrderController::class);
        
        // Order (Tahap 3C - Transisi Status Bisnis)
        Route::post('order/{id}/ajukan', [\App\Http\Controllers\Api\OrderController::class, 'aksiBisnis'])->defaults('aksi', 'ajukan');
        Route::post('order/{id}/tarik', [\App\Http\Controllers\Api\OrderController::class, 'aksiBisnis'])->defaults('aksi', 'tarik');
        Route::post('order/{id}/tolak', [\App\Http\Controllers\Api\OrderController::class, 'aksiBisnis'])->defaults('aksi', 'tolak');
        Route::post('order/{id}/perbaiki', [\App\Http\Controllers\Api\OrderController::class, 'aksiBisnis'])->defaults('aksi', 'perbaiki');
        Route::post('order/{id}/setujui', [\App\Http\Controllers\Api\OrderController::class, 'aksiBisnis'])->defaults('aksi', 'setujui');
        Route::post('order/{id}/revisi', [\App\Http\Controllers\Api\OrderController::class, 'aksiBisnis'])->defaults('aksi', 'revisi');
        Route::post('order/{id}/batalkan', [\App\Http\Controllers\Api\OrderController::class, 'aksiBisnis'])->defaults('aksi', 'batalkan');
        Route::post('order/{id}/hentikan', [\App\Http\Controllers\Api\OrderController::class, 'aksiBisnis'])->defaults('aksi', 'hentikan');

        // Stok, Kartu Stok, dan Kekurangan Bahan (Tahap 4A)
        Route::get('stok', [\App\Http\Controllers\Api\StockController::class, 'index']);
        Route::get('bahan/{id}/kartu-stok', [\App\Http\Controllers\Api\StockController::class, 'kartuStok']);
        Route::get('kekurangan-bahan', [\App\Http\Controllers\Api\StockController::class, 'kekuranganBahan']);

        // Pembelian & Penerimaan (Tahap 4B)
        Route::apiResource('po', \App\Http\Controllers\Api\PoController::class)->except(['update', 'destroy']);
        Route::post('po/{id}/pesan', [\App\Http\Controllers\Api\PoController::class, 'pesan']);
        
        Route::apiResource('penerimaan', \App\Http\Controllers\Api\PenerimaanController::class)->only(['index', 'store', 'show']);

        // Stok Opname (Tahap 4C)
        Route::apiResource('opname', \App\Http\Controllers\Api\OpnameController::class)->only(['index', 'store', 'show']);
        Route::post('opname/{id}/ajukan', [\App\Http\Controllers\Api\OpnameController::class, 'aksiBisnis'])->defaults('aksi', 'ajukan');
        Route::post('opname/{id}/tolak', [\App\Http\Controllers\Api\OpnameController::class, 'aksiBisnis'])->defaults('aksi', 'tolak');
        Route::post('opname/{id}/setujui', [\App\Http\Controllers\Api\OpnameController::class, 'aksiBisnis'])->defaults('aksi', 'setujui');

        $masterUmum = ['pelanggan', 'supplier', 'ukuran', 'warna', 'satuan', 'jenis-cacat', 'line-produksi', 'tahap'];
        Route::prefix('{tipe_master}')->whereIn('tipe_master', $masterUmum)->group(function () {
            Route::get('/', [App\Http\Controllers\Api\MasterController::class, 'index']);
            Route::post('/', [App\Http\Controllers\Api\MasterController::class, 'store']);
            Route::get('/{id}', [App\Http\Controllers\Api\MasterController::class, 'show']);
            Route::put('/{id}', [App\Http\Controllers\Api\MasterController::class, 'update']);
            Route::delete('/{id}', [App\Http\Controllers\Api\MasterController::class, 'destroy']);
            Route::post('/{id}/aktifkan', [App\Http\Controllers\Api\MasterController::class, 'aktifkan']);
            Route::post('/{id}/nonaktifkan', [App\Http\Controllers\Api\MasterController::class, 'nonaktifkan']);
        });
    });
});