<?php

namespace App\Models;

use App\Enums\DocStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduksiLog extends Model
{
    protected $table = 'produksi_log';

    protected $fillable = [
        'wo_item_id',
        'tahap_id',
        'line_id',
        'tanggal',
        'qty_hasil',
        'qty_reject',
        'jenis_cacat_id',
        'catatan',
        'status',
        'void_alasan',
        'void_oleh',
        'void_at',
        'dibuat_oleh',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'status' => DocStatus::class,
        'void_at' => 'datetime',
    ];

    public function woItem(): BelongsTo
    {
        return $this->belongsTo(WoItem::class, 'wo_item_id');
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(LineProduksi::class, 'line_id');
    }

    public function jenisCacat(): BelongsTo
    {
        return $this->belongsTo(JenisCacat::class, 'jenis_cacat_id');
    }

    public function voidOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_oleh');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
