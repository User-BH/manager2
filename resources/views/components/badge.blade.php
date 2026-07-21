@props(['color' => 'neutral', 'icon' => null])

@php
    /* نام‌های قدیمی رنگ‌ها (پالت اسلیت/آسمانی) به تُن‌های معنایی جدید نگاشت
       می‌شوند تا فراخوانی‌های موجود در ویوها بدون تغییر کار کنند. */
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

    $tone = $aliases[$color] ?? $color;
    $tone = in_array($tone, ['neutral', 'brand', 'success', 'danger', 'warning', 'info'], true) ? $tone : 'neutral';
@endphp

<span {{ $attributes->merge(['class' => "tone-{$tone} inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"]) }}>
    @if ($icon)<x-icon :name="$icon" :size="12" />@endif
    {{ $slot }}
</span>
