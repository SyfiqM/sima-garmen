<x-app-layout>
    <x-slot name="header">Notifikasi</x-slot>

    <div class="mb-4 flex justify-end">
        @if (auth()->user()->unreadNotifications()->count() > 0)
            <form method="POST" action="{{ route('notifikasi.baca-semua') }}">
                @csrf
                <x-ui.button variant="secondary">Tandai semua sudah dibaca</x-ui.button>
            </form>
        @endif
    </div>

    <x-ui.card :padding="false">
        <ul class="divide-y divide-gray-100">
            @forelse ($notifs as $n)
                @php
                    $warna = match ($n->data['tipe'] ?? 'info') {
                        'sukses' => 'green',
                        'peringatan' => 'yellow',
                        'bahaya' => 'red',
                        default => 'blue',
                    };
                @endphp
                <li class="flex items-start gap-3 px-5 py-3 {{ $n->read_at ? '' : 'bg-indigo-50/50' }}">
                    <x-ui.badge :color="$warna">{{ ucfirst($n->data['tipe'] ?? 'info') }}</x-ui.badge>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('notifikasi.baca', $n->id) }}" class="block text-sm {{ $n->read_at ? 'text-gray-700' : 'font-semibold text-gray-900' }} hover:underline">
                            {{ $n->data['judul'] ?? '-' }}
                        </a>
                        <p class="text-sm text-gray-600">{{ $n->data['pesan'] ?? '' }}</p>
                        <p class="mt-0.5 text-xs text-gray-400">{{ $n->created_at->diffForHumans() }}</p>
                    </div>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-gray-400">Belum ada notifikasi.</li>
            @endforelse
        </ul>
    </x-ui.card>

    <div class="mt-4">{{ $notifs->links() }}</div>
</x-app-layout>
