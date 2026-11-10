<?php

namespace App\Models;

use App\Enums\RepairStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcRepair extends Model
{
    protected $table = 'qc_repair';

    protected $fillable = [
        'qc_inspeksi_item_id',
        'wo_item_id',
        'qty',
        'tahap_tujuan_id',
        'status',
        'qty_selesai',
        'selesai_at',
        'diselesaikan_oleh',
        'catatan',
    ];

    protected $casts = [
        'status' => RepairStatus::class,
        'selesai_at' => 'datetime',
    ];

    public function qcInspeksiItem(): BelongsTo
    {
        return $this->belongsTo(QcInspeksiItem::class, 'qc_inspeksi_item_id');
    }

    public function woItem(): BelongsTo
    {
        return $this->belongsTo(WoItem::class, 'wo_item_id');
    }

    public function tahapTujuan(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_tujuan_id');
    }

    public function diselesaikanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diselesaikan_oleh');
    }
}
