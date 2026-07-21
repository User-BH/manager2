@php
    /* واحد تکرار نوار = تعداد آیتم‌های یک بلوک × (عرض آیتم + فاصله).
       عرض و فاصله در app.css روی .marquee-item تعریف شده‌اند (۲۸۰ و ۲۰ پیکسل).
       اگر آن‌ها تغییر کردند، این محاسبه هم باید به‌روزرسانی شود. */
    $itemsPerBlock = 8;
    $blockWidth = $itemsPerBlock * (280 + 20);
@endphp

<section id="gallery" class="overflow-hidden py-20">
    <div class="reveal mx-auto mb-12 max-w-xl px-4 text-center sm:px-6">
        <h2 class="text-2xl font-extrabold text-ink sm:text-3xl">مجتمع‌هایی که با ما مدیریت می‌شوند</h2>
        <p class="mt-3 text-[14.5px] leading-7 text-muted">
            از برج‌های بلندمرتبه تا مجتمع‌های کوچک محله‌ای، همه با یک پنل واحد
        </p>
    </div>

    <div class="marquee-viewport" style="--gallery-block-width: {{ $blockWidth }}px">
        {{-- دو کپی از بلوک، تا وقتی کپی اول از دید خارج می‌شود کپی دوم جای آن باشد --}}
        <div class="marquee-track">
            @for ($copy = 0; $copy < 2; $copy++)
                @for ($i = 0; $i < $itemsPerBlock; $i++)
                    <div class="marquee-item">
                        <x-skyline :variant="$i % 4" :seed="$i" />
                        <div class="absolute inset-0 bg-linear-to-t from-brand-900/45 to-transparent"></div>
                    </div>
                @endfor
            @endfor
        </div>
    </div>
</section>
