<section class="border-y border-line py-12">
    <div class="mx-auto grid max-w-6xl grid-cols-2 gap-8 px-4 sm:px-6 md:grid-cols-4">
        @foreach (config('landing.stats') as $index => $stat)
            <div class="reveal text-center" style="--reveal-delay: {{ $index * 80 }}ms">
                <p class="text-2xl font-extrabold text-brand-500 tabular-nums dark:text-brand-300 sm:text-3xl">
                    @if ($stat['count'] !== null)
                        {{-- شمارش تدریجی هنگام دیده‌شدن؛ مقدار نهایی از همین ابتدا
                             در DOM هست تا اگر انیمیشن اجرا نشد، عدد درست دیده شود. --}}
                        {{ $stat['prefix'] }}<span data-count-to="{{ $stat['count'] }}">{{ \App\Support\Jalali::digits(number_format($stat['count'])) }}</span>{{ $stat['suffix'] }}
                    @else
                        {{ $stat['value'] }}
                    @endif
                </p>
                <p class="mt-1.5 text-xs text-faint sm:text-[13px]">{{ $stat['label'] }}</p>
            </div>
        @endforeach
    </div>
</section>
