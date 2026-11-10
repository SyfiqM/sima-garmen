<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_inspeksi', function (Blueprint $table) {
            $table->id();
            $table->string('no_qc', 20)->unique();
            $table->foreignId('wo_id')->constrained('work_orders');
            $table->date('tanggal');
            $table->text('catatan')->nullable();
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->string('void_alasan', 255)->nullable();
            $table->foreignId('void_oleh')->nullable()->constrained('users');
            $table->dateTime('void_at')->nullable();
            $table->foreignId('qc_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspeksi');
    }
};
