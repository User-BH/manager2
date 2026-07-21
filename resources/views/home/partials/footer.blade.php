@php
    $contact = config('brand.contact');

    $quickLinks = [
        ['label' => 'ویژگی‌ها', 'href' => '#features'],
        ['label' => 'گالری', 'href' => '#gallery'],
        ['label' => 'نظرات کاربران', 'href' => '#testimonials'],
        ['label' => 'ورود به پنل', 'href' => route('login')],
    ];

    $supportLinks = [
        ['label' => 'سوالات متداول', 'href' => '#'],
        ['label' => 'قوانین و مقررات', 'href' => '#'],
        ['label' => 'حریم خصوصی', 'href' => '#'],
        ['label' => 'تماس با ما', 'href' => '#contact'],
    ];
@endphp

<footer id="contact" class="border-t border-line bg-surface">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-10 lg:grid-cols-[1.1fr_1fr_1fr_1.2fr]">
            {{-- ستون برند --}}
            <div>
                <x-logo :size="32" />
                <p class="mt-4 max-w-xs text-[13px] leading-7 text-muted">
                    {{ config('brand.name') }} {{ config('brand.description') }}
                </p>

                <div class="mt-5 flex items-center gap-2.5">
                    @foreach (config('brand.socials') as $social)
                        {{-- رنگ هاور اختصاصی هر شبکه از متغیر --social-hover خوانده می‌شود
                             چون می‌تواند گرادیان باشد، نه فقط یک رنگ ساده. --}}
                        <a href="{{ $social['href'] }}" target="_blank" rel="noopener noreferrer"
                           aria-label="{{ $social['label'] }}"
                           class="social-link flex h-9 w-9 items-center justify-center rounded-full border border-line text-muted transition-all duration-200 hover:-translate-y-0.5 hover:border-transparent hover:text-white"
                           style="--social-hover: {{ $social['hover'] }}">
                            <x-icon :name="$social['id']" :size="16" />
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- دسترسی سریع --}}
            <div>
                <p class="text-sm font-bold text-ink">دسترسی سریع</p>
                <ul class="mt-4 flex flex-col gap-2.5">
                    @foreach ($quickLinks as $link)
                        <li>
                            <a href="{{ $link['href'] }}" class="text-[13px] text-muted transition-colors hover:text-ink">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- پشتیبانی --}}
            <div>
                <p class="text-sm font-bold text-ink">پشتیبانی</p>
                <ul class="mt-4 flex flex-col gap-2.5">
                    @foreach ($supportLinks as $link)
                        <li>
                            <a href="{{ $link['href'] }}" class="text-[13px] text-muted transition-colors hover:text-ink">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- تماس --}}
            <div>
                <p class="text-sm font-bold text-ink">ارتباط با دفتر مرکزی</p>

                <ul class="mt-4 flex flex-col gap-2.5 text-[13px] text-muted">
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5 shrink-0 text-brand-500 dark:text-brand-300"><x-icon name="map-pin" :size="15" /></span>
                        <span>{{ $contact['address'] }}</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <span class="shrink-0 text-brand-500 dark:text-brand-300"><x-icon name="phone" :size="15" /></span>
                        <a href="tel:{{ $contact['phone_href'] }}" dir="ltr" class="transition-colors hover:text-ink">{{ $contact['phone'] }}</a>
                    </li>
                    <li class="flex items-center gap-2">
                        <span class="shrink-0 text-brand-500 dark:text-brand-300"><x-icon name="mail" :size="15" /></span>
                        <a href="mailto:{{ $contact['email'] }}" dir="ltr" class="transition-colors hover:text-ink">{{ $contact['email'] }}</a>
                    </li>
                </ul>

                {{-- به‌جای نقشهٔ جاسازی‌شدهٔ گوگل (منبع خارجی، ناسازگار با قید self-host
                     پروژه) یک کارت موقعیت محلی نمایش داده می‌شود. --}}
                <div class="mt-4 overflow-hidden rounded-2xl border border-line">
                    <div class="relative h-32">
                        <x-skyline :variant="2" :seed="9" class="absolute inset-0" />
                        <div class="absolute inset-0 bg-brand-900/45"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-center gap-1 text-white">
                            <x-icon name="map-pin" :size="20" />
                            <p class="text-xs font-medium">دفتر مرکزی — تهران</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-10 flex flex-col items-center justify-between gap-3 border-t border-line pt-6 text-xs text-faint sm:flex-row">
            <p>© {{ \App\Support\Jalali::date(now(), 'Y') }} {{ config('brand.name') }}. تمامی حقوق محفوظ است.</p>
            <p>ساخته‌شده برای مدیران مجتمع‌های مسکونی</p>
        </div>
    </div>
</footer>
