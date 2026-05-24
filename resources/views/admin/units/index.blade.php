@extends('layouts.app')
@section('title', 'مدیریت واحدها')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">واحدها</h1>
        <x-button :href="route('admin.units.create')">+ واحد جدید</x-button>
    </div>

    <x-card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-400">
                    <tr>
                        <th class="pb-2 text-right">شماره</th>
                        <th class="pb-2 text-right">طبقه</th>
                        <th class="pb-2 text-right">متراژ</th>
                        <th class="pb-2 text-right">نفرات</th>
                        <th class="pb-2 text-right">ضریب</th>
                        <th class="pb-2 text-right">وضعیت</th>
                        <th class="pb-2 text-right">بدهی</th>
                        <th class="pb-2 text-left">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @foreach ($units as $unit)
                        <tr>
                            <td class="py-3 font-medium">{{ Jalali::digits($unit->unit_number) }}</td>
                            <td class="py-3">{{ Jalali::digits($unit->floor) }}</td>
                            <td class="py-3 tabular-nums">{{ Jalali::digits($unit->area) }}</td>
                            <td class="py-3 tabular-nums">{{ Jalali::digits($unit->residents_count) }}</td>
                            <td class="py-3 tabular-nums">{{ Jalali::digits($unit->coefficient) }}</td>
                            <td class="py-3"><x-badge>{{ $unit->occupancy_status->label() }}</x-badge></td>
                            <td class="py-3 tabular-nums {{ $unit->balance > 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">{{ Jalali::money($unit->balance) }}</td>
                            <td class="py-3 text-left">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.units.statement', $unit) }}" class="text-slate-500 hover:underline">تسویه‌حساب</a>
                                    <a href="{{ route('admin.units.edit', $unit) }}" class="text-sky-600 hover:underline dark:text-sky-400">ویرایش</a>
                                    <form method="POST" action="{{ route('admin.units.destroy', $unit) }}" onsubmit="return confirm('حذف این واحد؟')">
                                        @csrf @method('DELETE')
                                        <button class="text-rose-600 hover:underline dark:text-rose-400">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $units->links() }}</div>
    </x-card>
</div>
@endsection
