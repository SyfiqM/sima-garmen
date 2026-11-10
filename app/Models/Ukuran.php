<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ukuran extends Model
{
    protected $table = 'ukuran';

    protected $fillable = [
        'kode',
        'urutan',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function bom(): HasMany
    {
        return $this->hasMany(Bom::class, 'ukuran_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'ukuran_id');
    }
}
