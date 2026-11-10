<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produk_id')->constrained('produk');
            $table->foreignId('bahan_id')->constrained('bahan');
            $table->foreignId('tahap_id')->constrained('tahap');
            $table->foreignId('ukuran_id')->nullable()->constrained('ukuran');
            $table->foreignId('warna_id')->nullable()->constrained('warna');
            $table->decimal('qty_per_pcs', 10, 4);
            $table->decimal('waste_persen', 5, 2)->default(0);
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
            $table->index(['produk_id', 'tahap_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom');
    }
};
