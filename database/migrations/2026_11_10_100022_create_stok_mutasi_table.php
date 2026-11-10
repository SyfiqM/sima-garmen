<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stok_mutasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bahan_id')->constrained('bahan');
            $table->dateTime('tanggal');
            $table->enum('tipe', ['masuk', 'keluar', 'retur', 'penyesuaian', 'koreksi']);
            $table->decimal('qty', 14, 3);
            $table->decimal('stok_sebelum', 14, 3);
            $table->decimal('stok_sesudah', 14, 3);
            $table->enum('ref_tipe', ['penerimaan', 'pengeluaran', 'stok_opname', 'manual', 'mutasi'])->default('manual');
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('keterangan', 255)->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
            $table->index(['bahan_id', 'tanggal']);
            $table->index(['ref_tipe', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stok_mutasi');
    }
};
