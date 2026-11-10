<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengeluaran_bahan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengeluaran_id')->constrained('pengeluaran_bahan');
            $table->foreignId('wo_bahan_id')->constrained('wo_bahan');
            $table->foreignId('bahan_id')->constrained('bahan');
            $table->decimal('qty', 14, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengeluaran_bahan_items');
    }
};
