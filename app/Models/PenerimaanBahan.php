<?php

namespace App\Models;

use App\Enums\DocStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PenerimaanBahan extends Model
{
    protected $table = 'penerimaan_bahan';

    protected $fillable = [
        'no_bm',
        'po_id',
        'supplier_id',
        'tgl_terima',
        'no_sj_supplier',
        'catatan',
        'status',
        'void_alasan',
        'void_oleh',
        'void_at',
        'diterima_oleh',
    ];

    protected $casts = [
        'tgl_terima' => 'date',
        'status' => DocStatus::class,
        'void_at' => 'datetime',
    ];

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function voidOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_oleh');
    }

    public function diterimaOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diterima_oleh');
    }

    public function penerimaanBahanItems(): HasMany
    {
        return $this->hasMany(PenerimaanBahanItem::class, 'penerimaan_id');
    }
}
