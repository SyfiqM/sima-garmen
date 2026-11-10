<?php

namespace App\Models;

use App\Enums\WoTahapStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WoTahap extends Model
{
    protected $table = 'wo_tahap';

    protected $fillable = [
        'wo_id',
        'tahap_id',
        'status',
        'mulai_at',
        'selesai_at',
        'ditutup_oleh',
        'catatan_penutupan',
    ];

    protected $casts = [
        'status' => WoTahapStatus::class,
        'mulai_at' => 'datetime',
        'selesai_at' => 'datetime',
    ];

    public function wo(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'wo_id');
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_id');
    }

    public function ditutupOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditutup_oleh');
    }
}
