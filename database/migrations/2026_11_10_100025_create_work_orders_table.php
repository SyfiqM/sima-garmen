<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('no_wo', 20)->unique();
            $table->foreignId('order_id')->constrained('orders');
            $table->unsignedTinyInteger('revisi_ke')->default(0);
            $table->enum('status', ['aktif', 'selesai', 'ditutup_kurang', 'dihentikan', 'dibatalkan'])->default('aktif');
            $table->date('tgl_mulai')->nullable();
            $table->date('tgl_target');
            $table->date('tgl_selesai')->nullable();
            $table->dateTime('produksi_selesai_at')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
