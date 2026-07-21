@props(['type' => 'info', 'title' => null])

@php
    $tones = [
        'success' => ['tone' => 'success', 'icon' => 'check-circle'],
        'error' => ['tone' => 'danger', 'icon' => 'x-circle'],
        'danger' => ['tone' => 'danger', 'icon' => 'x-circle'],
        'warning' => ['tone' => 'warning', 'icon' => 'alert'],
        'info' => ['tone' => 'info', 'icon' => 'info'],
    ];

    $config = $tones[$type] ?? $tones['info'];
@endphp

<div {{ $attributes->merge(['class' => "tone-{$config['tone']} flex items-start gap-2.5 rounded-xl px-4 py-3 text-sm"]) }}>
    <x-icon :name="$config['icon']" :size="17" class="mt-0.5" />
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div class="{{ $title ? 'mt-0.5' : '' }}">{{ $slot }}</div>
    </div>
</div>
