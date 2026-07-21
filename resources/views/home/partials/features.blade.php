@php
    /* کلاس‌های گرادیان باید به‌صورت رشتهٔ کامل نوشته شوند تا اسکنر Tailwind
       آن‌ها را پیدا کند؛ ساختن نام کلاس با درج مقدار در Blade کار نمی‌کند. */
    $gradients = [
        'from-brand-600 to-brand-400',
        'from-brand-500 to-brand-300',
        'from-brand-700 to-brand-500',
        'from-brand-500 to-brand-400',
    ];
@endphp

<section id="features" class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
    <div class="reveal mx-auto max-w-xl text-center">
        <h2 class="text-2xl font-extrabold text-ink sm:text-3xl">
            همه‌چیزی که برای مدیریت مجتمع لازم دارید
        </h2>
        <p class="mt-3 text-[14.5px] leading-7 text-muted">
            از مالی و امنیت تا ارتباط با ساکنین؛ یک پنل یکپارچه برای تمام نیازهای روزمرهٔ مدیریت ساختمان
        </p>
    </div>

    <div class="mt-14 grid gap-6 sm:grid-cols-2">
        @foreach (config('landing.features') as $index => $feature)
            <div class="reveal group overflow-hidden rounded-3xl border border-line bg-surface shadow-ambient transition-[transform,box-shadow] duration-500 hover:-translate-y-2 hover:shadow-[0_24px_40px_-12px_color-mix(in_srgb,var(--color-brand-600)_25%,transparent)]"
                 style="--reveal-delay: {{ $index * 80 }}ms">
                {{-- سربرگ گرادیانی با واترمارک آیکون --}}
                <div class="relative h-44 overflow-hidden bg-linear-to-bl {{ $gradients[$index % count($gradients)] }}">
                    <span class="absolute -left-6 -top-6 text-white/15 transition-transform duration-500 group-hover:scale-110 group-hover:-rotate-6">
                        <x-icon :name="$feature['icon']" :size="180" :stroke="1" />
                    </span>

                    <div class="absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-surface to-transparent"></div>

                    <span class="absolute bottom-3 right-3 flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-500 text-white shadow-lg transition-transform duration-500 group-hover:scale-110 group-hover:-rotate-6">
                        <x-icon :name="$feature['icon']" :size="20" />
                    </span>
                </div>

                <div class="p-5">
                    <h3 class="text-[15px] font-bold text-ink">{{ $feature['title'] }}</h3>
                    <p class="mt-2 text-[13.5px] leading-6 text-muted">{{ $feature['description'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>
