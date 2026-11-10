<?php

namespace App\Models;

use App\Enums\DocStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcInspeksi extends Model
{
    protected $table = 'qc_inspeksi';

    protected $fillable = [
        'no_qc',
        'wo_id',
        'tanggal',
        'catatan',
        'status',
        'void_alasan',
        'void_oleh',
        'void_at',
        'qc_id',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'status' => DocStatus::class,
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

    public function qc(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qc_id');
    }

    public function qcInspeksiItems(): HasMany
    {
        return $this->hasMany(QcInspeksiItem::class, 'qc_inspeksi_id');
    }
}
