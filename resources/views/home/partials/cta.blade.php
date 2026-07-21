<section class="mx-auto max-w-6xl px-4 pb-20 sm:px-6">
    <div class="reveal relative overflow-hidden rounded-[2rem] bg-linear-to-bl from-brand-600 to-brand-400 px-6 py-14 text-center sm:px-12">
        <div class="pointer-events-none absolute -left-10 -top-10 h-56 w-56 rounded-full bg-white/10"></div>
        <div class="pointer-events-none absolute -bottom-16 -right-16 h-64 w-64 rounded-full bg-white/10"></div>

        <h2 class="relative text-2xl font-extrabold text-white sm:text-3xl">
            همین امروز مدیریت مجتمع را ساده کنید
        </h2>
        <p class="relative mx-auto mt-3 max-w-md text-[14.5px] leading-7 text-white/85">
            برای راه‌اندازی پنل مجتمع خود با ما تماس بگیرید؛ حساب مدیر توسط ادمین سیستم ساخته می‌شود.
        </p>

        <div class="relative mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="tel:{{ config('brand.contact.phone_href') }}"
               class="group inline-flex items-center gap-2 rounded-2xl bg-white px-7 py-3.5 text-sm font-bold text-brand-600 transition-transform duration-200 hover:scale-105 focus-ring">
                <x-icon name="phone" :size="16" />
                تماس با ما
            </a>

            <a href="{{ route('login') }}"
               class="inline-flex items-center gap-2 rounded-2xl border border-white/40 px-7 py-3.5 text-sm font-bold text-white transition-colors duration-200 hover:bg-white/10 focus-ring">
                ورود به پنل
                <x-icon name="arrow-left" :size="16" />
            </a>
        </div>
    </div>
</section>
