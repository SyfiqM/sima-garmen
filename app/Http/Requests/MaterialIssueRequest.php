<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialIssueRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'gudang') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'wo_id' => ['required', 'integer', 'exists:work_orders,id'],
            'tahap_id' => ['required', 'integer', 'exists:tahap,id'],
            'tipe' => ['required', Rule::in(['keluar', 'retur'])],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'catatan' => ['nullable', 'string'],
            'alasan_lebih' => ['nullable', 'string', 'min:5', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.wo_bahan_id' => ['required', 'integer', 'distinct', 'exists:wo_bahan,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.alasan_lebih' => ['nullable', 'string', 'min:5', 'max:255'],
        ];
    }
}
