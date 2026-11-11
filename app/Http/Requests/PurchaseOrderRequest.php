<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'gudang') ?? false;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => 'required|exists:supplier,id',
            'tgl_po' => 'required|date',
            'tgl_estimasi_tiba' => 'nullable|date|after_or_equal:tgl_po',
            'catatan' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.bahan_id' => 'required|exists:bahan,id|distinct', // distinct mencegah duplikat bahan
            'items.*.qty_pesan' => 'required|numeric|min:0.001',
            'items.*.harga_satuan' => 'required|numeric|min:0',
            'items.*.keterangan' => 'nullable|string',
        ];
    }
}