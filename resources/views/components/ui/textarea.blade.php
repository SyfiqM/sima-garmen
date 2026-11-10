@props(['name', 'label' => null, 'value' => null, 'required' => false, 'rows' => 3])
<div>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-gray-700">{{ $label }}@if ($required)<span class="text-red-500"> *</span>@endif</label>
    @endif
    <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}" @required($required)
              {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:ring-1 ' . ($errors->has($name) ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500')]) }}>{{ old($name, $value) }}</textarea>
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
