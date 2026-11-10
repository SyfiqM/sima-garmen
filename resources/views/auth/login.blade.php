<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · {{ config('app.name', 'SIMA Garmen') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4 antialiased">
<div class="w-full max-w-md">
    <div class="mb-6 text-center">
        <h1 class="text-2xl font-bold text-gray-900">{{ config('app.name', 'SIMA Garmen') }}</h1>
        <p class="mt-1 text-sm text-gray-500">Sistem Informasi Manufaktur Garmen / Konveksi</p>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        @if ($errors->any())
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="login" class="block text-sm font-medium text-gray-700">Username atau Email</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus autocomplete="username"
                       class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="rounded border-gray-300 text-indigo-600">
                Ingat saya di perangkat ini
            </label>

            <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Masuk</button>
        </form>
    </div>

    <p class="mt-4 text-center text-xs text-gray-400">Lupa password? Hubungi Admin.</p>
</div>
</body>
</html>
