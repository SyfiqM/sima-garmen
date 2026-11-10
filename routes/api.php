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
    });
});