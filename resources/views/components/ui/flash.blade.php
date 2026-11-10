@if (session('success'))
    <div class="mb-4 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
@endif
@if (session('warning'))
    <div class="mb-4 rounded-md border border-yellow-200 bg-yellow-50 p-3 text-sm text-yellow-800">{{ session('warning') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
        Periksa kembali isian Anda. Ada {{ $errors->count() }} kesalahan.
    </div>
@endif
