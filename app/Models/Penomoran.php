<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Penomoran extends Model
{
    protected $table = 'penomoran';

    protected $fillable = [
        'kode',
        'periode',
        'nomor_terakhir',
    ];

    protected $casts = [

    ];
}
