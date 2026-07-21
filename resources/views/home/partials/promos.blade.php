@php($promos = config('landing.promos'))

<section class="mx-auto max-w-6xl px-4 pt-16 sm:px-6">
    <div class="reveal relative overflow-hidden rounded-[1.75rem] border border-line" data-promo-slider>
        @foreach ($promos as $index => $promo)
            <div data-promo-slide class="{{ $index === 0 ? '' : 'hidden' }} relative h-52 sm:h-60">
                <x-skyline :variant="$index + 1" :seed="$index" class="absolute inset-0" />
                <div class="absolute inset-0 bg-gradient-to-l from-brand-900/80 via-brand-800/55 to-transparent"></div>

                <div class="relative flex h-full flex-col justify-center gap-2 px-6 text-white sm:px-10">
                    <span class="inline-flex w-fit items-center rounded-full bg-white/15 px-2.5 py-0.5 text-[11px] font-medium backdrop-blur">
                        پیشنهاد ویژهٔ مدیران
                    </span>
                    <h3 class="max-w-md text-lg font-bold sm:text-xl">{{ $promo['title'] }}</h3>
                    <p class="max-w-md text-[13px] leading-6 text-white/80">{{ $promo['subtitle'] }}</p>
                </div>
            </div>
        @endforeach

        {{-- نقاط راهبری --}}
        <div class="absolute bottom-4 left-6 flex items-center gap-1.5">
            @foreach ($promos as $index => $promo)
                <button type="button" data-promo-dot="{{ $index }}"
                        class="h-1.5 rounded-full bg-white transition-all duration-250 {{ $index === 0 ? 'w-5 opacity-100' : 'w-1.5 opacity-55' }}"
                        aria-label="اسلاید {{ $index + 1 }}"></button>
            @endforeach
        </div>
    </div>
</section>

<script>
    // اسلایدر بنر: چرخش خودکار هر ۵ ثانیه، با توقف هنگام هاور.
    (function () {
        const slider = document.querySelector('[data-promo-slider]');
        if (!slider) return;

        const slides = slider.querySelectorAll('[data-promo-slide]');
        const dots = slider.querySelectorAll('[data-promo-dot]');
        if (slides.length < 2) return;

        let current = 0;
        let timer = null;

        function show(index) {
            current = (index + slides.length) % slides.length;

            slides.forEach((slide, i) => slide.classList.toggle('hidden', i !== current));
            dots.forEach((dot, i) => {
                dot.classList.toggle('w-5', i === current);
                dot.classList.toggle('opacity-100', i === current);
                dot.classList.toggle('w-1.5', i !== current);
                dot.classList.toggle('opacity-55', i !== current);
            });
        }

        function start() {
            stop();
            timer = setInterval(() => show(current + 1), 5000);
        }

        function stop() {
            if (timer) clearInterval(timer);
            timer = null;
        }

        dots.forEach((dot, i) => dot.addEventListener('click', () => { show(i); start(); }));
        slider.addEventListener('mouseenter', stop);
        slider.addEventListener('mouseleave', start);

        start();
    })();
</script>
