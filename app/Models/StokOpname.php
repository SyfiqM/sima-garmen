<?php

namespace App\Models;

use App\Enums\OpnameStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StokOpname extends Model
{
    protected $table = 'stok_opname';

    protected $fillable = [
        'no_opname',
        'tanggal',
        'status',
        'catatan',
        'dibuat_oleh',
        'disetujui_oleh',
        'disetujui_at',
        'alasan_penolakan',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'status' => OpnameStatus::class,
        'disetujui_at' => 'datetime',
    ];

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function disetujuiOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    public function stokOpnameItems(): HasMany
    {
        return $this->hasMany(StokOpnameItem::class, 'opname_id');
    }
}
