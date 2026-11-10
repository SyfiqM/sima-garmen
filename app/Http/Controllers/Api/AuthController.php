<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Helpers\Audit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string', // bisa username atau email
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        $key = 'login.' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'login' => ["Terlalu banyak percobaan. Coba lagi dalam $seconds detik."],
            ]);
        }

        $user = User::where('username', $request->login)
                    ->orWhere('email', $request->login)
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages([
                'login' => ['Kredensial tidak valid.'],
            ]);
        }

        if (!$user->is_active) {
            RateLimiter::hit($key);
            return response()->json(['message' => 'Akun Anda telah dinonaktifkan.'], 401);
        }

        RateLimiter::clear($key);

        $user->update(['last_login_at' => now()]);
        
        // Asumsi fungsi log: log($userId, $aksi, $tipe, $subjekId, $sebelum, $sesudah, $alasan)
        if (class_exists(Audit::class)) {
            Audit::log($user->id, 'auth.login', 'users', $user->id, null, null, 'Login via API');
        }

        $tokenName = $request->device_name ?? 'sima-web';
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new \App\Http\Resources\UserResource($user),
            ]
        ]);
    }

    public function logout(Request $request)
    {
        if (class_exists(Audit::class)) {
            Audit::log($request->user()->id, 'auth.logout', 'users', $request->user()->id, null, null, 'Logout via API');
        }
        
        $request->user()->currentAccessToken()->delete();
        return response()->json(null, 204);
    }
}