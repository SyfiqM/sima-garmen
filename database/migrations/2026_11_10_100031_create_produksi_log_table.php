<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produksi_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wo_item_id')->constrained('wo_items');
            $table->foreignId('tahap_id')->constrained('tahap');
            $table->foreignId('line_id')->nullable()->constrained('line_produksi');
            $table->date('tanggal');
            $table->unsignedInteger('qty_hasil')->default(0);
            $table->unsignedInteger('qty_reject')->default(0);
            $table->foreignId('jenis_cacat_id')->nullable()->constrained('jenis_cacat');
            $table->string('catatan', 255)->nullable();
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->string('void_alasan', 255)->nullable();
            $table->foreignId('void_oleh')->nullable()->constrained('users');
            $table->dateTime('void_at')->nullable();
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->timestamps();
            $table->index(['wo_item_id', 'tahap_id', 'status']);
            $table->index('tanggal');
            $table->index(['line_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produksi_log');
    }
};
