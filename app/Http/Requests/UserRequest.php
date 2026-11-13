<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('pengguna') ?? $this->route('id');
        $rules = [
            'name' => 'required|string|max:100',
            'username' => ['required', 'string', 'max:50', Rule::unique('users')->ignore($id)],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users')->ignore($id)],
            'role' => 'required|in:admin,manajer,staf_order,gudang,supervisor,qc,pimpinan',
            'no_hp' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ];

        // Supervisor wajib punya tahap_id
        if ($this->input('role') === 'supervisor') {
            $rules['tahap_id'] = 'required|exists:tahap,id';
        } else {
            $rules['tahap_id'] = 'nullable|exists:tahap,id';
        }

        // Password wajib saat pembuatan baru
        if ($this->isMethod('post')) {
            $rules['password'] = 'required|string|min:8';
        } else {
            $rules['password'] = 'nullable|string|min:8';
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'tahap_id.required' => 'Tahap wajib diisi untuk role Supervisor.',
            'tahap_id.exists' => 'Tahap tidak valid.',
        ];
    }
}
