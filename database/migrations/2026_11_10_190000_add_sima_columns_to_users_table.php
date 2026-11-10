<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
            $table->enum('role', ['admin', 'manajer', 'staf_order', 'gudang', 'supervisor', 'qc', 'pimpinan'])
                ->default('staf_order')->after('password');
            $table->foreignId('tahap_id')->nullable()->after('role')->constrained('tahap');
            $table->string('no_hp', 20)->nullable()->after('tahap_id');
            $table->boolean('is_active')->default(true)->after('no_hp');
            $table->dateTime('last_login_at')->nullable()->after('is_active');
            $table->softDeletes();
        });

        // Jika sudah ada baris user lama (mis. hasil register Breeze), beri username otomatis.
        DB::table('users')->whereNull('username')->orderBy('id')->get()->each(function ($u) {
            DB::table('users')->where('id', $u->id)->update(['username' => 'user' . $u->id]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tahap_id']);
            $table->dropUnique(['username']);
            $table->dropSoftDeletes();
            $table->dropColumn(['username', 'role', 'tahap_id', 'no_hp', 'is_active', 'last_login_at']);
        });
    }
};
