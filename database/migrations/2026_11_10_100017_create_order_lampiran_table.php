<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_lampiran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('nama_file', 255);
            $table->string('path', 500);
            $table->string('mime', 100);
            $table->unsignedInteger('ukuran_kb');
            $table->string('keterangan', 255)->nullable();
            $table->foreignId('diunggah_oleh')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lampiran');
    }
};
