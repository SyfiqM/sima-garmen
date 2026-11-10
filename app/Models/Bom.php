<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bom extends Model
{
    protected $table = 'bom';

    protected $fillable = [
        'produk_id',
        'bahan_id',
        'tahap_id',
        'ukuran_id',
        'warna_id',
        'qty_per_pcs',
        'waste_persen',
        'catatan',
    ];

    protected $casts = [
        'qty_per_pcs' => 'decimal:4',
        'waste_persen' => 'decimal:2',
    ];

    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class, 'produk_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_id');
    }

    public function ukuran(): BelongsTo
    {
        return $this->belongsTo(Ukuran::class, 'ukuran_id');
    }

    public function warna(): BelongsTo
    {
        return $this->belongsTo(Warna::class, 'warna_id');
    }
}
