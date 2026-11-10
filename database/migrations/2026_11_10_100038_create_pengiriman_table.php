<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengiriman', function (Blueprint $table) {
            $table->id();
            $table->string('no_sj', 20)->unique();
            $table->foreignId('order_id')->constrained('orders');
            $table->date('tgl_kirim')->nullable();
            $table->string('ekspedisi', 100)->nullable();
            $table->string('no_resi', 60)->nullable();
            $table->string('no_kendaraan', 20)->nullable();
            $table->string('nama_sopir', 100)->nullable();
            $table->string('nama_penerima', 100)->nullable();
            $table->text('alamat_tujuan')->nullable();
            $table->enum('status', ['disiapkan', 'dikirim', 'diterima', 'dibatalkan'])->default('disiapkan');
            $table->date('tgl_diterima')->nullable();
            $table->string('bukti_terima_path', 500)->nullable();
            $table->string('alasan_batal', 255)->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengiriman');
    }
};
