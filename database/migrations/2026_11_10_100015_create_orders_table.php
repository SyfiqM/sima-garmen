<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('no_order', 20)->unique();
            $table->unsignedTinyInteger('revisi_ke')->default(0);
            $table->foreignId('pelanggan_id')->constrained('pelanggan');
            $table->string('no_po_pelanggan', 50)->nullable();
            $table->date('tgl_order');
            $table->date('deadline');
            $table->enum('prioritas', ['normal', 'tinggi', 'urgent'])->default('normal');
            $table->enum('status', ['draft', 'diajukan', 'ditolak', 'menunggu_bahan', 'siap_produksi', 'produksi', 'siap_kirim', 'dikirim_sebagian', 'terkirim', 'dibatalkan', 'dihentikan'])->default('draft');
            $table->text('alamat_kirim')->nullable();
            $table->text('catatan')->nullable();
            $table->unsignedInteger('total_qty')->default(0);
            $table->decimal('total_nilai', 16, 2)->nullable();
            $table->string('alasan_penolakan', 255)->nullable();
            $table->string('alasan_pembatalan', 255)->nullable();
            $table->boolean('ditutup_kurang')->default(false);
            $table->string('alasan_tutup_kurang', 255)->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users');
            $table->dateTime('diajukan_at')->nullable();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users');
            $table->dateTime('disetujui_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'deadline']);
            $table->index('prioritas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
