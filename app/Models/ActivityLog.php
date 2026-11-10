<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    protected $fillable = [
        'user_id',
        'aksi',
        'subjek_tipe',
        'subjek_id',
        'sebelum',
        'sesudah',
        'alasan',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'sebelum' => 'array',
        'sesudah' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
