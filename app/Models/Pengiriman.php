<?php

namespace App\Models;

use App\Enums\PengirimanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pengiriman extends Model
{
    protected $table = 'pengiriman';

    protected $fillable = [
        'no_sj',
        'order_id',
        'tgl_kirim',
        'ekspedisi',
        'no_resi',
        'no_kendaraan',
        'nama_sopir',
        'nama_penerima',
        'alamat_tujuan',
        'status',
        'tgl_diterima',
        'bukti_terima_path',
        'alasan_batal',
        'catatan',
        'dibuat_oleh',
    ];

    protected $casts = [
        'tgl_kirim' => 'date',
        'status' => PengirimanStatus::class,
        'tgl_diterima' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function pengirimanKoli(): HasMany
    {
        return $this->hasMany(PengirimanKoli::class, 'pengiriman_id');
    }
}
