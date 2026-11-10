<?php

namespace App\Models;

use App\Enums\DocStatus;
use App\Enums\PengeluaranTipe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PengeluaranBahan extends Model
{
    protected $table = 'pengeluaran_bahan';

    protected $fillable = [
        'no_pb',
        'wo_id',
        'tahap_id',
        'tipe',
        'tanggal',
        'catatan',
        'alasan_lebih',
        'disetujui_oleh',
        'status',
        'void_alasan',
        'void_oleh',
        'void_at',
        'dibuat_oleh',
    ];

    protected $casts = [
        'tipe' => PengeluaranTipe::class,
        'tanggal' => 'date',
        'status' => DocStatus::class,
        'void_at' => 'datetime',
    ];

    public function wo(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'wo_id');
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_id');
    }

    public function disetujuiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    public function voidOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_oleh');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function pengeluaranBahanItems(): HasMany
    {
        return $this->hasMany(PengeluaranBahanItem::class, 'pengeluaran_id');
    }
}
