@extends('layouts.app')
@section('title', 'مدیران مجتمع')

@php use App\Support\Jalali; @endphp

@section('content')
<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    <x-card title="افزودن مدیر">
        <form method="POST" action="{{ route('admin.managers.store') }}" class="space-y-3">
            @csrf
            <x-input name="name" label="نام مدیر" required />
            <x-input name="phone" label="شماره تلفن (نام کاربری)" required dir="ltr" placeholder="09xxxxxxxxx" />
            <x-input name="password" type="password" label="رمز عبور" required dir="ltr" />
            <x-button variant="primary" class="w-full">افزودن مدیر</x-button>
        </form>
    </x-card>

    <div class="lg:col-span-2">
        <x-card title="مدیران فعلی" subtitle="یک مجتمع می‌تواند چند مدیر داشته باشد">
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-400"><tr><th class="pb-2 text-right">نام</th><th class="pb-2 text-right">شماره</th><th class="pb-2 text-left">عملیات</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @foreach ($managers as $m)
                        <tr>
                            <td class="py-3 font-medium">{{ $m->name }} @if($m->id === auth()->id())<x-badge color="sky">شما</x-badge>@endif</td>
                            <td class="py-3" dir="ltr">{{ Jalali::digits($m->phone) }}</td>
                            <td class="py-3 text-left">
                                @if ($m->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.managers.destroy', $m) }}" onsubmit="return confirm('حذف این مدیر؟')">@csrf @method('DELETE')
                                        <button class="text-rose-600 hover:underline dark:text-rose-400">حذف</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    </div>
</div>
@endsection
