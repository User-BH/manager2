@extends('layouts.app')
@section('title', 'بکاپ مجتمع')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">بکاپ اطلاعات مجتمع</h1>

    <x-card title="تهیه بکاپ جدید" subtitle="خروجی شامل واحدها، کاربران، مالی، قبوض و پرداخت‌ها">
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
            با کلیک روی دکمه‌ی زیر، یک فایل JSON شامل تمام داده‌های همین مجتمع تهیه و دانلود می‌شود.
            داده‌های سایر مجتمع‌ها در این بکاپ قرار نمی‌گیرند.
        </p>
        <form method="POST" action="{{ route('admin.backup.store') }}">
            @csrf
            <x-button variant="primary">تهیه و دانلود بکاپ</x-button>
        </form>
    </x-card>

    <x-card title="تاریخچه بکاپ‌ها">
        @if ($backups->isEmpty())
            <p class="py-6 text-center text-sm text-slate-400">هنوز بکاپی تهیه نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-400">
                    <tr><th class="pb-2 text-right">تاریخ</th><th class="pb-2 text-right">حجم</th><th class="pb-2 text-right">وضعیت</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @foreach ($backups as $b)
                        <tr>
                            <td class="py-2.5">{{ Jalali::dateTime($b->created_at) }}</td>
                            <td class="py-2.5 tabular-nums">{{ Jalali::digits(number_format(($b->size ?? 0) / 1024, 1)) }} KB</td>
                            <td class="py-2.5"><x-badge color="emerald">{{ $b->status }}</x-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>
</div>
@endsection
