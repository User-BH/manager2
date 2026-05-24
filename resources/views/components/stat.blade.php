@props(['label', 'value', 'unit' => null, 'tone' => 'slate', 'icon' => null])

@php
    $tones = [
        'slate' => 'text-slate-700 dark:text-slate-200',
        'emerald' => 'text-emerald-600 dark:text-emerald-400',
        'rose' => 'text-rose-600 dark:text-rose-400',
        'amber' => 'text-amber-600 dark:text-amber-400',
        'sky' => 'text-sky-600 dark:text-sky-400',
    ];
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</p>
    <p class="mt-2 text-2xl font-bold tabular-nums {{ $tones[$tone] ?? $tones['slate'] }}">
        {{ $value }}
        @if ($unit)
            <span class="text-sm font-normal text-slate-400">{{ $unit }}</span>
        @endif
    </p>
</div>
