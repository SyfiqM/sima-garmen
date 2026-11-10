<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_inspeksi_cacat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_inspeksi_item_id')->constrained('qc_inspeksi_items');
            $table->foreignId('jenis_cacat_id')->constrained('jenis_cacat');
            $table->unsignedInteger('qty');
            $table->enum('tindakan', ['repair', 'reject']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspeksi_cacat');
    }
};
