<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcInspeksiItem extends Model
{
    protected $table = 'qc_inspeksi_items';

    protected $fillable = [
        'qc_inspeksi_id',
        'wo_item_id',
        'qty_diperiksa',
        'qty_lolos',
        'qty_repair',
        'qty_reject',
    ];

    protected $casts = [

    ];

    public function qcInspeksi(): BelongsTo
    {
        return $this->belongsTo(QcInspeksi::class, 'qc_inspeksi_id');
    }

    public function woItem(): BelongsTo
    {
        return $this->belongsTo(WoItem::class, 'wo_item_id');
    }

    public function qcInspeksiCacat(): HasMany
    {
        return $this->hasMany(QcInspeksiCacat::class, 'qc_inspeksi_item_id');
    }

    public function qcRepair(): HasMany
    {
        return $this->hasMany(QcRepair::class, 'qc_inspeksi_item_id');
    }
}
