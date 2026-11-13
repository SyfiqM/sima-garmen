<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'manajer', 'staf_order') ?? false;
    }

    public function rules(): array
    {
        return [
            'pelanggan_id' => 'required|exists:pelanggan,id',
            'no_po_pelanggan' => 'nullable|string|max:50',
            'tgl_order' => 'required|date',
            'deadline' => 'required|date|after_or_equal:tgl_order',
            'prioritas' => 'nullable|in:normal,tinggi,urgent',
            'alamat_kirim' => 'nullable|string',
            'catatan' => 'nullable|string',
            
            // Validasi Items
            'items' => 'required|array|min:1',
            'items.*.produk_id' => 'required|exists:produk,id',
            'items.*.warna_id' => 'required|exists:warna,id',
            'items.*.ukuran_id' => 'required|exists:ukuran,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.harga_satuan' => 'nullable|numeric|min:0',
            'items.*.spesifikasi' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Order harus memiliki minimal 1 item produk.',
            'deadline.after_or_equal' => 'Deadline tidak boleh lebih kecil dari tanggal order.',
        ];
    }
}