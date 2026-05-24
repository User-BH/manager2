@props(['title' => null, 'subtitle' => null])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800']) }}>
    @if ($title)
        <div class="mb-4 flex items-center justify-between gap-2">
            <div>
                <h3 class="font-semibold text-slate-800 dark:text-slate-100">{{ $title }}</h3>
                @if ($subtitle)
                    <p class="mt-0.5 text-xs text-slate-400">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div>{{ $actions }}</div>
            @endisset
        </div>
    @endif
    {{ $slot }}
</div>
