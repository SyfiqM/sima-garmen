@props(['variant' => 'primary', 'href' => null, 'type' => 'submit'])
@php
    $gaya = [
        'primary' => 'bg-indigo-600 text-white hover:bg-indigo-700',
        'secondary' => 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        'success' => 'bg-green-600 text-white hover:bg-green-700',
    ];
    $kelas = 'inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium shadow-sm transition ' . ($gaya[$variant] ?? $gaya['primary']);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $kelas]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $kelas]) }}>{{ $slot }}</button>
@endif
