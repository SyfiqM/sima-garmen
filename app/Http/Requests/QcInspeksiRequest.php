<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class QcInspeksiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('admin', 'qc');
    }

    public function rules(): array
    {
        return [
            'wo_id' => ['required', 'integer', 'exists:work_orders,id'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.wo_item_id' => ['required', 'integer', 'distinct', 'exists:wo_items,id'],
            'items.*.qty_diperiksa' => ['required', 'integer', 'min:1'],
            'items.*.qty_lolos' => ['required', 'integer', 'min:0'],
            'items.*.qty_repair' => ['required', 'integer', 'min:0'],
            'items.*.qty_reject' => ['required', 'integer', 'min:0'],
            'items.*.tahap_tujuan_id' => ['nullable', 'integer', 'exists:tahap,id'],
            'items.*.cacat' => ['nullable', 'array'],
            'items.*.cacat.*.jenis_cacat_id' => ['required', 'integer', 'exists:jenis_cacat,id'],
            'items.*.cacat.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.cacat.*.tindakan' => ['required', 'in:repair,reject'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('items', []) as $i => $item) {
                    $diperiksa = (int) ($item['qty_diperiksa'] ?? 0);
                    $lolos = (int) ($item['qty_lolos'] ?? 0);
                    $repair = (int) ($item['qty_repair'] ?? 0);
                    $reject = (int) ($item['qty_reject'] ?? 0);

                    if ($diperiksa !== $lolos + $repair + $reject) {
                        $validator->errors()->add("items.{$i}.qty_diperiksa", 'Total diperiksa harus sama dengan jumlah lolos, repair, dan reject.');
                    }

                    $sumRepair = 0;
                    $sumReject = 0;
                    foreach ((array) ($item['cacat'] ?? []) as $c) {
                        if (($c['tindakan'] ?? null) === 'repair') {
                            $sumRepair += (int) ($c['qty'] ?? 0);
                        } elseif (($c['tindakan'] ?? null) === 'reject') {
                            $sumReject += (int) ($c['qty'] ?? 0);
                        }
                    }

                    if ($sumRepair !== $repair) {
                        $validator->errors()->add("items.{$i}.cacat", 'Jumlah cacat bertindakan repair harus sama dengan qty repair.');
                    }
                    if ($sumReject !== $reject) {
                        $validator->errors()->add("items.{$i}.cacat", 'Jumlah cacat bertindakan reject harus sama dengan qty reject.');
                    }
                }
            },
        ];
    }
}