<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $tipe = $this->route('tipe_master');

        if ($user?->hasRole('admin')) return true;

        if ($user?->hasRole('manajer')) {
            return in_array($tipe, ['pelanggan', 'supplier', 'produk', 'ukuran', 'warna', 'satuan', 'jenis-cacat', 'line-produksi']);
        }
        if ($user?->hasRole('staf_order')) {
            return $tipe === 'pelanggan';
        }
        if ($user?->hasRole('gudang')) {
            return $tipe === 'supplier';
        }

        return false;
    }

    public function rules(): array
    {
        $tipe = $this->route('tipe_master');
        $id = $this->route('id');
        $tableName = str_replace('-', '_', $tipe);
        
        // Pengecualian nama tabel plural untuk master tertentu
        if ($tipe === 'jenis-cacat') $tableName = 'jenis_cacat';
        if ($tipe === 'line-produksi') $tableName = 'line_produksi';

        $rules = [
            'nama' => 'required|string|max:150',
            'is_active' => 'boolean'
        ];

        // Validasi unik untuk kolom kode (kecuali warna yang tidak punya kode)
        if ($tipe !== 'warna') {
            $rules['kode'] = ['nullable', 'string', 'max:30', Rule::unique($tableName, 'kode')->ignore($id)];
        }

        // Tambahan spesifik per master
        if ($tipe === 'warna') {
            $rules['kode_hex'] = 'nullable|string|max:7';
        } elseif ($tipe === 'ukuran' || $tipe === 'tahap') {
            $rules['urutan'] = 'nullable|integer|min:1';
        } elseif ($tipe === 'line-produksi') {
            $rules['tahap_id'] = 'required|exists:tahap,id';
            $rules['kapasitas_per_hari'] = 'nullable|integer|min:0';
        } elseif ($tipe === 'jenis-cacat') {
            $rules['kategori'] = 'required|in:kain,potongan,jahitan,ukuran,kotor,lainnya';
            $rules['tahap_id'] = 'nullable|exists:tahap,id';
        } elseif ($tipe === 'pelanggan' || $tipe === 'supplier') {
            $rules['kontak_person'] = 'nullable|string|max:100';
            $rules['telepon'] = 'nullable|string|max:25';
            $rules['email'] = 'nullable|email|max:150';
            $rules['alamat'] = 'nullable|string';
            if ($tipe === 'pelanggan') $rules['kota'] = 'nullable|string|max:80';
        }

        return $rules;
    }
}