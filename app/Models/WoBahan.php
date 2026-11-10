<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WoBahan extends Model
{
    protected $table = 'wo_bahan';

    protected $fillable = [
        'wo_id',
        'bahan_id',
        'tahap_id',
        'qty_rencana',
        'qty_reservasi',
        'qty_keluar',
        'qty_retur',
    ];

    protected $casts = [
        'qty_rencana' => 'decimal:3',
        'qty_reservasi' => 'decimal:3',
        'qty_keluar' => 'decimal:3',
        'qty_retur' => 'decimal:3',
    ];

    public function wo(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'wo_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_id');
    }

    public function pengeluaranBahanItems(): HasMany
    {
        return $this->hasMany(PengeluaranBahanItem::class, 'wo_bahan_id');
    }
}
