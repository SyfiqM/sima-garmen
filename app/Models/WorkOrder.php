<?php

namespace App\Models;

use App\Enums\WoStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $table = 'work_orders';

    protected $fillable = [
        'no_wo',
        'order_id',
        'revisi_ke',
        'status',
        'tgl_mulai',
        'tgl_target',
        'tgl_selesai',
        'produksi_selesai_at',
        'catatan',
        'dibuat_oleh',
    ];

    protected $casts = [
        'status' => WoStatus::class,
        'tgl_mulai' => 'date',
        'tgl_target' => 'date',
        'tgl_selesai' => 'date',
        'produksi_selesai_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function woItems(): HasMany
    {
        return $this->hasMany(WoItem::class, 'wo_id');
    }

    public function woTahap(): HasMany
    {
        return $this->hasMany(WoTahap::class, 'wo_id');
    }

    public function woBahan(): HasMany
    {
        return $this->hasMany(WoBahan::class, 'wo_id');
    }

    public function pengeluaranBahan(): HasMany
    {
        return $this->hasMany(PengeluaranBahan::class, 'wo_id');
    }

    public function qcInspeksi(): HasMany
    {
        return $this->hasMany(QcInspeksi::class, 'wo_id');
    }

    public function packing(): HasMany
    {
        return $this->hasMany(Packing::class, 'wo_id');
    }
}
