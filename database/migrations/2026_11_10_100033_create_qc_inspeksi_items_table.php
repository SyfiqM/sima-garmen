<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_inspeksi_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_inspeksi_id')->constrained('qc_inspeksi');
            $table->foreignId('wo_item_id')->constrained('wo_items');
            $table->unsignedInteger('qty_diperiksa')->default(0);
            $table->unsignedInteger('qty_lolos')->default(0);
            $table->unsignedInteger('qty_repair')->default(0);
            $table->unsignedInteger('qty_reject')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `qc_inspeksi_items` ADD CONSTRAINT `chk_qci_jumlah` CHECK (`qty_diperiksa` = `qty_lolos` + `qty_repair` + `qty_reject`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspeksi_items');
    }
};
