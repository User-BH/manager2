@extends('layouts.app')
@section('title', 'هزینه‌ها و درآمدها')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-bold">هزینه‌ها و درآمدها — {{ Jalali::periodLabel($period) }}</h1>
        <form method="GET" class="flex items-center gap-2">
            <input type="text" name="period" value="{{ $period }}" dir="ltr" placeholder="1404-03"
                class="w-32 rounded-xl border border-slate-300 px-3 py-1.5 text-sm dark:border-slate-600 dark:bg-slate-900">
            <x-button variant="ghost" class="!py-1.5">نمایش</x-button>
        </form>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-stat label="جمع هزینه‌های دوره" :value="Jalali::money($expenseTotal)" tone="rose" />
        <x-stat label="جمع درآمدهای دوره" :value="Jalali::money($incomeTotal)" tone="emerald" />
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Expenses --}}
        <x-card title="هزینه‌ها">
            <form method="POST" action="{{ route('admin.expenses.store') }}" class="mb-4 space-y-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-700/40">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}">
                <x-input name="title" label="عنوان هزینه" required />
                <div class="grid grid-cols-2 gap-3">
                    <x-input name="amount" type="number" label="مبلغ" required />
                    <x-select name="category" label="دسته" :options="['tenant' => 'مستاجرانه', 'owner' => 'مالکانه']" required />
                </div>
                <x-select name="split_method" label="روش تقسیم بین واحدها" :options="collect($splitMethods)->prepend('— تقسیم نشود (فقط از صندوق)', '')->toArray()" />
                <x-button variant="primary" class="w-full">ثبت هزینه</x-button>
            </form>

            <div class="space-y-2">
                @forelse ($expenses as $e)
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2 text-sm dark:border-slate-700">
                        <div>
                            <span class="font-medium">{{ $e->title }}</span>
                            <x-badge :color="$e->category->value === 'owner' ? 'sky' : 'slate'">{{ $e->category->label() }}</x-badge>
                            @if ($e->is_distributed)<x-badge color="amber">تقسیم‌شده</x-badge>@endif
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="tabular-nums text-rose-600 dark:text-rose-400">{{ Jalali::money($e->amount) }}</span>
                            <form method="POST" action="{{ route('admin.expenses.destroy', $e) }}" onsubmit="return confirm('حذف؟')">@csrf @method('DELETE')
                                <button class="text-xs text-rose-500 hover:underline">حذف</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="py-4 text-center text-sm text-slate-400">هزینه‌ای ثبت نشده است.</p>
                @endforelse
            </div>
        </x-card>

        {{-- Incomes --}}
        <x-card title="درآمدها">
            <form method="POST" action="{{ route('admin.incomes.store') }}" class="mb-4 space-y-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-700/40">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}">
                <x-input name="title" label="عنوان درآمد" required />
                <div class="grid grid-cols-2 gap-3">
                    <x-input name="amount" type="number" label="مبلغ" required />
                    <x-input name="source" label="منبع" />
                </div>
                <x-button variant="success" class="w-full">ثبت درآمد</x-button>
            </form>

            <div class="space-y-2">
                @forelse ($incomes as $i)
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2 text-sm dark:border-slate-700">
                        <span class="font-medium">{{ $i->title }}</span>
                        <div class="flex items-center gap-3">
                            <span class="tabular-nums text-emerald-600 dark:text-emerald-400">{{ Jalali::money($i->amount) }}</span>
                            <form method="POST" action="{{ route('admin.incomes.destroy', $i) }}" onsubmit="return confirm('حذف؟')">@csrf @method('DELETE')
                                <button class="text-xs text-rose-500 hover:underline">حذف</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="py-4 text-center text-sm text-slate-400">درآمدی ثبت نشده است.</p>
                @endforelse
            </div>
        </x-card>
    </div>
</div>
@endsection
