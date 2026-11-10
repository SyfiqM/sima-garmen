<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $table = 'supplier';

    protected $fillable = [
        'kode',
        'nama',
        'kontak_person',
        'telepon',
        'email',
        'alamat',
        'catatan',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function bahan(): HasMany
    {
        return $this->hasMany(Bahan::class, 'supplier_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    public function penerimaanBahan(): HasMany
    {
        return $this->hasMany(PenerimaanBahan::class, 'supplier_id');
    }
}
