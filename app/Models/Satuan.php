<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Satuan extends Model
{
    protected $table = 'satuan';

    protected $fillable = [
        'kode',
        'nama',
    ];

    protected $casts = [

    ];

    public function bahan(): HasMany
    {
        return $this->hasMany(Bahan::class, 'satuan_id');
    }
}
