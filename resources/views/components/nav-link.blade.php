@props(['href', 'active' => false, 'icon' => null])

@php
    $isActive = $active || ($href !== '#' && request()->fullUrlIs($href.'*'));
    $base = 'flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition';
    $state = $isActive
        ? 'bg-sky-600 text-white shadow-sm'
        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700/60';
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => "$base $state"]) }}>
    @if ($icon)
        <span class="text-base leading-none">{!! $icon !!}</span>
    @endif
    <span>{{ $slot }}</span>
</a>
