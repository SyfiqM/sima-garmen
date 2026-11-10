<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WoItem extends Model
{
    protected $table = 'wo_items';

    protected $fillable = [
        'wo_id',
        'order_item_id',
        'qty_order',
        'qty_batas_potong',
        'qty_cutting',
        'qty_cutting_reject',
        'qty_sewing',
        'qty_sewing_reject',
        'qty_finishing',
        'qty_finishing_reject',
        'qty_qc_diperiksa',
        'qty_qc_lolos',
        'qty_qc_reject',
        'qty_repair_open',
        'qty_repair_selesai',
        'qty_packing',
        'qty_terkirim',
    ];

    protected $casts = [

    ];

    // Rumus ketersediaan antar tahap (bagian 4.8 dokumen rancangan)

    public function getCuttingBolehAttribute(): int
    {
        return $this->qty_batas_potong - ($this->qty_cutting + $this->qty_cutting_reject);
    }

    public function getSewingTersediaAttribute(): int
    {
        return $this->qty_cutting - ($this->qty_sewing + $this->qty_sewing_reject);
    }

    public function getFinishingTersediaAttribute(): int
    {
        return $this->qty_sewing - ($this->qty_finishing + $this->qty_finishing_reject);
    }

    public function getQcTersediaAttribute(): int
    {
        return $this->qty_finishing + $this->qty_repair_selesai - $this->qty_qc_diperiksa;
    }

    public function getPackingTersediaAttribute(): int
    {
        return min($this->qty_qc_lolos, $this->qty_order) - $this->qty_packing;
    }

    public function getKirimTersediaAttribute(): int
    {
        return $this->qty_packing - $this->qty_terkirim;
    }

    public function wo(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'wo_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function produksiLog(): HasMany
    {
        return $this->hasMany(ProduksiLog::class, 'wo_item_id');
    }

    public function qcInspeksiItems(): HasMany
    {
        return $this->hasMany(QcInspeksiItem::class, 'wo_item_id');
    }

    public function qcRepair(): HasMany
    {
        return $this->hasMany(QcRepair::class, 'wo_item_id');
    }

    public function packingItems(): HasMany
    {
        return $this->hasMany(PackingItem::class, 'wo_item_id');
    }
}
