<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PengirimanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('admin', 'gudang');
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'koli_ids' => ['required', 'array', 'min:1'],
            'koli_ids.*' => ['required', 'integer', 'distinct', 'exists:packing,id'],
            'tgl_kirim' => ['nullable', 'date'],
            'ekspedisi' => ['nullable', 'string', 'max:100'],
            'no_resi' => ['nullable', 'string', 'max:60'],
            'no_kendaraan' => ['nullable', 'string', 'max:20'],
            'nama_sopir' => ['nullable', 'string', 'max:100'],
            'nama_penerima' => ['nullable', 'string', 'max:100'],
            'alamat_tujuan' => ['nullable', 'string', 'max:1000'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}