@extends('layouts.app')
@section('title', $unit->exists ? 'ویرایش واحد' : 'واحد جدید')

@php use App\Enums\OccupancyStatus; @endphp

@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">{{ $unit->exists ? 'ویرایش واحد '.$unit->unit_number : 'ثبت واحد جدید' }}</h1>

    <x-card>
        <form method="POST" action="{{ $unit->exists ? route('admin.units.update', $unit) : route('admin.units.store') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @if ($unit->exists) @method('PUT') @endif

            <x-input name="unit_number" label="شماره واحد" :value="$unit->unit_number" required />
            <x-input name="floor" type="number" label="طبقه" :value="$unit->floor" required />
            <x-input name="area" type="number" step="0.01" label="متراژ (متر مربع)" :value="$unit->area" required />
            <x-input name="residents_count" type="number" label="تعداد نفرات ساکن" :value="$unit->residents_count" required />
            <x-input name="parking_count" type="number" label="تعداد پارکینگ" :value="$unit->parking_count ?? 0" />
            <x-input name="coefficient" type="number" step="0.01" label="ضریب اختصاصی" :value="$unit->coefficient" required />

            @if ($buildings->isNotEmpty())
                <x-select name="building_id" label="ساختمان / بلوک" :options="$buildings->pluck('name', 'id')->prepend('—', '')->toArray()" :selected="$unit->building_id" />
            @endif

            <x-select name="occupancy_status" label="وضعیت سکونت" :options="OccupancyStatus::options()" :selected="$unit->occupancy_status?->value" required />

            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="checkbox" name="uses_elevator" value="1" @checked($unit->uses_elevator ?? true) class="rounded border-line-strong text-brand-500 dark:text-brand-300">
                این واحد از آسانسور استفاده می‌کند
            </label>

            <div class="sm:col-span-2">
                <x-input name="notes" label="یادداشت" :value="$unit->notes" />
            </div>

            <div class="flex gap-2 sm:col-span-2">
                <x-button variant="primary">ذخیره</x-button>
                <x-button :href="route('admin.units.index')" variant="ghost" type="button">انصراف</x-button>
            </div>
        </form>
    </x-card>
</div>
@endsection
