<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bahan', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama', 150);
            $table->enum('jenis', ['kain', 'benang', 'aksesoris', 'kemasan']);
            $table->foreignId('satuan_id')->constrained('satuan');
            $table->foreignId('warna_id')->nullable()->constrained('warna');
            $table->decimal('stok', 14, 3)->default(0);
            $table->decimal('stok_dipesan', 14, 3)->default(0);
            $table->decimal('stok_minimum', 14, 3)->default(0);
            $table->decimal('harga_terakhir', 14, 2)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('supplier');
            $table->string('lokasi_rak', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('jenis');
            $table->index('is_active');
        });

        DB::statement('ALTER TABLE `bahan` ADD CONSTRAINT `chk_bahan_stok` CHECK (`stok` >= 0)');
        DB::statement('ALTER TABLE `bahan` ADD CONSTRAINT `chk_bahan_dipesan` CHECK (`stok_dipesan` >= 0)');
        DB::statement('ALTER TABLE `bahan` ADD CONSTRAINT `chk_bahan_dipesan_stok` CHECK (`stok_dipesan` <= `stok`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bahan');
    }
};
