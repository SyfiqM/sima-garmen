<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JenisCacat extends Model
{
    protected $table = 'jenis_cacat';

    protected $fillable = [
        'kode',
        'nama',
        'kategori',
        'tahap_id',
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
        return $this->hasMany(ProduksiLog::class, 'jenis_cacat_id');
    }

    public function qcInspeksiCacat(): HasMany
    {
        return $this->hasMany(QcInspeksiCacat::class, 'jenis_cacat_id');
    }
}
