@extends('layouts.app')
@section('title', 'مدیریت ساکنین')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">ساکنین</h1>
        <x-button :href="route('admin.residents.create')">+ ساکن جدید</x-button>
    </div>

    <x-card>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-faint">
                    <tr>
                        <th class="pb-2 text-right">نام</th>
                        <th class="pb-2 text-right">نقش</th>
                        <th class="pb-2 text-right">واحد</th>
                        <th class="pb-2 text-right">تماس</th>
                        <th class="pb-2 text-right">وضعیت</th>
                        <th class="pb-2 text-left">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($residents as $resident)
                        <tr>
                            <td class="py-3 font-medium">{{ $resident->name }}</td>
                            <td class="py-3"><x-badge :color="$resident->role->value === 'owner' ? 'sky' : 'slate'">{{ $resident->role->label() }}</x-badge></td>
                            <td class="py-3">{{ $resident->currentUnits->map(fn($u) => 'واحد '.Jalali::digits($u->unit_number))->join('، ') ?: '-' }}</td>
                            <td class="py-3" dir="ltr">{{ $resident->phone ? Jalali::digits($resident->phone) : '-' }}</td>
                            <td class="py-3">
                                <div class="flex items-center gap-1">
                                    <x-badge :color="$resident->is_active ? 'emerald' : 'rose'">{{ $resident->is_active ? 'فعال' : 'غیرفعال' }}</x-badge>
                                    @unless ($resident->can_message)<x-badge color="amber">پیام محدود</x-badge>@endunless
                                </div>
                            </td>
                            <td class="py-3 text-left">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.residents.edit', $resident) }}" class="text-brand-500 dark:text-brand-300 hover:underline">ویرایش</a>
                                    <form method="POST" action="{{ route('admin.residents.toggle-active', $resident) }}">
                                        @csrf @method('PATCH')
                                        <button class="text-warning hover:underline">{{ $resident->is_active ? 'غیرفعال' : 'فعال' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.residents.toggle-message', $resident) }}">
                                        @csrf @method('PATCH')
                                        <button class="text-muted hover:underline">{{ $resident->can_message ? 'محدود پیام' : 'رفع محدودیت' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $residents->links() }}</div>
    </x-card>
</div>
@endsection
