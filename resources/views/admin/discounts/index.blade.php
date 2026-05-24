@extends('layouts.app')
@section('title', 'تخفیف و بخشودگی')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-bold">تخفیف و بخشودگی — {{ Jalali::periodLabel($period) }}</h1>
        <form method="GET" class="flex items-center gap-2">
            <input type="text" name="period" value="{{ $period }}" dir="ltr" placeholder="1404-03"
                class="w-32 rounded-xl border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
            <x-button variant="ghost" class="!py-1.5">نمایش</x-button>
        </form>
    </div>
    <p class="text-sm text-slate-400">تخفیف برای هر واحد در دوره‌ی مشخص ثبت می‌شود و هنگام صدور قبض از مبلغ کل کسر می‌گردد.</p>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-card title="ثبت تخفیف">
            <form method="POST" action="{{ route('admin.discounts.store') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}">
                <x-select name="unit_id" label="واحد" :options="$units->pluck('unit_number', 'id')->mapWithKeys(fn($v,$k) => [$k => 'واحد '.$v])->toArray()" required />
                <x-input name="amount" type="number" label="مبلغ تخفیف" required />
                <x-input name="reason" label="علت (اختیاری)" />
                <x-button variant="primary" class="w-full">ثبت تخفیف</x-button>
            </form>
        </x-card>

        <div class="lg:col-span-2">
            <x-card title="تخفیف‌های ثبت‌شده‌ی این دوره">
                @if ($discounts->isEmpty())
                    <p class="py-8 text-center text-sm text-slate-400">تخفیفی ثبت نشده است.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="text-xs text-slate-400">
                            <tr><th class="pb-2 text-right">واحد</th><th class="pb-2 text-right">مبلغ</th><th class="pb-2 text-right">علت</th><th class="pb-2 text-left">عملیات</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            @foreach ($discounts as $d)
                                <tr>
                                    <td class="py-2.5">واحد {{ Jalali::digits($d->unit->unit_number) }}</td>
                                    <td class="py-2.5 tabular-nums text-emerald-600 dark:text-emerald-400">{{ Jalali::money($d->amount) }}</td>
                                    <td class="py-2.5 text-slate-500">{{ $d->reason ?: '-' }}</td>
                                    <td class="py-2.5 text-left">
                                        <form method="POST" action="{{ route('admin.discounts.destroy', $d) }}" onsubmit="return confirm('حذف تخفیف؟')">@csrf @method('DELETE')
                                            <button class="text-rose-600 hover:underline dark:text-rose-400">حذف</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-card>
        </div>
    </div>
</div>
@endsection
