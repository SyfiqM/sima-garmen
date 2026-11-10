<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackingItem extends Model
{
    protected $table = 'packing_items';

    protected $fillable = [
        'packing_id',
        'wo_item_id',
        'qty',
    ];

    protected $casts = [

    ];

    public function packing(): BelongsTo
    {
        return $this->belongsTo(Packing::class, 'packing_id');
    }

    public function woItem(): BelongsTo
    {
        return $this->belongsTo(WoItem::class, 'wo_item_id');
    }
}
