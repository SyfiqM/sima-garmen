<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wo_id')->constrained('work_orders');
            $table->foreignId('order_item_id')->constrained('order_items');
            $table->unsignedInteger('qty_order');
            $table->unsignedInteger('qty_batas_potong');
            $table->unsignedInteger('qty_cutting')->default(0);
            $table->unsignedInteger('qty_cutting_reject')->default(0);
            $table->unsignedInteger('qty_sewing')->default(0);
            $table->unsignedInteger('qty_sewing_reject')->default(0);
            $table->unsignedInteger('qty_finishing')->default(0);
            $table->unsignedInteger('qty_finishing_reject')->default(0);
            $table->unsignedInteger('qty_qc_diperiksa')->default(0);
            $table->unsignedInteger('qty_qc_lolos')->default(0);
            $table->unsignedInteger('qty_qc_reject')->default(0);
            $table->unsignedInteger('qty_repair_open')->default(0);
            $table->unsignedInteger('qty_repair_selesai')->default(0);
            $table->unsignedInteger('qty_packing')->default(0);
            $table->unsignedInteger('qty_terkirim')->default(0);
            $table->timestamps();
            $table->unique(['wo_id', 'order_item_id']);
        });

        DB::statement('ALTER TABLE `wo_items` ADD CONSTRAINT `chk_woi_packing` CHECK (`qty_packing` <= `qty_order`)');
        DB::statement('ALTER TABLE `wo_items` ADD CONSTRAINT `chk_woi_terkirim` CHECK (`qty_terkirim` <= `qty_packing`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_items');
    }
};
