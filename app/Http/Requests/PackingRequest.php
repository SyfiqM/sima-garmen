<?php

namespace App\Http\Requests;

use App\Models\Tahap;
use Illuminate\Foundation\Http\FormRequest;

class PackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }
        if ($user->hasRole('admin')) {
            return true;
        }

        $finishingId = Tahap::where('kode', 'finishing')->value('id');

        return $user->hasRole('supervisor') && $finishingId && (int) $user->tahap_id === (int) $finishingId;
    }

    public function rules(): array
    {
        return [
            'wo_id' => ['required', 'integer', 'exists:work_orders,id'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'berat_kg' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.wo_item_id' => ['required', 'integer', 'distinct', 'exists:wo_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }
}