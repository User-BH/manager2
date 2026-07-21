<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0f6e56">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    {{-- صفحه‌های Blade عنوان را با @section('title') می‌دهند و کامپوننت‌های
         تمام‌صفحهٔ Livewire با #[Title] که به‌صورت متغیر $title می‌رسد. --}}
    <title>@yield('title', $title ?? 'داشبورد') — {{ config('brand.name') }}</title>

    {{-- پیش از اولین رنگ‌آمیزی: تم روشن/تاریک و وضعیت جمع‌بودن سایدبار --}}
    <script>
        (function () {
            const t = localStorage.getItem('theme') || 'system';
            const dark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.documentElement.dataset.sidebar =
                localStorage.getItem('sidebar') === 'collapsed' ? 'collapsed' : 'expanded';
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">
@php($user = auth()->user())
@php($complex = $user?->isSuperAdmin() ? (session('active_complex_id') ? \App\Models\Complex::find(session('active_complex_id')) : null) : $user?->complex)

<div x-data="{ mobileOpen: false }" class="flex h-screen overflow-hidden">

    {{-- سایدبار دسکتاپ --}}
    <aside class="sidebar-desktop relative z-30 hidden shrink-0 flex-col border-l border-line bg-surface lg:flex">
        <x-sidebar-content :user="$user" :complex="$complex" />

        {{-- دکمهٔ جمع/باز کردن، روی لبهٔ سایدبار --}}
        <button
            type="button"
            onclick="toggleSidebar()"
            class="absolute -left-3 top-7 flex h-6 w-6 items-center justify-center rounded-full border border-line-strong bg-surface text-muted shadow-sm transition-colors hover:bg-sunken hover:text-ink focus-ring"
            aria-label="جمع یا باز کردن منو"
        >
            <span class="sidebar-collapse-chevron flex items-center justify-center">
                <x-icon name="chevrons-right" :size="14" />
            </span>
        </button>
    </aside>

    {{-- بک‌دراپ موبایل --}}
    <div
        x-show="mobileOpen"
        x-cloak
        x-transition.opacity
        @click="mobileOpen = false"
        class="fixed inset-0 z-40 bg-black/40 lg:hidden"
    ></div>

    {{-- کشوی موبایل --}}
    <aside
        x-show="mobileOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-250"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        @keydown.escape.window="mobileOpen = false"
        class="fixed inset-y-0 right-0 z-50 flex w-[272px] flex-col border-l border-line bg-surface lg:hidden"
    >
        <div class="absolute left-3 top-4 z-10">
            <x-icon-button icon="close" @click="mobileOpen = false" aria-label="بستن منو" class="h-8 w-8" />
        </div>
        <x-sidebar-content :user="$user" :complex="$complex" />
    </aside>

    {{-- ستون اصلی --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
            <x-icon-button icon="menu" @click="mobileOpen = true" aria-label="باز کردن منو" class="lg:hidden" />

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-ink">@yield('title', $title ?? 'داشبورد')</p>
                @if ($complex)
                    <p class="truncate text-[11px] text-faint">{{ $complex->name }}</p>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <x-icon-button icon="bell" variant="outline" aria-label="اعلان‌ها" class="relative">
                    <span class="absolute -left-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-accent-500 ring-2 ring-surface"></span>
                </x-icon-button>

                <x-theme-toggle />

                <div class="mx-1 h-7 w-px bg-line"></div>

                {{-- منوی کاربر --}}
                <div x-data="{ open: false }" class="relative">
                    <button
                        type="button"
                        @click="open = !open"
                        class="flex items-center gap-2 rounded-xl border border-line py-1.5 pl-2.5 pr-1.5 transition-colors duration-200 hover:bg-sunken focus-ring"
                    >
                        <div class="hidden text-right sm:block">
                            <p class="text-[13px] font-semibold leading-tight text-ink">{{ $user->name }}</p>
                            <p class="text-[11px] leading-tight text-faint">{{ $user->role->label() }}</p>
                        </div>

                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-500 text-sm font-bold text-white">
                            {{ mb_substr($user->name, 0, 1) }}
                        </div>

                        <span class="text-faint transition-transform duration-200" :class="open && 'rotate-180'">
                            <x-icon name="chevron-down" :size="15" />
                        </span>
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        @click.outside="open = false"
                        @keydown.escape.window="open = false"
                        x-transition.origin.top.left
                        class="absolute left-0 top-[calc(100%+8px)] z-40 w-48 overflow-hidden rounded-xl border border-line bg-overlay shadow-ambient"
                    >
                        <div class="border-b border-line px-3.5 py-2.5 sm:hidden">
                            <p class="text-[13px] font-semibold text-ink">{{ $user->name }}</p>
                            <p class="text-[11px] text-faint">{{ $user->role->label() }}</p>
                        </div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-[13px] text-danger transition-colors duration-150 hover:bg-sunken">
                                <x-icon name="logout" :size="16" />
                                خروج از حساب
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="scrollbar-thin flex-1 overflow-y-auto p-4 sm:p-6">
            @if (session('success'))
                <x-alert type="success" class="mb-4">{{ session('success') }}</x-alert>
            @endif
            @if (session('error'))
                <x-alert type="error" class="mb-4">{{ session('error') }}</x-alert>
            @endif

            @yield('content')
            {{ $slot ?? '' }}
        </main>
    </div>
</div>

@livewireScripts
</body>
</html>
