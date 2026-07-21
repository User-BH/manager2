@props(['user', 'complex' => null])

{{--
    محتوای مشترک سایدبار دسکتاپ و کشوی موبایل.
    ساختار منو از یک آرایهٔ واحد ساخته می‌شود تا افزودن یا جابه‌جایی آیتم‌ها
    فقط در یک نقطه انجام شود؛ هر آیتم دقیقاً به route()های موجود اشاره دارد.
--}}
@php
    $isAdmin = $user->isAdmin();
    $isSuperAdmin = $user->isSuperAdmin();

    $sections = array_values(array_filter([
        [
            'title' => null,
            'items' => [
                ['label' => 'داشبورد', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ],
        ],

        $isAdmin ? [
            'title' => 'مدیریت',
            'items' => [
                ['label' => 'واحدها', 'route' => 'admin.units.index', 'icon' => 'units'],
                ['label' => 'ساکنین', 'route' => 'admin.residents.index', 'icon' => 'residents'],
                ['label' => 'مدیران مجتمع', 'route' => 'admin.managers.index', 'icon' => 'managers'],
                ['label' => 'قوانین شارژ', 'route' => 'admin.charge-rules.index', 'icon' => 'rules'],
                ['label' => 'هزینه‌ها و درآمد', 'route' => 'admin.expenses.index', 'icon' => 'wallet'],
                ['label' => 'قبوض و شارژ', 'route' => 'admin.bills.index', 'icon' => 'receipt'],
                ['label' => 'بررسی پرداخت‌ها', 'route' => 'admin.payments.index', 'icon' => 'review'],
                ['label' => 'تخفیف و بخشودگی', 'route' => 'admin.discounts.index', 'icon' => 'discount'],
            ],
        ] : null,

        [
            'title' => 'عمومی',
            'items' => array_values(array_filter([
                $isAdmin ? null : ['label' => 'صورت‌حساب‌های من', 'route' => 'bills.index', 'icon' => 'receipt'],
                ['label' => 'اطلاعیه‌ها', 'route' => 'announcements.index', 'icon' => 'announcement'],
                ['label' => 'پیام‌رسان', 'route' => 'messenger', 'icon' => 'messenger'],
                ['label' => 'ساکنین خوش‌حساب', 'route' => 'good-payers', 'icon' => 'award'],
            ])),
        ],

        $isAdmin ? [
            'title' => 'تنظیمات',
            'items' => [
                ['label' => 'تنظیمات مجتمع', 'route' => 'admin.settings.edit', 'icon' => 'settings'],
                ['label' => 'بکاپ مجتمع', 'route' => 'admin.backup.index', 'icon' => 'backup'],
            ],
        ] : null,

        $isSuperAdmin ? [
            'title' => 'سیستم',
            'items' => [
                ['label' => 'مدیریت مجتمع‌ها', 'route' => 'system.complexes.index', 'icon' => 'complexes'],
                ['label' => 'پنل پیامک', 'route' => 'system.sms.edit', 'icon' => 'sms'],
                ['label' => 'بکاپ کل سیستم', 'route' => 'system.backup.index', 'icon' => 'server'],
            ],
        ] : null,
    ]));
@endphp

<div class="flex h-full flex-col">
    {{-- برند --}}
    <div class="sidebar-brand flex h-16 shrink-0 items-center gap-2.5 border-b border-line px-4">
        <x-logo-mark :size="34" class="shrink-0" />
        <div class="sidebar-brand-text min-w-0 overflow-hidden leading-tight">
            <p class="truncate text-sm font-bold text-ink">{{ config('brand.name') }}</p>
            <p class="truncate text-xs text-faint">{{ $complex?->name ?? config('brand.panel_subtitle') }}</p>
        </div>
    </div>

    {{-- منو --}}
    <nav class="scrollbar-thin flex-1 overflow-y-auto px-2.5 py-4">
        @foreach ($sections as $index => $section)
            <div @class(['mt-5' => $index > 0])>
                @if ($section['title'])
                    <p class="sidebar-section-title mb-1.5 px-2.5 text-[11px] font-semibold tracking-wide text-faint">
                        {{ $section['title'] }}
                    </p>
                @endif

                <ul class="flex flex-col gap-0.5">
                    @foreach ($section['items'] as $item)
                        <li>
                            <x-nav-link :href="route($item['route'])" :icon="$item['icon']">
                                {{ $item['label'] }}
                            </x-nav-link>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
</div>
