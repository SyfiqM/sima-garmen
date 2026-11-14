<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

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
     */
    public function rules(): array
    {
        $maksMb = \App\Models\Pengaturan::where('key', 'maks_lampiran_mb')->value('value') ?: 5;

    return [
            // Hapus rule 'mimes' dan 'extensions' dari sini agar tidak otomatis memicu finfo
            'file' => [
                'required',
                'file',
                'max:' . ($maksMb * 1024),
            ],
            'keterangan' => 'nullable|string|max:255',
        ];
    }

    /**
     * Lakukan validasi tingkat lanjut setelah rule dasar lolos.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Jika validasi dasar (seperti ukuran file) gagal, tidak perlu lanjut
            if ($validator->errors()->has('file')) {
                return;
            }

            $file = $this->file('file');
            if (!$file) return;

            try {
                // Memanggil guessExtension() akan memicu finfo.
                // Dibungkus try-catch agar jika crash (bug Windows/Laragon), server tidak membalas 500.
                $guessed = $file->guessExtension();
                $client = strtolower($file->getClientOriginalExtension());
                $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'xlsx'];

                // Tolak jika ekstensi asli salah, ATAU isi filenya tidak cocok dengan ekstensi (menyamar)
                if (!in_array($client, $allowed) || !in_array($guessed, $allowed)) {
                    $validator->errors()->add('file', 'Format file tidak diizinkan atau isi file tidak sesuai dengan ekstensi.');
                }
            } catch (\Throwable $e) {
                // Tangkap error finfo::file() dan paksa menjadi error validasi (422)
                $validator->errors()->add('file', 'Berkas rusak, terkunci, atau terdeteksi berbahaya.');
            }
        });
    }
}