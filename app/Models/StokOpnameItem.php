<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StokOpnameItem extends Model
{
    protected $table = 'stok_opname_items';

    protected $fillable = [
        'opname_id',
        'bahan_id',
        'stok_sistem',
        'stok_fisik',
        'selisih',
        'alasan',
    ];

    protected $casts = [
        'stok_sistem' => 'decimal:3',
        'stok_fisik' => 'decimal:3',
        'selisih' => 'decimal:3',
    ];

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StokOpname::class, 'opname_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }
}
