@props([
    'size' => 34,
    'subtitle' => null,
    'monochrome' => false,
    'href' => null,
])

@php
    $tag = $href ? 'a' : 'div';
    $titleColor = $monochrome ? 'text-white' : 'text-ink';
    $subColor = $monochrome ? 'text-white/70' : 'text-faint';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'flex items-center gap-2.5']) }}>
    <x-logo-mark :size="$size" :monochrome="$monochrome" class="shrink-0" />

    <div class="leading-tight">
        <p class="text-sm font-bold {{ $titleColor }}">{{ config('brand.name') }}</p>
        @if ($subtitle)
            <p class="text-xs {{ $subColor }}">{{ $subtitle }}</p>
        @endif
    </div>
</{{ $tag }}>
