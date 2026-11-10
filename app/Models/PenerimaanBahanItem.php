<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PenerimaanBahanItem extends Model
{
    protected $table = 'penerimaan_bahan_items';

    protected $fillable = [
        'penerimaan_id',
        'po_item_id',
        'bahan_id',
        'qty',
        'harga_satuan',
        'catatan',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'harga_satuan' => 'decimal:2',
    ];

    public function penerimaan(): BelongsTo
    {
        return $this->belongsTo(PenerimaanBahan::class, 'penerimaan_id');
    }

    public function poItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'po_item_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }
}
