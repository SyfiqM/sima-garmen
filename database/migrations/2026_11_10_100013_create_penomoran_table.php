<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penomoran', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 5);
            $table->string('periode', 6);
            $table->unsignedInteger('nomor_terakhir')->default(0);
            $table->timestamps();
            $table->unique(['kode', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penomoran');
    }
};
