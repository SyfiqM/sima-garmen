<x-app-layout>
    <x-slot name="header">Profil Saya</x-slot>

    <div class="grid max-w-5xl gap-6 lg:grid-cols-2">
        <x-ui.card title="Data Akun">
            <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-sm font-medium text-gray-700">Username</label>
                    <div class="mt-1 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">{{ $user->username }}</div>
                    <p class="mt-1 text-xs text-gray-500">Username hanya dapat diubah oleh Admin.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Role</label>
                    <div class="mt-1 text-sm text-gray-600">
                        {{ $user->role->label() }}@if ($user->tahap) · Tahap {{ $user->tahap->nama }}@endif
                    </div>
                </div>

                <x-ui.input name="name" label="Nama" :value="$user->name" :required="true" />
                <x-ui.input name="email" label="Email" type="email" :value="$user->email" />
                <x-ui.input name="no_hp" label="No. HP" :value="$user->no_hp" />

                <x-ui.button>Simpan Profil</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Ganti Password">
            <form method="POST" action="{{ route('profile.password') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <x-ui.input name="current_password" label="Password Saat Ini" type="password" :required="true" autocomplete="current-password" />
                <x-ui.input name="password" label="Password Baru" type="password" :required="true" hint="Minimal 8 karakter." autocomplete="new-password" />
                <x-ui.input name="password_confirmation" label="Ulangi Password Baru" type="password" :required="true" autocomplete="new-password" />

                <x-ui.button>Ganti Password</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-app-layout>
