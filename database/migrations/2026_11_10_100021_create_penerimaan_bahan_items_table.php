<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penerimaan_bahan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('penerimaan_id')->constrained('penerimaan_bahan');
            $table->foreignId('po_item_id')->nullable()->constrained('purchase_order_items');
            $table->foreignId('bahan_id')->constrained('bahan');
            $table->decimal('qty', 14, 3);
            $table->decimal('harga_satuan', 14, 2)->nullable();
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerimaan_bahan_items');
    }
};
