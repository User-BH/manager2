@extends('layouts.app')
@section('title', 'قوانین شارژ')

@php use App\Support\Jalali; use App\Enums\ChargeRuleType; @endphp

@section('content')
<div class="space-y-4">
    <h1 class="text-xl font-bold">قوانین محاسبه شارژ</h1>
    <p class="text-sm text-faint">این قوانین به‌صورت ماهانه روی همه واحدها اعمال می‌شوند. هزینه‌های قابل‌تقسیم در بخش «هزینه‌ها» ثبت می‌شوند.</p>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="قوانین فعلی">
                @if ($rules->isEmpty())
                    <p class="py-8 text-center text-sm text-faint">هنوز قانونی تعریف نشده است.</p>
                @else
                    <div class="space-y-2">
                        @foreach ($rules as $rule)
                            <div class="flex items-center justify-between rounded-xl border border-line p-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium">{{ $rule->name }}</span>
                                        <x-badge :color="$rule->category->value === 'owner' ? 'sky' : 'slate'">{{ $rule->category->label() }}</x-badge>
                                        @unless ($rule->is_active)<x-badge color="rose">غیرفعال</x-badge>@endunless
                                    </div>
                                    <p class="mt-1 text-xs text-faint">
                                        {{ $rule->type->label() }}
                                        @if ($rule->type->isPoolBased()) — استخر: {{ Jalali::money($rule->pool_amount) }} @endif
                                        @if (! empty($rule->config['amount'])) — مبلغ: {{ Jalali::money($rule->config['amount']) }} @endif
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('admin.charge-rules.toggle', $rule) }}">@csrf @method('PATCH')
                                        <button class="text-xs text-warning hover:underline">{{ $rule->is_active ? 'غیرفعال' : 'فعال' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.charge-rules.destroy', $rule) }}" onsubmit="return confirm('حذف این قانون؟')">@csrf @method('DELETE')
                                        <button class="text-xs text-danger hover:underline">حذف</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <x-card title="افزودن قانون جدید">
            <form method="POST" action="{{ route('admin.charge-rules.store') }}" class="space-y-3" x-data="{ type: 'fixed' }">
                @csrf
                <x-input name="name" label="نام قانون" required />
                <x-select name="type" label="نوع محاسبه" :options="ChargeRuleType::options()" x-model="type" required />
                <x-select name="category" label="دسته هزینه" :options="['tenant' => 'مستاجرانه', 'owner' => 'مالکانه']" required />

                <template x-if="['fixed','per_person','per_area'].includes(type)">
                    <x-input name="amount" type="number" step="0.01" label="مبلغ (ثابت / به‌ازای نفر / به‌ازای متر)" />
                </template>

                <template x-if="type === 'combined'">
                    <div class="space-y-3">
                        <x-input name="base" type="number" label="مبلغ پایه ثابت" />
                        <x-input name="per_area_rate" type="number" label="نرخ هر متر مربع" />
                        <x-input name="per_person_rate" type="number" label="نرخ هر نفر" />
                    </div>
                </template>

                <template x-if="['utility_by_person','by_unit_count','by_coefficient','elevator_by_floor'].includes(type)">
                    <x-input name="pool_amount" type="number" label="مبلغ کل قابل تقسیم (ماهانه)" />
                </template>

                <template x-if="type === 'elevator_by_floor'">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="exempt_ground_floor" value="1" checked class="rounded border-line-strong text-brand-500 dark:text-brand-300">
                        طبقه همکف از هزینه آسانسور معاف باشد
                    </label>
                </template>

                <x-button variant="primary" class="w-full">افزودن قانون</x-button>
            </form>
        </x-card>
    </div>
</div>
@endsection
