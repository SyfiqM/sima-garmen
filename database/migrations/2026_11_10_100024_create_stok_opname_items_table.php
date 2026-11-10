<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stok_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opname_id')->constrained('stok_opname')->cascadeOnDelete();
            $table->foreignId('bahan_id')->constrained('bahan');
            $table->decimal('stok_sistem', 14, 3);
            $table->decimal('stok_fisik', 14, 3);
            $table->decimal('selisih', 14, 3);
            $table->string('alasan', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stok_opname_items');
    }
};
