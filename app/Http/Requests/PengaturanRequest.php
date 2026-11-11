<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PengaturanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        return [
            'nama_perusahaan' => 'nullable|string|max:255',
            'alamat_perusahaan' => 'nullable|string',
            'telepon_perusahaan' => 'nullable|string|max:50',
            'toleransi_potong_persen' => 'nullable|numeric|min:0',
            'toleransi_bahan_persen' => 'nullable|numeric|min:0',
            'default_waste_persen' => 'nullable|numeric|min:0',
            'buffer_kirim_hari' => 'nullable|integer|min:0',
            'deadline_alert_hari' => 'nullable|integer|min:0',
            'backdate_hari' => 'nullable|integer|min:0',
            'batas_void_jam' => 'nullable|integer|min:0',
            'maks_lampiran_mb' => 'nullable|integer|min:1',
        ];
    }
}