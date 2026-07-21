@php($hero = config('landing.hero'))

<section class="relative overflow-hidden pt-28 sm:pt-32">
    {{-- هالهٔ گرادیان پس‌زمینه --}}
    <div class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[600px]"
         style="background: radial-gradient(60% 50% at 50% 0%, var(--color-brand-100), transparent 70%)"></div>

    <div class="mx-auto grid max-w-6xl gap-12 px-4 pb-16 sm:px-6 lg:grid-cols-2 lg:items-center">
        <div class="relative z-10">
            <div class="reveal mb-5 inline-flex items-center gap-2 rounded-full border border-line px-3.5 py-1.5 text-xs font-medium text-brand-500 dark:text-brand-300">
                <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                {{ $hero['badge'] }}
            </div>

            <h1 class="reveal text-3xl font-extrabold leading-[1.35] text-ink sm:text-4xl lg:text-[2.6rem]" style="--reveal-delay: 80ms">
                {{ $hero['title'] }}
                <br>
                <span class="text-brand-500 dark:text-brand-300">{{ $hero['title_highlight'] }}</span>
            </h1>

            <p class="reveal mt-5 max-w-md text-[15px] leading-7 text-muted" style="--reveal-delay: 160ms">
                {{ $hero['description'] }}
            </p>

            <div class="reveal mt-8 flex flex-wrap items-center gap-3" style="--reveal-delay: 240ms">
                <x-button :href="route('login')" size="lg" class="group shadow-lg shadow-brand-900/10">
                    ورود به پنل مدیریت
                    <span class="transition-transform duration-200 group-hover:-translate-x-1">
                        <x-icon name="arrow-left" :size="16" />
                    </span>
                </x-button>

                <x-button href="#features" variant="outline" size="lg" icon="play">
                    آشنایی با امکانات
                </x-button>
            </div>

            <div class="reveal mt-10 flex flex-wrap gap-6" style="--reveal-delay: 320ms">
                @foreach ($hero['highlights'] as $item)
                    <div class="flex items-center gap-2">
                        <span class="text-brand-500 dark:text-brand-300">
                            <x-icon :name="$item['icon']" :size="16" />
                        </span>
                        <span class="text-xs font-medium text-faint">{{ $item['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="reveal relative" style="--reveal-delay: 120ms">
            <div class="absolute -inset-4 -z-10 rounded-[2rem] bg-brand-200 opacity-60 blur-2xl"></div>
            <div class="h-[340px] overflow-hidden rounded-[1.75rem] border border-line shadow-2xl sm:h-[420px]">
                <x-skyline :variant="0" />
            </div>
        </div>
    </div>
</section>
