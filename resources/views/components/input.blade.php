@props([
    'label' => null,
    'name',
    'type' => 'text',
    'value' => null,
    'required' => false,
    'hint' => null,
    /* آیکون راهنما در ابتدای فیلد (سمت راست در RTL) */
    'icon' => null,
    /* اسلات اختیاری برای دکمهٔ انتهای فیلد، مثل نمایش/مخفی کردن رمز */
])

@php
    $padding = ($icon ? 'pr-10' : 'pr-3').' '.(isset($trailing) ? 'pl-10' : 'pl-3');
@endphp

<label class="block">
    @if ($label)
        <span class="mb-1.5 block text-sm font-medium text-muted">
            {{ $label }}@if ($required)<span class="text-danger"> *</span>@endif
        </span>
    @endif

    <span class="relative block">
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-faint">
                <x-icon :name="$icon" :size="16" />
            </span>
        @endif

        <input
            type="{{ $type }}"
            name="{{ $name }}"
            @if ($required) required @endif
            value="{{ old($name, $value) }}"
            {{ $attributes->merge(['class' => "w-full rounded-xl border border-line bg-sunken py-2.5 text-sm text-ink outline-none transition-colors duration-200 placeholder:text-faint focus:border-brand-400 focus:bg-surface focus-ring {$padding}"]) }}
        />

        @isset($trailing)
            <span class="absolute inset-y-0 left-2.5 flex items-center">{{ $trailing }}</span>
        @endisset
    </span>

    @error($name)
        <span class="mt-1 block text-xs text-danger">{{ $message }}</span>
    @else
        @if ($hint)
            <span class="mt-1 block text-xs text-faint">{{ $hint }}</span>
        @endif
    @enderror
</label>
