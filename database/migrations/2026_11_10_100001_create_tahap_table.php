<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tahap', function (Blueprint $table) {
            $table->id();
            $table->enum('kode', ['cutting', 'sewing', 'finishing'])->unique();
            $table->string('nama', 50);
            $table->unsignedTinyInteger('urutan');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahap');
    }
};
