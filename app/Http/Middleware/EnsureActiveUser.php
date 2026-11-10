<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && !$request->user()->is_active) {
            if ($request->is('api/*')) {
                // Cabut token jika nonaktif
                $request->user()->currentAccessToken()->delete();
                return response()->json(['message' => 'Akun Anda telah dinonaktifkan.'], 401);
            }
            abort(403, 'Akun Anda telah dinonaktifkan.');
        }

        return $next($request);
    }
}