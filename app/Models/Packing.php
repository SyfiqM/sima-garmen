<?php

namespace App\Models;

use App\Enums\PackingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Packing extends Model
{
    protected $table = 'packing';

    protected $fillable = [
        'no_koli',
        'wo_id',
        'urutan_koli',
        'tanggal',
        'berat_kg',
        'catatan',
        'status',
        'void_alasan',
        'void_oleh',
        'void_at',
        'dibuat_oleh',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'berat_kg' => 'decimal:2',
        'status' => PackingStatus::class,
        'void_at' => 'datetime',
    ];

    public function wo(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'wo_id');
    }

    public function voidOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_oleh');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function packingItems(): HasMany
    {
        return $this->hasMany(PackingItem::class, 'packing_id');
    }

    public function pengirimanKoli(): HasMany
    {
        return $this->hasMany(PengirimanKoli::class, 'packing_id');
    }
}
