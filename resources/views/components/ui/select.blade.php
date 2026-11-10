@props(['name', 'label' => null, 'options' => [], 'selected' => null, 'required' => false, 'placeholder' => null])
<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-gray-700">{{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif</label>
    @endif
    <select id="{{ $name }}" name="{{ $name }}" @required($required)
            {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:ring-1 ' . ($errors->has($name) ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500')]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $nilai => $teks)
            <option value="{{ $nilai }}" @selected((string) old($name, $selected) === (string) $nilai)>{{ $teks }}</option>
        @endforeach
    </select>
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
