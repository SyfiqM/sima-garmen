<?php

namespace App\Models;

use App\Enums\TindakanCacat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcInspeksiCacat extends Model
{
    protected $table = 'qc_inspeksi_cacat';

    protected $fillable = [
        'qc_inspeksi_item_id',
        'jenis_cacat_id',
        'qty',
        'tindakan',
    ];

    protected $casts = [
        'tindakan' => TindakanCacat::class,
    ];

    public function qcInspeksiItem(): BelongsTo
    {
        return $this->belongsTo(QcInspeksiItem::class, 'qc_inspeksi_item_id');
    }

    public function jenisCacat(): BelongsTo
    {
        return $this->belongsTo(JenisCacat::class, 'jenis_cacat_id');
    }
}
