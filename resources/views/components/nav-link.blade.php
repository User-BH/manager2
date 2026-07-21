@props([
    'href',
    'icon' => null,
    'active' => null,
    /* الگوی تشخیص فعال بودن؛ پیش‌فرض خود href با هر زیرمسیرش */
    'match' => null,
])

@php
    $pattern = $match ?? ($href !== '#' ? $href.'*' : null);
    $isActive = $active ?? ($pattern ? request()->fullUrlIs($pattern) : false);
@endphp

{{-- title بومی نگه داشته می‌شود تا در حالت جمع‌شدهٔ سایدبار که برچسب پنهان است
     عنوان آیتم قابل‌خواندن بماند؛ تولتیپ سفارشی به‌کار نمی‌آید چون نوار منو
     overflow-y-auto دارد و هر عنصر absolute بیرون از آن بریده می‌شود. --}}
<a
    href="{{ $href }}"
    title="{{ trim($slot) }}"
    @if ($isActive) aria-current="page" @endif
    {{ $attributes->merge([
        'class' => 'sidebar-item group relative flex items-center gap-3 rounded-xl px-2.5 py-2.5 text-[13.5px] font-medium transition-colors duration-150 focus-ring '
            .($isActive ? 'bg-brand-500 text-on-brand shadow-sm' : 'text-muted hover:bg-sunken hover:text-ink'),
    ]) }}
>
    @if ($icon)
        <span class="flex shrink-0 items-center justify-center transition-transform duration-200 group-hover:scale-110">
            <x-icon :name="$icon" :size="19" :stroke="1.9" />
        </span>
    @endif

    <span class="sidebar-label whitespace-nowrap">{{ $slot }}</span>
</a>
