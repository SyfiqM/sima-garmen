<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_repair', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_inspeksi_item_id')->constrained('qc_inspeksi_items');
            $table->foreignId('wo_item_id')->constrained('wo_items');
            $table->unsignedInteger('qty');
            $table->foreignId('tahap_tujuan_id')->constrained('tahap');
            $table->enum('status', ['open', 'selesai'])->default('open');
            $table->unsignedInteger('qty_selesai')->default(0);
            $table->dateTime('selesai_at')->nullable();
            $table->foreignId('diselesaikan_oleh')->nullable()->constrained('users');
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_repair');
    }
};
