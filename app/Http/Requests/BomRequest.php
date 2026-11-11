<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'manajer') ?? false;
    }

    public function rules(): array
    {
        return [
            'bahan_id' => 'required|exists:bahan,id',
            'tahap_id' => 'required|exists:tahap,id',
            'ukuran_id' => 'nullable|exists:ukuran,id',
            'warna_id' => 'nullable|exists:warna,id',
            'qty_per_pcs' => 'required|numeric|gt:0',
            'waste_persen' => 'nullable|numeric|min:0',
            'catatan' => 'nullable|string|max:255',
        ];
    }
}