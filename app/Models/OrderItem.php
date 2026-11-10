<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'produk_id',
        'warna_id',
        'ukuran_id',
        'qty',
        'harga_satuan',
        'spesifikasi',
    ];

    protected $casts = [
        'harga_satuan' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class, 'produk_id');
    }

    public function warna(): BelongsTo
    {
        return $this->belongsTo(Warna::class, 'warna_id');
    }

    public function ukuran(): BelongsTo
    {
        return $this->belongsTo(Ukuran::class, 'ukuran_id');
    }

    public function woItems(): HasMany
    {
        return $this->hasMany(WoItem::class, 'order_item_id');
    }
}
