<?php

namespace App\Models;

use App\Enums\MutasiTipe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StokMutasi extends Model
{
    protected $table = 'stok_mutasi';

    protected $fillable = [
        'bahan_id',
        'tanggal',
        'tipe',
        'qty',
        'stok_sebelum',
        'stok_sesudah',
        'ref_tipe',
        'ref_id',
        'keterangan',
        'user_id',
    ];

    protected $casts = [
        'tanggal' => 'datetime',
        'tipe' => MutasiTipe::class,
        'qty' => 'decimal:3',
        'stok_sebelum' => 'decimal:3',
        'stok_sesudah' => 'decimal:3',
    ];

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
