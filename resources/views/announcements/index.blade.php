@extends('layouts.app')
@section('title', 'اطلاعیه‌ها')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">اطلاعیه‌ها</h1>
        @if (auth()->user()->isAdmin())
            <x-button :href="route('admin.announcements.index')" variant="ghost">مدیریت اطلاعیه‌ها</x-button>
        @endif
    </div>

    @forelse ($announcements as $a)
        <x-card>
            <div class="flex items-start justify-between gap-2">
                <div class="flex items-center gap-2">
                    @if ($a->is_pinned)<x-badge color="amber">سنجاق‌شده</x-badge>@endif
                    <h3 class="font-semibold">{{ $a->title }}</h3>
                </div>
                <x-badge color="sky">{{ $a->audience->label() }}</x-badge>
            </div>
            <p class="mt-2 whitespace-pre-line text-sm text-muted">{{ $a->body }}</p>
            <p class="mt-3 text-xs text-faint">{{ Jalali::dateTime($a->published_at) }}</p>
        </x-card>
    @empty
        <x-card><p class="py-8 text-center text-sm text-faint">اطلاعیه‌ای موجود نیست.</p></x-card>
    @endforelse

    {{ $announcements->links() }}
</div>
@endsection
