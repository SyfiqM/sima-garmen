@php
    $user = auth()->user();
    $menu = \App\Support\Menu::getForRole($user->role->value);
    $unread = $user->unreadNotifications()->count();
@endphp 
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($header) ? strip_tags((string) $header) . ' · ' : '' }}{{ config('app.name', 'SIMA Garmen') }}</title>
    <style>[x-cloak] { display: none !important; }</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 text-gray-800 antialiased" x-data="{ sidebar: false }">
<div class="min-h-screen lg:flex">

    {{-- Lapisan gelap untuk sidebar di layar kecil --}}
    <div x-show="sidebar" x-cloak @click="sidebar = false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-40 w-64 overflow-y-auto bg-gray-900 text-gray-200 transition-transform duration-200 lg:static lg:z-auto lg:translate-x-0 lg:shrink-0">
        <div class="border-b border-gray-800 px-5 py-4">
            <div class="text-lg font-bold text-white">{{ config('app.name', 'SIMA Garmen') }}</div>
            <div class="text-xs text-gray-400">{{ $user->role->label() }}</div>
        </div>

        
<nav class="space-y-1 p-3">
    @foreach ($menu as $item)
        <a href="{{ url($item['path']) }}"
            class="block rounded-md px-3 py-2 text-sm
            {{ request()->is(ltrim($item['path'], '/')) ? 'bg-indigo-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' }}">

            {{ $item['label'] }}
        </a>
    @endforeach
</nav>

    </aside>

    {{-- Konten --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-gray-200 bg-white px-4">
            <button type="button" class="rounded-md p-2 text-gray-600 hover:bg-gray-100 lg:hidden" @click="sidebar = true" aria-label="Menu">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <h1 class="min-w-0 flex-1 truncate text-lg font-semibold text-gray-800">{{ $header ?? '' }}</h1>

            <a href="{{ route('notifikasi.index') }}" class="relative rounded-md p-2 text-gray-600 hover:bg-gray-100" title="Notifikasi">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
                @if ($unread > 0)
                    <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
                @endif
            </a>

            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-100">
                    <span class="hidden sm:inline">{{ $user->name }}</span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                </button>
                <div x-show="open" x-cloak class="absolute right-0 z-50 mt-2 w-52 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                    <div class="border-b border-gray-100 px-4 py-2">
                        <div class="truncate text-sm font-medium text-gray-800">{{ $user->name }}</div>
                        <div class="text-xs text-gray-500">{{ $user->role->label() }}@if ($user->tahap) · {{ $user->tahap->nama }}@endif</div>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Profil Saya</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">Keluar</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            <x-ui.flash />
            {{ $slot }}
        </main>

        <footer class="px-6 py-3 text-xs text-gray-400">{{ config('app.name', 'SIMA Garmen') }} · Sistem Informasi Manufaktur Garmen / Konveksi</footer>
    </div>
</div>
</body>
</html>
