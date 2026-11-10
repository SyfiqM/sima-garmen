<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warna extends Model
{
    protected $table = 'warna';

    protected $fillable = [
        'nama',
        'kode_hex',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function bahan(): HasMany
    {
        return $this->hasMany(Bahan::class, 'warna_id');
    }

    public function bom(): HasMany
    {
        return $this->hasMany(Bom::class, 'warna_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'warna_id');
    }
}
