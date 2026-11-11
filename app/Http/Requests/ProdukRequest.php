<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProdukRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'manajer') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'kode' => ['nullable', 'string', 'max:30', Rule::unique('produk', 'kode')->ignore($id)],
            'nama' => 'required|string|max:150',
            'kategori' => 'nullable|string|max:50',
            'deskripsi' => 'nullable|string',
            'harga_jual_default' => 'nullable|numeric|min:0',
            'is_active' => 'boolean'
        ];
    }
}