<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ProductionLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $tahapId = (int) $this->input('tahap_id');

        return $user?->hasRole('admin')
            || ($user?->hasRole('supervisor') && $tahapId > 0 && (int) $user->tahap_id === $tahapId);
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
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.wo_item_id' => ['required', 'integer', 'distinct', 'exists:wo_items,id'],
            'items.*.line_id' => ['nullable', 'integer', 'exists:line_produksi,id'],
            'items.*.qty_hasil' => ['required', 'integer', 'min:0'],
            'items.*.qty_reject' => ['required', 'integer', 'min:0'],
            'items.*.jenis_cacat_id' => ['nullable', 'integer', 'exists:jenis_cacat,id'],
            'items.*.catatan' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            foreach ($this->input('items', []) as $index => $item) {
                if (($item['qty_reject'] ?? 0) > 0 && empty($item['jenis_cacat_id'])) {
                    $validator->errors()->add("items.{$index}.jenis_cacat_id", 'Jenis cacat wajib diisi jika ada reject.');
                }
                if (($item['qty_hasil'] ?? 0) + ($item['qty_reject'] ?? 0) < 1) {
                    $validator->errors()->add("items.{$index}.qty_hasil", 'Qty hasil dan reject harus berjumlah minimal 1.');
                }
            }
        }];
    }
}
