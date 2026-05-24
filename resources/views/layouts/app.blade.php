<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'مدیریت ساختمان') — {{ config('app.name') }}</title>

    {{-- Prevent dark-mode flash before assets load --}}
    <script>
        (function () {
            const t = localStorage.getItem('theme') || 'system';
            const dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased dark:bg-slate-900 dark:text-slate-100">
@php($user = auth()->user())
@php($complex = $user?->isSuperAdmin() ? (session('active_complex_id') ? \App\Models\Complex::find(session('active_complex_id')) : null) : $user?->complex)

<div x-data="{ open: false }" class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside
        class="fixed inset-y-0 right-0 z-40 w-72 transform border-l border-slate-200 bg-white p-4 transition-transform dark:border-slate-700 dark:bg-slate-800 lg:static lg:translate-x-0"
        :class="open ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
    >
        <div class="mb-6 flex items-center gap-3 px-2">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-600 text-lg font-bold text-white">س</div>
            <div>
                <p class="font-bold leading-tight">مدیریت ساختمان</p>
                <p class="text-xs text-slate-400">{{ $complex?->name ?? 'پنل سیستم' }}</p>
            </div>
        </div>

        <nav class="space-y-1">
            <x-nav-link :href="route('dashboard')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10h14V10"/></svg>'>داشبورد</x-nav-link>

            @if ($user->isAdmin())
                <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">مدیریت</p>
                <x-nav-link :href="route('admin.units.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 21V5a2 2 0 012-2h8a2 2 0 012 2v16M4 21h16M9 7h2m-2 4h2m-2 4h2"/></svg>'>واحدها</x-nav-link>
                <x-nav-link :href="route('admin.residents.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-2a4 4 0 10-4-4 4 4 0 004 4z"/></svg>'>ساکنین</x-nav-link>
                <x-nav-link :href="route('admin.charge-rules.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 16v-2m6-6h2M4 12h2m1.5-4.5l1.5 1.5m6 6l1.5 1.5m0-9l-1.5 1.5m-6 6l-1.5 1.5"/></svg>'>قوانین شارژ</x-nav-link>
                <x-nav-link :href="route('admin.expenses.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 9v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>'>هزینه‌ها و درآمد</x-nav-link>
                <x-nav-link :href="route('admin.bills.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-1 1z"/></svg>'>قبوض و شارژ</x-nav-link>
                <x-nav-link :href="route('admin.payments.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h2m4 0h4M3 7a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>'>بررسی پرداخت‌ها</x-nav-link>
            @endif

            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">عمومی</p>
            @unless ($user->isAdmin())
                <x-nav-link :href="route('bills.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-1 1z"/></svg>'>صورت‌حساب‌های من</x-nav-link>
            @endunless
            <x-nav-link :href="route('announcements.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1 1 0 01-1.447.894L5 18H3a1 1 0 01-1-1v-4a1 1 0 011-1h2l4.553-2.134A1 1 0 0111 5.882zM15 8a3 3 0 010 6"/></svg>'>اطلاعیه‌ها</x-nav-link>
            <x-nav-link :href="route('messenger')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h8m-8-4h8m-4 8H7l-4 3V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-7z"/></svg>'>پیام‌رسان</x-nav-link>
            <x-nav-link :href="route('good-payers')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L22 12l-5.714 2.143L14 21l-2.286-6.857L6 12l5.714-2.143z"/></svg>'>ساکنین خوش‌حساب</x-nav-link>

            @if ($user->isAdmin())
                <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">تنظیمات</p>
                <x-nav-link :href="route('admin.settings.edit')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>'>تنظیمات مجتمع</x-nav-link>
                <x-nav-link :href="route('admin.backup.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 1.105 3.582 2 8 2s8-.895 8-2V7M4 7c0 1.105 3.582 2 8 2s8-.895 8-2M4 7c0-1.105 3.582-2 8-2s8 .895 8 2"/></svg>'>بکاپ مجتمع</x-nav-link>
            @endif

            @if ($user->isSuperAdmin())
                <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">سیستم</p>
                <x-nav-link :href="route('system.complexes.index')" icon='<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m6-14h6m-6 4h6m-6 4h6"/></svg>'>مدیریت مجتمع‌ها</x-nav-link>
            @endif
        </nav>
    </aside>

    {{-- Mobile backdrop --}}
    <div x-show="open" x-cloak @click="open = false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex items-center justify-between gap-3 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur dark:border-slate-700 dark:bg-slate-800/90">
            <button @click="open = !open" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 lg:hidden" aria-label="منو">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <div class="text-sm font-medium text-slate-500 dark:text-slate-300">@yield('title', 'داشبورد')</div>

            <div class="flex items-center gap-2">
                <button onclick="toggleTheme()" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="تغییر تم">
                    <svg xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>

                <div x-data="{ menu: false }" class="relative">
                    <button @click="menu = !menu" class="flex items-center gap-2 rounded-xl px-2 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-700">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-sm font-bold dark:bg-slate-600">{{ mb_substr($user->name, 0, 1) }}</div>
                        <div class="hidden text-right sm:block">
                            <p class="text-sm font-medium leading-tight">{{ $user->name }}</p>
                            <p class="text-xs text-slate-400">{{ $user->role->label() }}</p>
                        </div>
                    </button>
                    <div x-show="menu" x-cloak @click.outside="menu = false" class="absolute left-0 mt-2 w-44 rounded-xl border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-800">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="block w-full px-4 py-2 text-right text-sm text-rose-600 hover:bg-slate-100 dark:hover:bg-slate-700">خروج از حساب</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            @if (session('success'))
                <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-800 dark:bg-rose-900/30 dark:text-rose-300">{{ session('error') }}</div>
            @endif

            @yield('content')
            {{ $slot ?? '' }}
        </main>
    </div>
</div>

<style>[x-cloak]{display:none!important}</style>
@livewireScripts
</body>
</html>
