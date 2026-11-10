<?php

namespace App\Models;

use App\Enums\TahapKode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tahap extends Model
{
    protected $table = 'tahap';

    protected $fillable = [
        'kode',
        'nama',
        'urutan',
        'is_active',
    ];

    protected $casts = [
        'kode' => TahapKode::class,
        'is_active' => 'boolean',
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tahap_id');
    }

    public function lineProduksi(): HasMany
    {
        return $this->hasMany(LineProduksi::class, 'tahap_id');
    }

    public function jenisCacat(): HasMany
    {
        return $this->hasMany(JenisCacat::class, 'tahap_id');
    }

    public function bom(): HasMany
    {
        return $this->hasMany(Bom::class, 'tahap_id');
    }

    public function woTahap(): HasMany
    {
        return $this->hasMany(WoTahap::class, 'tahap_id');
    }

    public function woBahan(): HasMany
    {
        return $this->hasMany(WoBahan::class, 'tahap_id');
    }

    public function pengeluaranBahan(): HasMany
    {
        return $this->hasMany(PengeluaranBahan::class, 'tahap_id');
    }

    public function produksiLog(): HasMany
    {
        return $this->hasMany(ProduksiLog::class, 'tahap_id');
    }

    public function qcRepair(): HasMany
    {
        return $this->hasMany(QcRepair::class, 'tahap_tujuan_id');
    }
}
