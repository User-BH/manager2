@props([
    'icon' => null,
    'variant' => 'ghost',
    'type' => 'button',
    'href' => null,
])

@php
    $classes = implode(' ', [
        'inline-flex h-9 w-9 items-center justify-center rounded-full text-muted',
        'transition-colors duration-200 hover:bg-sunken hover:text-ink focus-ring',
        $variant === 'outline' ? 'border border-line' : '',
    ]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="17" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="17" />@endif
        {{ $slot }}
    </button>
@endif
