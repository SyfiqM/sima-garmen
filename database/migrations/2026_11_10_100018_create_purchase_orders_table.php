<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('no_po', 20)->unique();
            $table->foreignId('supplier_id')->constrained('supplier');
            $table->date('tgl_po');
            $table->date('tgl_estimasi_tiba')->nullable();
            $table->enum('status', ['draft', 'dipesan', 'diterima_sebagian', 'diterima', 'dibatalkan'])->default('draft');
            $table->text('catatan')->nullable();
            $table->string('alasan_batal', 255)->nullable();
            $table->foreignId('dibuat_oleh')->constrained('users');
            $table->dateTime('dipesan_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
