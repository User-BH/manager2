@props(['title' => null, 'subtitle' => null, 'icon' => null, 'padding' => 'p-5'])

<div {{ $attributes->merge(['class' => "rounded-2xl border border-line bg-surface shadow-ambient {$padding}"]) }}>
    @if ($title)
        <div class="mb-4 flex items-start justify-between gap-3">
            <div class="flex items-center gap-2.5">
                @if ($icon)
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl tone-brand">
                        <x-icon :name="$icon" :size="17" />
                    </span>
                @endif
                <div>
                    <h3 class="font-semibold text-ink">{{ $title }}</h3>
                    @if ($subtitle)
                        <p class="mt-0.5 text-xs text-faint">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>
            @isset($actions)
                <div class="shrink-0">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    {{ $slot }}
</div>
