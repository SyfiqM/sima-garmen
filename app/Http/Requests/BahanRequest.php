<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BahanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'gudang') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('bahan') ?? $this->route('id');

        return [
            'kode' => ['nullable', 'string', 'max:30', Rule::unique('bahan', 'kode')->ignore($id)],
            'nama' => 'required|string|max:150',
            'jenis' => 'required|in:kain,benang,aksesoris,kemasan',
            'satuan_id' => 'required|exists:satuan,id',
            'warna_id' => 'nullable|exists:warna,id',
            'stok_minimum' => 'nullable|numeric|min:0',
            'harga_terakhir' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|exists:supplier,id',
            'lokasi_rak' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            // Perhatikan: 'stok' dan 'stok_dipesan' sengaja tidak dimasukkan agar tidak bisa dimanipulasi dari endpoint ini.
        ];
    }
}
