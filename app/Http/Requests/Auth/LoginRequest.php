<?php

namespace App\Http\Requests\Auth;

use App\Support\Audit;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'login' => 'username atau email',
            'password' => 'password',
        ];
    }

    /** Masuk dengan username ATAU email. Hanya akun aktif yang bisa masuk. */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim((string) $this->input('login'));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $berhasil = Auth::attempt([
            $field => $login,
            'password' => (string) $this->input('password'),
            'is_active' => true,
        ], $this->boolean('remember'));

        if (! $berhasil) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'Username/email atau password salah, atau akun tidak aktif.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user);
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $detik = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => "Terlalu banyak percobaan masuk. Coba lagi dalam {$detik} detik.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('login')) . '|' . $this->ip());
    }
}
