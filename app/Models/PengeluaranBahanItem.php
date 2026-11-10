<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PengeluaranBahanItem extends Model
{
    protected $table = 'pengeluaran_bahan_items';

    protected $fillable = [
        'pengeluaran_id',
        'wo_bahan_id',
        'bahan_id',
        'qty',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    public function pengeluaran(): BelongsTo
    {
        return $this->belongsTo(PengeluaranBahan::class, 'pengeluaran_id');
    }

    public function woBahan(): BelongsTo
    {
        return $this->belongsTo(WoBahan::class, 'wo_bahan_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }
}
