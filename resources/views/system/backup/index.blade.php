@extends('layouts.app')
@section('title', 'بکاپ کل سیستم')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">بکاپ و بازیابی کل سیستم</h1>

    <x-card title="تهیه بکاپ کامل" subtitle="شامل همه‌ی مجتمع‌ها، کاربران و داده‌های مالی">
        <form method="POST" action="{{ route('system.backup.store') }}">
            @csrf
            <x-button variant="primary">تهیه و دانلود بکاپ کامل</x-button>
        </form>
    </x-card>

    <x-card title="بازیابی از فایل بکاپ">
        <div class="tone-danger mb-3 rounded-xl px-4 py-3 text-sm">
            هشدار: بازیابی، داده‌های فعلی همه‌ی مجتمع‌ها را جایگزین می‌کند. این عمل بازگشت‌ناپذیر است.
        </div>
        <form method="POST" action="{{ route('system.backup.restore') }}" enctype="multipart/form-data" class="space-y-3"
              onsubmit="return confirm('بازیابی کامل سیستم؟ تمام داده‌های فعلی جایگزین می‌شوند.')">
            @csrf
            <input type="file" name="backup" accept=".json" required
                class="w-full rounded-xl border border-line-strong px-3 py-2 text-sm">
            @error('backup')<span class="text-xs text-danger">{{ $message }}</span>@enderror
            <x-button variant="danger">بازیابی سیستم</x-button>
        </form>
    </x-card>

    <x-card title="تاریخچه بکاپ‌های کامل">
        @if ($backups->isEmpty())
            <p class="py-6 text-center text-sm text-faint">هنوز بکاپی تهیه نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs text-faint"><tr><th class="pb-2 text-right">تاریخ</th><th class="pb-2 text-right">حجم</th><th class="pb-2 text-left">دانلود</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($backups as $b)
                        <tr>
                            <td class="py-2.5">{{ Jalali::dateTime($b->created_at) }}</td>
                            <td class="py-2.5 tabular-nums">{{ Jalali::digits(number_format(($b->size ?? 0) / 1024, 1)) }} KB</td>
                            <td class="py-2.5 text-left"><a href="{{ route('system.backup.download', $b) }}" class="text-brand-500 dark:text-brand-300 hover:underline">دانلود</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>
</div>
@endsection
