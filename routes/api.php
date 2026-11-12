<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BahanController;
use App\Http\Controllers\Api\BomController;
use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\MaterialIssueController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OpnameController;
use App\Http\Controllers\Api\OptionController;
use App\Http\Controllers\Api\OrderAttachmentController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PackingController;
use App\Http\Controllers\Api\PenerimaanController;
use App\Http\Controllers\Api\PengirimanController;
use App\Http\Controllers\Api\PengaturanController;
use App\Http\Controllers\Api\PoController;
use App\Http\Controllers\Api\ProductionController;
use App\Http\Controllers\Api\ProdukController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\QcController;
use App\Http\Controllers\Api\RepairController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Endpoint Publik
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/enum', [SystemController::class, 'enums']);
    Route::get('/pengaturan-umum', [SystemController::class, 'pengaturanUmum']);

    // Endpoint Terproteksi
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/menu', [SystemController::class, 'menu']);

        Route::get('/me', [ProfileController::class, 'me']);
        Route::put('/me/profil', [ProfileController::class, 'updateProfil']);
        Route::put('/me/password', [ProfileController::class, 'updatePassword']);

        Route::get('/notifikasi', [NotificationController::class, 'index']);
        Route::get('/notifikasi/belum-dibaca', [NotificationController::class, 'unreadCount']);
        Route::post('/notifikasi/{id}/baca', [NotificationController::class, 'markAsRead']);
        Route::post('/notifikasi/baca-semua', [NotificationController::class, 'markAllAsRead']);

        // Dropdown Opsi
        Route::get('/opsi/{nama}', [OptionController::class, 'getOpsi']);

        // Master Bahan & Produk
        Route::apiResource('bahan', BahanController::class);
        Route::post('bahan/{id}/aktifkan', [BahanController::class, 'aktifkan']);
        Route::post('bahan/{id}/nonaktifkan', [BahanController::class, 'nonaktifkan']);

        Route::apiResource('produk', ProdukController::class);
        Route::post('produk/{id}/aktifkan', [ProdukController::class, 'aktifkan']);
        Route::post('produk/{id}/nonaktifkan', [ProdukController::class, 'nonaktifkan']);

        // BOM Produk
        Route::get('produk/{produk_id}/bom', [BomController::class, 'index']);
        Route::post('produk/{produk_id}/bom', [BomController::class, 'store']);
        Route::put('bom/{id}', [BomController::class, 'update']);
        Route::delete('bom/{id}', [BomController::class, 'destroy']);
        Route::post('produk/{produk_id}/bom/salin-dari/{produk_asal_id}', [BomController::class, 'salinDari']);
        Route::get('produk/{produk_id}/bom/pratinjau', [BomController::class, 'pratinjau']);

        // Pengguna (Khusus Admin)
        Route::apiResource('pengguna', UserController::class);
        Route::post('pengguna/{id}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('pengguna/{id}/aktifkan', [UserController::class, 'aktifkan']);
        Route::post('pengguna/{id}/nonaktifkan', [UserController::class, 'nonaktifkan']);

        // Pengaturan (Khusus Admin)
        Route::get('pengaturan', [PengaturanController::class, 'index']);
        Route::put('pengaturan', [PengaturanController::class, 'updateMassal']);

        // Audit Log (Admin & Manajer)
        Route::get('audit-log', [AuditLogController::class, 'index']);

        // Order (Tahap 1 CRUD)
        Route::apiResource('order', OrderController::class);

        Route::get('order/{id}/simulasi-bahan', [OrderController::class, 'simulasiBahan']);
        Route::get('order/{id}/riwayat', [OrderController::class, 'riwayat']);
        Route::get('order/{id}/cetak', [OrderController::class, 'cetak']);
        Route::get('order/{order}/lampiran', [OrderAttachmentController::class, 'index']);
        Route::post('order/{order}/lampiran', [OrderAttachmentController::class, 'store']);
        Route::get('order/{order}/lampiran/{lampiran}/unduh', [OrderAttachmentController::class, 'unduh']);
        Route::delete('order/{order}/lampiran/{lampiran}', [OrderAttachmentController::class, 'destroy']);

        // Order (Tahap 3C - Transisi Status Bisnis)
        Route::post('order/{id}/ajukan', [OrderController::class, 'aksiBisnis'])->defaults('aksi', 'ajukan');
        Route::post('order/{id}/tarik', [OrderController::class, 'aksiBisnis'])->defaults('aksi', 'tarik');
        Route::post('order/{id}/tolak', [OrderController::class, 'aksiBisnis'])->defaults('aksi', 'tolak');
        Route::post('order/{id}/perbaiki', [OrderController::class, 'aksiBisnis'])->defaults('aksi', 'perbaiki');
        Route::post('order/{id}/setujui', [OrderController::class, 'aksiBisnis'])->defaults('aksi', 'setujui');
        Route::post('order/{id}/revisi', [OrderController::class, 'aksiBisnis'])->defaults('aksi', 'revisi');
        Route::post('order/{id}/batalkan', [OrderController::class, 'aksiBisnis'])->defaults('aksi', 'batalkan');
        Route::post('order/{id}/hentikan', [OrderController::class, 'aksiBisnis'])->defaults('aksi', 'hentikan');
        Route::put('order/{id}/deadline', [OrderController::class, 'deadline']);
        Route::put('order/{id}/prioritas', [OrderController::class, 'prioritas']);

        // Stok, Kartu Stok, dan Kekurangan Bahan (Tahap 4A)
        Route::get('stok', [StockController::class, 'index']);
        Route::get('bahan/{id}/kartu-stok', [StockController::class, 'kartuStok']);
        Route::get('kekurangan-bahan', [StockController::class, 'kekuranganBahan']);

        Route::get('pengeluaran-bahan/bahan-wo', [MaterialIssueController::class, 'bahanWo']);
        Route::apiResource('pengeluaran-bahan', MaterialIssueController::class)->only(['index', 'store', 'show']);
        Route::post('pengeluaran-bahan/{id}/setujui', [MaterialIssueController::class, 'setujui']);
        Route::post('pengeluaran-bahan/{id}/void', [MaterialIssueController::class, 'void']);

        Route::get('produksi/antrean', [ProductionController::class, 'index']);
        Route::get('produksi/log', [ProductionController::class, 'logs']);
        Route::post('produksi/log', [ProductionController::class, 'store']);
        Route::post('produksi/log/{id}/void', [ProductionController::class, 'voidLog']);
        Route::post('work-order/{workOrderId}/tahap/{kode}/tutup', [ProductionController::class, 'tutupTahap']);
        Route::post('work-order/{workOrderId}/tahap/{kode}/buka', [ProductionController::class, 'bukaTahap']);
        Route::post('work-order/{workOrderId}/tambah-potong', [ProductionController::class, 'tambahPotong']);

        // Pembelian & Penerimaan (Tahap 4B)
        Route::apiResource('po', PoController::class);
        Route::post('po/dari-kekurangan', [PoController::class, 'dariKekurangan']);
        Route::post('po/{id}/pesan', [PoController::class, 'pesan']);
        Route::post('po/{id}/batalkan', [PoController::class, 'batalkan']);

        Route::apiResource('penerimaan', PenerimaanController::class)->only(['index', 'store', 'show']);
        Route::post('penerimaan/{id}/void', [PenerimaanController::class, 'void']);

        // Stok Opname (Tahap 4C)
        Route::apiResource('opname', OpnameController::class)->only(['index', 'store', 'show']);
        Route::post('opname/{id}/ajukan', [OpnameController::class, 'aksiBisnis'])->defaults('aksi', 'ajukan');
        Route::post('opname/{id}/tolak', [OpnameController::class, 'aksiBisnis'])->defaults('aksi', 'tolak');
        Route::post('opname/{id}/setujui', [OpnameController::class, 'aksiBisnis'])->defaults('aksi', 'setujui');

        // Batch 6 - QC, repair, packing, work order
        Route::get('qc/antrean', [QcController::class, 'antrean']);
        Route::get('qc/inspeksi', [QcController::class, 'index']);
        Route::post('qc/inspeksi', [QcController::class, 'store']);
        Route::post('qc/inspeksi/{id}/void', [QcController::class, 'void']);

        Route::get('repair', [RepairController::class, 'index']);
        Route::post('repair/{id}/selesai', [RepairController::class, 'selesai']);

        Route::get('packing', [PackingController::class, 'index']);
        Route::post('packing', [PackingController::class, 'store']);
        Route::get('packing/{id}', [PackingController::class, 'show']);
        Route::post('packing/{id}/void', [PackingController::class, 'void']);
        Route::get('packing/{id}/label', [PackingController::class, 'label']);

        Route::get('work-order', [WorkOrderController::class, 'index']);
        Route::get('work-order/{id}', [WorkOrderController::class, 'show'])->whereNumber('id');
        Route::post('work-order/{id}/tutup-kurang', [WorkOrderController::class, 'tutupKurang']);


        // Batch 7 - pengiriman dan surat jalan
        Route::get('order/{id}/koli-tersedia', [PengirimanController::class, 'koliTersedia'])->whereNumber('id');
        Route::get('pengiriman', [PengirimanController::class, 'index']);
        Route::post('pengiriman', [PengirimanController::class, 'store']);
        Route::get('pengiriman/{id}', [PengirimanController::class, 'show'])->whereNumber('id');
        Route::post('pengiriman/{id}/kirim', [PengirimanController::class, 'kirim']);
        Route::post('pengiriman/{id}/diterima', [PengirimanController::class, 'diterima']);
        Route::post('pengiriman/{id}/batal', [PengirimanController::class, 'batal']);
        Route::get('pengiriman/{id}/cetak', [PengirimanController::class, 'cetak']);
        Route::get('pengiriman/{id}/bukti', [PengirimanController::class, 'bukti']);

        $masterUmum = ['pelanggan', 'supplier', 'ukuran', 'warna', 'satuan', 'jenis-cacat', 'line-produksi', 'tahap'];
        Route::prefix('{tipe_master}')->whereIn('tipe_master', $masterUmum)->group(function () {
            Route::get('/', [MasterController::class, 'index']);
            Route::post('/', [MasterController::class, 'store']);
            Route::get('/{id}', [MasterController::class, 'show']);
            Route::put('/{id}', [MasterController::class, 'update']);
            Route::delete('/{id}', [MasterController::class, 'destroy']);
            Route::post('/{id}/aktifkan', [MasterController::class, 'aktifkan']);
            Route::post('/{id}/nonaktifkan', [MasterController::class, 'nonaktifkan']);
        });
    });
});