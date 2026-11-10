<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengeluaran_bahan', function (Blueprint $table) {
            $table->id();
            $table->string('no_pb', 20)->unique();
            $table->foreignId('wo_id')->constrained('work_orders');
            $table->foreignId('tahap_id')->constrained('tahap');
            $table->enum('tipe', ['keluar', 'retur']);
            $table->date('tanggal');
            $table->text('catatan')->nullable();
            $table->string('alasan_lebih', 255)->nullable();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users');
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->string('void_alasan', 255)->nullable();
            $table->foreignId('void_oleh')->nullable()->constrained('users');
            $table->dateTime('void_at')->nullable();
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengeluaran_bahan');
    }
};
