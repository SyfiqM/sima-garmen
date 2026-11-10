@props(['title' => null, 'padding' => true])
<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm']) }}>
    @if ($title)
        <div class="border-b border-gray-200 px-5 py-3 font-semibold text-gray-800">{{ $title }}</div>
    @endif
    <div class="{{ $padding ? 'p-5' : '' }}">{{ $slot }}</div>
</div>
