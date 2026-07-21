@props(['label', 'value', 'unit' => null, 'tone' => 'neutral', 'icon' => null, 'caption' => null])

@php
    /* نگاشت نام‌های قدیمی پالت به تُن‌های معنایی — مثل x-badge */
    $aliases = [
        'slate' => 'neutral',
        'gray' => 'neutral',
        'emerald' => 'success',
        'green' => 'success',
        'rose' => 'danger',
        'red' => 'danger',
        'amber' => 'warning',
        'yellow' => 'warning',
        'sky' => 'info',
        'blue' => 'info',
    ];

    $tone = $aliases[$tone] ?? $tone;

    $valueColor = match ($tone) {
        'success' => 'text-success',
        'danger' => 'text-danger',
        'warning' => 'text-warning',
        'info' => 'text-info',
        'brand' => 'text-brand-500 dark:text-brand-300',
        default => 'text-ink',
    };
@endphp

<div {{ $attributes->merge(['class' => 'group rounded-2xl border border-line bg-surface p-5 shadow-ambient transition-colors duration-200 hover:border-line-strong']) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm text-muted">{{ $label }}</p>
        @if ($icon)
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl tone-{{ $tone === 'neutral' ? 'brand' : $tone }}">
                <x-icon :name="$icon" :size="16" />
            </span>
        @endif
    </div>

    <p class="mt-2 text-2xl font-bold tabular-nums {{ $valueColor }}">
        {{ $value }}
        @if ($unit)
            <span class="text-sm font-normal text-faint">{{ $unit }}</span>
        @endif
    </p>

    @if ($caption)
        <p class="mt-1 text-xs text-faint">{{ $caption }}</p>
    @endif
</div>
