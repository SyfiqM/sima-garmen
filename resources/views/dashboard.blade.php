<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    <div class="mb-6 flex flex-wrap items-center gap-2 text-gray-600">
        <span>Selamat datang, <span class="font-semibold text-gray-900">{{ auth()->user()->name }}</span></span>
        <x-ui.badge :color="auth()->user()->role->color()">{{ auth()->user()->role->label() }}</x-ui.badge>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6">
        @foreach ($stat as $label => $nilai)
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="text-xs text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($nilai, 0, ',', '.') }}</div>
            </div>
        @endforeach
    </div>

    
<x-ui.card title="Akses Cepat" class="mt-6">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($menu as $item)
            @continue($item['path'] === '/dashboard')

            <a href="{{ url($item['path']) }}"
                class="rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:border-indigo-300 hover:bg-indigo-50">

                <div class="text-[11px] uppercase tracking-wide text-gray-400">
                    {{ $item['icon'] }}
                </div>

                {{ $item['label'] }}
            </a>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-gray-400">
        Dashboard lengkap per role (grafik, WIP, KPI) dibangun pada Batch 8.
        Menu bertanda "segera" aktif seiring batch berikutnya.
    </p>
</x-ui.card>
</x-app-layout>
