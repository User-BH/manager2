@extends('layouts.app')
@section('title', 'مدیریت اطلاعیه‌ها')

@php use App\Support\Jalali; use App\Enums\AnnouncementAudience; @endphp

@section('content')
<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    <x-card title="اطلاعیه جدید">
        <form method="POST" action="{{ route('admin.announcements.store') }}" class="space-y-3">
            @csrf
            <x-input name="title" label="عنوان" required />
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-ink">متن</span>
                <textarea name="body" rows="4" required
                    class="w-full rounded-xl border border-line-strong px-3 py-2 text-sm">{{ old('body') }}</textarea>
                @error('body')<span class="text-xs text-danger">{{ $message }}</span>@enderror
            </label>
            <x-select name="audience" label="مخاطب" :options="AnnouncementAudience::options()" required />
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_pinned" value="1" class="rounded border-line-strong text-brand-500 dark:text-brand-300">
                سنجاق کردن (نمایش در بالا)
            </label>
            <x-button variant="primary" class="w-full">انتشار اطلاعیه</x-button>
        </form>
    </x-card>

    <div class="space-y-3 lg:col-span-2">
        @forelse ($announcements as $a)
            <x-card>
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        @if ($a->is_pinned)<x-badge color="amber">سنجاق</x-badge>@endif
                        <h3 class="font-semibold">{{ $a->title }}</h3>
                        <x-badge color="sky">{{ $a->audience->label() }}</x-badge>
                    </div>
                    <form method="POST" action="{{ route('admin.announcements.destroy', $a) }}" onsubmit="return confirm('حذف اطلاعیه؟')">@csrf @method('DELETE')
                        <button class="text-xs text-danger hover:underline">حذف</button>
                    </form>
                </div>
                <p class="mt-2 whitespace-pre-line text-sm text-muted">{{ $a->body }}</p>
                <p class="mt-2 text-xs text-faint">{{ Jalali::dateTime($a->published_at ?? $a->created_at) }}</p>
            </x-card>
        @empty
            <x-card><p class="py-8 text-center text-sm text-faint">اطلاعیه‌ای ثبت نشده است.</p></x-card>
        @endforelse
    </div>
</div>
@endsection
