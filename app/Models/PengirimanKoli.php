<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PengirimanKoli extends Model
{
    protected $table = 'pengiriman_koli';

    protected $fillable = [
        'pengiriman_id',
        'packing_id',
    ];

    protected $casts = [

    ];

    public function pengiriman(): BelongsTo
    {
        return $this->belongsTo(Pengiriman::class, 'pengiriman_id');
    }

    public function packing(): BelongsTo
    {
        return $this->belongsTo(Packing::class, 'packing_id');
    }
}
