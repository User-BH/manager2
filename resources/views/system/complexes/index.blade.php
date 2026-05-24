@extends('layouts.app')
@section('title', 'مدیریت مجتمع‌ها')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">مدیریت مجتمع‌ها</h1>
        @if ($activeId)
            <form method="POST" action="{{ route('system.complexes.clear') }}">@csrf
                <x-button variant="ghost">خروج از حالت مدیریت مجتمع</x-button>
            </form>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-card title="مجتمع جدید" subtitle="به همراه حساب مدیر">
            <form method="POST" action="{{ route('system.complexes.store') }}" class="space-y-3">
                @csrf
                <x-input name="name" label="نام مجتمع" required />
                <x-input name="address" label="آدرس" />
                <hr class="border-slate-200 dark:border-slate-700">
                <x-input name="admin_name" label="نام مدیر مجتمع" required />
                <x-input name="admin_email" type="email" label="ایمیل مدیر" required dir="ltr" />
                <x-input name="admin_password" type="password" label="رمز عبور مدیر" required dir="ltr" />
                <x-button variant="primary" class="w-full">ایجاد مجتمع</x-button>
            </form>
        </x-card>

        <div class="lg:col-span-2">
            <x-card title="مجتمع‌ها">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-400">
                        <tr><th class="pb-2 text-right">نام</th><th class="pb-2 text-right">واحدها</th><th class="pb-2 text-right">کاربران</th><th class="pb-2 text-left">عملیات</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($complexes as $c)
                            <tr>
                                <td class="py-3 font-medium">
                                    {{ $c->name }}
                                    @if ($c->id === $activeId)<x-badge color="emerald">در حال مدیریت</x-badge>@endif
                                </td>
                                <td class="py-3 tabular-nums">{{ Jalali::digits($c->units_count) }}</td>
                                <td class="py-3 tabular-nums">{{ Jalali::digits($c->users_count) }}</td>
                                <td class="py-3 text-left">
                                    <form method="POST" action="{{ route('system.complexes.select', $c) }}">@csrf
                                        <x-button variant="primary" class="!px-3 !py-1.5">مدیریت</x-button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        </div>
    </div>
</div>
@endsection
