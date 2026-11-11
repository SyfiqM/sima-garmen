<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class OrderAttachmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $order = Order::find($this->route('order'));

        return $order !== null && ($user?->hasRole('admin', 'manajer')
            || ($user?->hasRole('staf_order') && (int) $order->dibuat_oleh === (int) $user->id));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf,xlsx',
                'extensions:jpg,jpeg,png,pdf,xlsx',
                'max:'.((int) (DB::table('pengaturan')->where('key', 'maks_lampiran_mb')->value('value') ?? 5) * 1024),
            ],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }
}
