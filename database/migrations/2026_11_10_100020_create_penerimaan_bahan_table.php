<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penerimaan_bahan', function (Blueprint $table) {
            $table->id();
            $table->string('no_bm', 20)->unique();
            $table->foreignId('po_id')->nullable()->constrained('purchase_orders');
            $table->foreignId('supplier_id')->constrained('supplier');
            $table->date('tgl_terima');
            $table->string('no_sj_supplier', 50)->nullable();
            $table->text('catatan')->nullable();
            $table->enum('status', ['aktif', 'void'])->default('aktif');
            $table->string('void_alasan', 255)->nullable();
            $table->foreignId('void_oleh')->nullable()->constrained('users');
            $table->dateTime('void_at')->nullable();
            $table->foreignId('diterima_oleh')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penerimaan_bahan');
    }
};
