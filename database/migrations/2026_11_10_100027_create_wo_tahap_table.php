<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wo_tahap', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wo_id')->constrained('work_orders');
            $table->foreignId('tahap_id')->constrained('tahap');
            $table->enum('status', ['menunggu', 'berjalan', 'selesai'])->default('menunggu');
            $table->dateTime('mulai_at')->nullable();
            $table->dateTime('selesai_at')->nullable();
            $table->foreignId('ditutup_oleh')->nullable()->constrained('users');
            $table->string('catatan_penutupan', 255)->nullable();
            $table->timestamps();
            $table->unique(['wo_id', 'tahap_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_tahap');
    }
};
