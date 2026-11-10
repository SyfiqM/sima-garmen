<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Http\Resources\UserResource;

class ProfileController extends Controller
{
    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    public function updateProfil(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users')->ignore($user->id)],
            'no_hp' => 'nullable|string|max:20',
        ]);

        $user->update($validated);
        return new UserResource($user);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'password_lama' => 'required|current_password',
            'password_baru' => 'required|string|min:8|confirmed',
        ]);

        $request->user()->update([
            'password' => Hash::make($request->password_baru)
        ]);

        return response()->json(['message' => 'Password berhasil diubah.']);
    }
}