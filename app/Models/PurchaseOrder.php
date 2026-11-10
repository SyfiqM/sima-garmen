<?php

namespace App\Models;

use App\Enums\PoStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    protected $fillable = [
        'no_po',
        'supplier_id',
        'tgl_po',
        'tgl_estimasi_tiba',
        'status',
        'catatan',
        'alasan_batal',
        'dibuat_oleh',
        'dipesan_at',
    ];

    protected $casts = [
        'tgl_po' => 'date',
        'tgl_estimasi_tiba' => 'date',
        'status' => PoStatus::class,
        'dipesan_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id');
    }

    public function penerimaanBahan(): HasMany
    {
        return $this->hasMany(PenerimaanBahan::class, 'po_id');
    }
}
