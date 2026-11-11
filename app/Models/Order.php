<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\Prioritas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Order extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (empty($order->dibuat_oleh) && Auth::check()) {
                $order->dibuat_oleh = Auth::id();
            }
        });
    }

    protected $table = 'orders';

    protected $fillable = [
        'no_order',
        'revisi_ke',
        'pelanggan_id',
        'no_po_pelanggan',
        'tgl_order',
        'deadline',
        'prioritas',
        'status',
        'alamat_kirim',
        'catatan',
        'total_qty',
        'total_nilai',
        'alasan_penolakan',
        'alasan_pembatalan',
        'ditutup_kurang',
        'alasan_tutup_kurang',
        'dibuat_oleh',
        'diajukan_at',
        'disetujui_oleh',
        'disetujui_at',
    ];

    protected $casts = [
        'tgl_order' => 'date',
        'deadline' => 'date',
        'prioritas' => Prioritas::class,
        'status' => OrderStatus::class,
        'total_nilai' => 'decimal:2',
        'ditutup_kurang' => 'boolean',
        'diajukan_at' => 'datetime',
        'disetujui_at' => 'datetime',
    ];

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function disetujuiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function orderItems(): HasMany
    {
        return $this->items();
    }

    public function lampiran(): HasMany
    {
        return $this->hasMany(OrderLampiran::class, 'order_id');
    }

    public function orderLampiran(): HasMany
    {
        return $this->lampiran();
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'ref_order_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'order_id');
    }

    public function pengiriman(): HasMany
    {
        return $this->hasMany(Pengiriman::class, 'order_id');
    }
}
