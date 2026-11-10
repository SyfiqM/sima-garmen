<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLampiran extends Model
{
    protected $table = 'order_lampiran';

    protected $fillable = [
        'order_id',
        'nama_file',
        'path',
        'mime',
        'ukuran_kb',
        'keterangan',
        'diunggah_oleh',
    ];

    protected $casts = [

    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function diunggahOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh');
    }
}
