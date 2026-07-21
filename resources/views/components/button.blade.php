@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'submit',
    'href' => null,
    'icon' => null,
])

@php
    $variants = [
        'primary' => 'bg-brand-500 text-white hover:bg-brand-600 shadow-sm',
        'success' => 'bg-brand-400 text-white hover:bg-brand-500 shadow-sm',
        'accent' => 'bg-accent-500 text-white hover:bg-accent-600 shadow-sm',
        'danger' => 'bg-danger text-white hover:opacity-90 shadow-sm',
        'ghost' => 'bg-sunken text-muted hover:bg-line hover:text-ink',
        'outline' => 'border border-line-strong text-ink hover:bg-sunken',
        'quiet' => 'text-muted hover:bg-sunken hover:text-ink',
    ];

    $sizes = [
        'sm' => 'gap-1.5 rounded-lg px-3 py-1.5 text-xs',
        'md' => 'gap-2 rounded-xl px-4 py-2 text-sm',
        'lg' => 'gap-2 rounded-2xl px-6 py-3 text-sm',
    ];

    $classes = implode(' ', [
        'inline-flex items-center justify-center font-medium transition-colors duration-200 focus-ring',
        $sizes[$size] ?? $sizes['md'],
        $variants[$variant] ?? $variants['primary'],
    ]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="$size === 'sm' ? 14 : 16" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="$size === 'sm' ? 14 : 16" />@endif
        {{ $slot }}
    </button>
@endif
