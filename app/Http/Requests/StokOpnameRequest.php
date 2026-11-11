<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StokOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'gudang') ?? false;
    }

    public function rules(): array
    {
        return [
            'tanggal' => 'required|date',
            'catatan' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.bahan_id' => 'required|exists:bahan,id|distinct',
            'items.*.stok_fisik' => 'required|numeric|min:0',
            'items.*.alasan' => 'nullable|string|max:255',
        ];
    }
}