<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wo_bahan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wo_id')->constrained('work_orders');
            $table->foreignId('bahan_id')->constrained('bahan');
            $table->foreignId('tahap_id')->constrained('tahap');
            $table->decimal('qty_rencana', 14, 3);
            $table->decimal('qty_reservasi', 14, 3)->default(0);
            $table->decimal('qty_keluar', 14, 3)->default(0);
            $table->decimal('qty_retur', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['wo_id', 'bahan_id', 'tahap_id']);
        });

        DB::statement('ALTER TABLE `wo_bahan` ADD CONSTRAINT `chk_wob_reservasi` CHECK (`qty_reservasi` <= `qty_rencana`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_bahan');
    }
};
