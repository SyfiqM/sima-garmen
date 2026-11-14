<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Http\Requests\UserRequest;
use App\Exceptions\BusinessRuleException;
use App\Helpers\Audit;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->user()->hasRole('admin')) abort(403);

        $query = User::with('tahap'); // Jika ada relasi tahap
        if ($request->search) {
            $search = '%' . $request->search . '%';
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('name', 'like', $search)->orWhere('username', 'like', $search);
            });
        }
        if ($request->has('role')) $query->where('role', $request->role);
        
        return response()->json($query->paginate($request->per_page ?? 25));
    }

    public function store(UserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);
        
        // Kosongkan tahap_id jika bukan supervisor (pencegahan ekstra)
        if ($data['role'] !== 'supervisor') $data['tahap_id'] = null;

        $user = User::create($data);
        if (class_exists(Audit::class)) Audit::log($request->user()->id, 'user.create', 'User', $user->id, null, $user->toArray(), 'Buat pengguna baru');
        return response()->json(['data' => $user], 201);
    }

    public function show(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) abort(403);
        return response()->json(['data' => User::findOrFail($id)]);
    }

    public function update(UserRequest $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validated();
        
        $passwordBerubah = !empty($data['password']);
        if ($passwordBerubah) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if ($data['role'] !== 'supervisor') $data['tahap_id'] = null;

        $this->cekAdminTerakhir($user, $data['role'] ?? $user->role, $data['is_active'] ?? $user->is_active);

        $sebelum = $user->toArray();
        $user->update($data);
        if ($passwordBerubah || ($data['is_active'] ?? true) === false) {
            $user->tokens()->delete();
        }

        if (class_exists(Audit::class)) Audit::log($request->user()->id, 'user.update', 'User', $user->id, $sebelum, $user->toArray(), 'Update pengguna');
        return response()->json(['data' => $user]);
    }

    public function destroy(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) abort(403);
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            throw new BusinessRuleException('Anda tidak dapat menghapus akun Anda sendiri.', 'STATUS_TIDAK_VALID', 409);
        }
        $this->cekAdminTerakhir($user, 'non_admin', 0); // Simulasi hapus untuk cek

        $user->tokens()->delete();
        $user->delete();
        if (class_exists(Audit::class)) Audit::log($request->user()->id, 'user.delete', 'User', $user->id, $user->toArray(), null, 'Hapus pengguna');
        return response()->json(null, 204);
    }

    public function resetPassword(Request $request, $id)
    {
        if (!$request->user()->hasRole('admin')) abort(403);
        $request->validate(['password' => 'required|string|min:8']);
        
        $user = User::findOrFail($id);
        $user->update(['password' => Hash::make($request->password)]);
        $user->tokens()->delete(); // sesi lama tidak boleh tetap berlaku setelah reset
        
        if (class_exists(Audit::class)) Audit::log($request->user()->id, 'user.reset_password', 'User', $user->id, null, null, 'Reset password oleh Admin');
        return response()->json(['message' => 'Password berhasil di-reset.']);
    }

    public function aktifkan(Request $request, int $id)
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        $user = User::findOrFail($id);
        $sebelum = $user->only(['role', 'is_active']);
        $user->update(['is_active' => true]);
        Audit::log($request->user()->id, 'user.aktifkan', 'User', $user->id, $sebelum, $user->only(['role', 'is_active']));
        return response()->json(['data' => $user]);
    }

    public function nonaktifkan(Request $request, int $id)
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            throw new BusinessRuleException('Anda tidak dapat menonaktifkan akun sendiri.', 'STATUS_TIDAK_VALID', 409);
        }

        $currentRole = $user->role instanceof \BackedEnum ? $user->role->value : $user->role;
        $this->cekAdminTerakhir($user, $currentRole, false);
        $sebelum = $user->only(['role', 'is_active']);
        $user->update(['is_active' => false]);
        $user->tokens()->delete();
        Audit::log($request->user()->id, 'user.nonaktifkan', 'User', $user->id, $sebelum, $user->only(['role', 'is_active']));
        return response()->json(['data' => $user]);
    }

    private function cekAdminTerakhir($user, $newRole, $newActive)
    {
        $currentRole = $user->role instanceof \BackedEnum ? $user->role->value : $user->role;
        if ($currentRole === 'admin' && ($newRole !== 'admin' || !$newActive)) {
            $adminAktifLain = User::where('role', 'admin')->where('is_active', 1)->where('id', '!=', $user->id)->count();
            if ($adminAktifLain === 0) {
                throw new BusinessRuleException('Tidak dapat mengubah/menonaktifkan admin terakhir di sistem.', 'STATUS_TIDAK_VALID', 409);
            }
        }
    }
}