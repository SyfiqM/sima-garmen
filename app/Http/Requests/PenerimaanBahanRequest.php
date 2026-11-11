<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PenerimaanBahanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'gudang') ?? false;
    }

    public function rules(): array
    {
        return [
            'po_id' => 'nullable|exists:purchase_orders,id',
            'supplier_id' => 'required|exists:supplier,id',
            'tgl_terima' => 'required|date',
            'no_sj_supplier' => 'nullable|string|max:50',
            'catatan' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.po_item_id' => 'nullable|exists:purchase_order_items,id',
            'items.*.bahan_id' => 'required|exists:bahan,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.harga_satuan' => 'nullable|numeric|min:0',
        ];
    }
}