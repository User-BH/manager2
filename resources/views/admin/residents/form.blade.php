@extends('layouts.app')
@section('title', $resident->exists ? 'ویرایش ساکن' : 'ساکن جدید')

@php use App\Enums\ResidentRelation; @endphp
@php($currentUnitId = $resident->currentUnits->first()?->id)

@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <h1 class="text-xl font-bold">{{ $resident->exists ? 'ویرایش '.$resident->name : 'ثبت ساکن جدید' }}</h1>

    <x-card>
        <form method="POST" action="{{ $resident->exists ? route('admin.residents.update', $resident) : route('admin.residents.store') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @if ($resident->exists) @method('PUT') @endif

            <x-input name="name" label="نام و نام خانوادگی" :value="$resident->name" required />
            <x-input name="email" type="email" label="ایمیل (نام کاربری)" :value="$resident->email" required dir="ltr" />
            <x-input name="phone" label="شماره تماس" :value="$resident->phone" dir="ltr" />
            <x-input name="national_id" label="کد ملی" :value="$resident->national_id" dir="ltr" />
            <x-select name="role" label="نقش" :options="ResidentRelation::options()" :selected="$resident->role?->value" required />
            <x-select name="unit_id" label="واحد" :options="$units->pluck('unit_number', 'id')->mapWithKeys(fn($v,$k) => [$k => 'واحد '.$v])->prepend('— بدون واحد', '')->toArray()" :selected="$currentUnitId" />

            <div class="sm:col-span-2">
                <x-input name="password" type="password" label="{{ $resident->exists ? 'رمز عبور جدید (خالی = بدون تغییر)' : 'رمز عبور' }}" :required="! $resident->exists" dir="ltr" />
            </div>

            <div class="flex gap-2 sm:col-span-2">
                <x-button variant="primary">ذخیره</x-button>
                <x-button :href="route('admin.residents.index')" variant="ghost" type="button">انصراف</x-button>
            </div>
        </form>
    </x-card>
</div>
@endsection
