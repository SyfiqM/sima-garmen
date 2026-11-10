<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';

    protected $fillable = [
        'po_id',
        'bahan_id',
        'qty_pesan',
        'qty_diterima',
        'harga_satuan',
        'ref_order_id',
        'keterangan',
    ];

    protected $casts = [
        'qty_pesan' => 'decimal:3',
        'qty_diterima' => 'decimal:3',
        'harga_satuan' => 'decimal:2',
    ];

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }

    public function refOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'ref_order_id');
    }

    public function penerimaanBahanItems(): HasMany
    {
        return $this->hasMany(PenerimaanBahanItem::class, 'po_item_id');
    }
}
