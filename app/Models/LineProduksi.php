<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LineProduksi extends Model
{
    protected $table = 'line_produksi';

    protected $fillable = [
        'kode',
        'nama',
        'tahap_id',
        'kapasitas_per_hari',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_id');
    }

    public function produksiLog(): HasMany
    {
        return $this->hasMany(ProduksiLog::class, 'line_id');
    }
}
