@extends('layouts.app')
@section('title', 'داشبورد سیستم')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-6">
    <h1 class="text-xl font-bold">پنل مدیریت سیستم</h1>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat label="تعداد مجتمع‌ها" :value="Jalali::digits($complexes->count())" tone="sky" />
        <x-stat label="مجموع واحدها" :value="Jalali::digits($totalUnits)" tone="emerald" />
        <x-stat label="مجموع کاربران" :value="Jalali::digits($totalUsers)" tone="amber" />
    </div>

    <x-card title="مجتمع‌ها" subtitle="برای مدیریت یک مجتمع، آن را انتخاب کنید">
        <table class="w-full text-sm">
            <thead class="text-xs text-faint">
                <tr><th class="pb-2 text-right">نام</th><th class="pb-2 text-right">واحدها</th><th class="pb-2 text-right">کاربران</th><th class="pb-2 text-left">عملیات</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($complexes as $c)
                    <tr>
                        <td class="py-3 font-medium">{{ $c->name }}</td>
                        <td class="py-3 tabular-nums">{{ Jalali::digits($c->units_count) }}</td>
                        <td class="py-3 tabular-nums">{{ Jalali::digits($c->users_count) }}</td>
                        <td class="py-3 text-left">
                            <form method="POST" action="{{ route('system.complexes.select', $c) }}">
                                @csrf
                                <x-button variant="primary" class="!px-3 !py-1.5">انتخاب و مدیریت</x-button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <x-button :href="route('system.complexes.index')" variant="ghost" class="mt-4">مدیریت مجتمع‌ها</x-button>
    </x-card>
</div>
@endsection
