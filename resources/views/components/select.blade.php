@props(['label' => null, 'name', 'options' => [], 'selected' => null, 'required' => false])

<label class="block">
    @if ($label)
        <span class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $label }}@if($required)<span class="text-rose-500"> *</span>@endif</span>
    @endif
    <select
        name="{{ $name }}"
        @if($required) required @endif
        {{ $attributes->merge(['class' => 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100']) }}
    >
        @foreach ($options as $val => $text)
            <option value="{{ $val }}" @selected((string) old($name, $selected) === (string) $val)>{{ $text }}</option>
        @endforeach
    </select>
    @error($name)
        <span class="mt-1 block text-xs text-rose-500">{{ $message }}</span>
    @enderror
</label>
