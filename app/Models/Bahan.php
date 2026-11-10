<?php

namespace App\Models;

use App\Enums\JenisBahan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bahan extends Model
{
    use SoftDeletes;

    protected $table = 'bahan';

    protected $fillable = [
        'kode',
        'nama',
        'jenis',
        'satuan_id',
        'warna_id',
        'stok',
        'stok_dipesan',
        'stok_minimum',
        'harga_terakhir',
        'supplier_id',
        'lokasi_rak',
        'is_active',
    ];

    protected $casts = [
        'jenis' => JenisBahan::class,
        'stok' => 'decimal:3',
        'stok_dipesan' => 'decimal:3',
        'stok_minimum' => 'decimal:3',
        'harga_terakhir' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    /** Stok yang masih bebas dipakai (stok dikurangi reservasi). */
    public function getStokTersediaAttribute(): float
    {
        return round((float) $this->stok - (float) $this->stok_dipesan, 3);
    }

    public function satuan(): BelongsTo
    {
        return $this->belongsTo(Satuan::class, 'satuan_id');
    }

    public function warna(): BelongsTo
    {
        return $this->belongsTo(Warna::class, 'warna_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function bom(): HasMany
    {
        return $this->hasMany(Bom::class, 'bahan_id');
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'bahan_id');
    }

    public function penerimaanBahanItems(): HasMany
    {
        return $this->hasMany(PenerimaanBahanItem::class, 'bahan_id');
    }

    public function stokMutasi(): HasMany
    {
        return $this->hasMany(StokMutasi::class, 'bahan_id');
    }

    public function stokOpnameItems(): HasMany
    {
        return $this->hasMany(StokOpnameItem::class, 'bahan_id');
    }

    public function woBahan(): HasMany
    {
        return $this->hasMany(WoBahan::class, 'bahan_id');
    }

    public function pengeluaranBahanItems(): HasMany
    {
        return $this->hasMany(PengeluaranBahanItem::class, 'bahan_id');
    }
}
