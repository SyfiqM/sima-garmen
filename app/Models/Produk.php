<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Produk extends Model
{
    use SoftDeletes;

    protected $table = 'produk';

    protected $fillable = [
        'kode',
        'nama',
        'kategori',
        'deskripsi',
        'harga_jual_default',
        'is_active',
    ];

    protected $casts = [
        'harga_jual_default' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function bom(): HasMany
    {
        return $this->hasMany(Bom::class, 'produk_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'produk_id');
    }
}
