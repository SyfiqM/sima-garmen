<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('produk_id')->constrained('produk');
            $table->foreignId('warna_id')->constrained('warna');
            $table->foreignId('ukuran_id')->constrained('ukuran');
            $table->unsignedInteger('qty');
            $table->decimal('harga_satuan', 14, 2)->nullable();
            $table->text('spesifikasi')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'produk_id', 'warna_id', 'ukuran_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
