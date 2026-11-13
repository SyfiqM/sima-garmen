<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class PengirimanDiterimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('admin', 'staf_order', 'gudang');
    }

    public function rules(): array
    {
        $maksMb = (int) (DB::table('pengaturan')->where('key', 'maks_lampiran_mb')->value('value') ?? 5);

        return [
            'tgl_diterima' => ['nullable', 'date', 'before_or_equal:today'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'bukti' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'extensions:jpg,jpeg,png,pdf',
                'max:'.($maksMb * 1024),
            ],
        ];
    }
}