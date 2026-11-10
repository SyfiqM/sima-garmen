<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('bahan_id')->constrained('bahan');
            $table->decimal('qty_pesan', 14, 3);
            $table->decimal('qty_diterima', 14, 3)->default(0);
            $table->decimal('harga_satuan', 14, 2)->default(0);
            $table->foreignId('ref_order_id')->nullable()->constrained('orders');
            $table->string('keterangan', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
