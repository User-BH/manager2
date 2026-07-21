@php($testimonials = config('landing.testimonials'))

<section id="testimonials" class="mx-auto max-w-4xl px-4 py-20 sm:px-6">
    <div class="reveal mx-auto max-w-xl text-center">
        <h2 class="text-2xl font-extrabold text-ink sm:text-3xl">تجربهٔ مدیران مجتمع‌ها</h2>
        <p class="mt-3 text-[14.5px] leading-7 text-muted">
            نظر کسانی که هر روز از این پنل برای مدیریت مجتمع خودشان استفاده می‌کنند
        </p>
    </div>

    <div class="reveal mt-12" data-testimonial-slider style="--reveal-delay: 120ms">
        @foreach ($testimonials as $index => $item)
            <div data-testimonial-slide class="{{ $index === 0 ? '' : 'hidden' }} mx-auto flex max-w-xl flex-col items-center rounded-3xl border border-line bg-surface p-8 text-center shadow-ambient sm:p-10">
                <span class="text-brand-300">
                    <x-icon name="quote" :size="28" />
                </span>

                <p class="mt-5 text-[15px] leading-8 text-ink">{{ $item['quote'] }}</p>

                <div class="mt-6 flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-500 text-sm font-bold text-white">
                        {{ mb_substr($item['name'], 0, 1) }}
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-ink">{{ $item['name'] }}</p>
                        <p class="text-xs text-faint">{{ $item['role'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="mt-6 flex items-center justify-center gap-1.5">
            @foreach ($testimonials as $index => $item)
                <button type="button" data-testimonial-dot="{{ $index }}"
                        class="h-1.5 rounded-full bg-brand-500 transition-all duration-250 {{ $index === 0 ? 'w-5 opacity-100' : 'w-1.5 opacity-35' }}"
                        aria-label="نظر {{ $index + 1 }}"></button>
            @endforeach
        </div>
    </div>
</section>

<script>
    // اسلایدر نظرات: چرخش خودکار هر ۶ ثانیه با توقف هنگام هاور.
    (function () {
        const slider = document.querySelector('[data-testimonial-slider]');
        if (!slider) return;

        const slides = slider.querySelectorAll('[data-testimonial-slide]');
        const dots = slider.querySelectorAll('[data-testimonial-dot]');
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
                dot.classList.toggle('opacity-35', i !== current);
            });
        }

        function start() {
            stop();
            timer = setInterval(() => show(current + 1), 6000);
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
