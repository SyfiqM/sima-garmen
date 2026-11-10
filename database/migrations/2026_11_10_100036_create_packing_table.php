<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing', function (Blueprint $table) {
            $table->id();
            $table->string('no_koli', 20)->unique();
            $table->foreignId('wo_id')->constrained('work_orders');
            $table->unsignedSmallInteger('urutan_koli');
            $table->date('tanggal');
            $table->decimal('berat_kg', 8, 2)->nullable();
            $table->string('catatan', 255)->nullable();
            $table->enum('status', ['tersedia', 'dalam_pengiriman', 'terkirim', 'void'])->default('tersedia');
            $table->string('void_alasan', 255)->nullable();
            $table->foreignId('void_oleh')->nullable()->constrained('users');
            $table->dateTime('void_at')->nullable();
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing');
    }
};
