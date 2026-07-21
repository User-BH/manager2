@props([
    /* ۰ تا ۳ — ترکیب‌بندی و گرادیان متفاوت، برای اینکه تکرار تصویر در گالری دیده نشود */
    'variant' => 0,
    'seed' => 0,
])

{{--
    نمای انتزاعی مجتمع مسکونی، کاملاً SVG و محلی (بدون هیچ تصویر یا منبع خارجی).
    جایگزین عکس‌های استوک در طراحی اولیه است تا قید self-host پروژه حفظ شود؛
    اگر بعداً عکس واقعی مجتمع اضافه شد، کافی است این کامپوننت با <img> عوض شود.
--}}
@php
    $variant = ((int) $variant) % 4;
    $id = 'sky'.$variant.'-'.$seed.'-'.substr(md5($variant.'|'.$seed), 0, 6);

    /* هر ستون: [x, عرض, ارتفاع, ستون‌های پنجره, ردیف‌های پنجره] روی بوم ۸۰۰×۵۰۰ */
    $compositions = [
        0 => [[60, 120, 250, 3, 5], [200, 150, 340, 4, 7], [370, 110, 200, 3, 4], [500, 170, 300, 4, 6], [690, 100, 230, 2, 5]],
        1 => [[40, 150, 300, 4, 6], [210, 100, 210, 2, 4], [330, 170, 370, 4, 8], [520, 120, 250, 3, 5], [660, 140, 190, 3, 3]],
        2 => [[70, 140, 220, 3, 4], [230, 120, 330, 3, 7], [370, 160, 260, 4, 5], [550, 110, 380, 2, 8], [680, 110, 210, 3, 4]],
        3 => [[50, 110, 270, 2, 6], [180, 160, 200, 4, 4], [360, 130, 350, 3, 7], [510, 150, 240, 4, 5], [680, 100, 300, 2, 6]],
    ];

    $buildings = $compositions[$variant];
    $groundY = 500;
@endphp

<svg viewBox="0 0 800 500" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg"
     role="img" aria-label="نمای مجتمع مسکونی"
     {{ $attributes->merge(['class' => 'h-full w-full']) }}>
    <defs>
        <linearGradient id="bg-{{ $id }}" x1="0" y1="0" x2="0.4" y2="1">
            <stop offset="0%" stop-color="var(--color-brand-{{ [700, 600, 800, 500][$variant] }})"/>
            <stop offset="100%" stop-color="var(--color-brand-{{ [400, 500, 400, 300][$variant] }})"/>
        </linearGradient>

        <radialGradient id="glow-{{ $id }}" cx="0.25" cy="0.15" r="0.55">
            <stop offset="0%" stop-color="#ffffff" stop-opacity="0.35"/>
            <stop offset="100%" stop-color="#ffffff" stop-opacity="0"/>
        </radialGradient>
    </defs>

    <rect width="800" height="500" fill="url(#bg-{{ $id }})"/>
    <rect width="800" height="500" fill="url(#glow-{{ $id }})"/>

    {{-- ماه/خورشید --}}
    <circle cx="{{ 150 + $variant * 120 }}" cy="90" r="34" fill="#ffffff" opacity="0.16"/>
    <circle cx="{{ 150 + $variant * 120 }}" cy="90" r="20" fill="#ffffff" opacity="0.28"/>

    {{-- ردیف پشتی ساختمان‌ها، محو و کم‌رنگ برای حس عمق --}}
    <g opacity="0.18" fill="#ffffff">
        @foreach ($buildings as [$x, $w, $h, , ])
            <rect x="{{ $x - 35 }}" y="{{ $groundY - $h * 0.75 }}" width="{{ $w * 0.8 }}" height="{{ $h * 0.75 }}" rx="6"/>
        @endforeach
    </g>

    {{-- ردیف اصلی با پنجره‌ها --}}
    @foreach ($buildings as $index => [$x, $w, $h, $cols, $rows])
        @php
            $y = $groundY - $h;
            $padX = 16;
            $padY = 22;
            $cellW = ($w - $padX * 2) / $cols;
            $cellH = ($h - $padY * 2) / max($rows, 1);
            $winW = min($cellW * 0.55, 18);
            $winH = min($cellH * 0.45, 16);
        @endphp

        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" rx="8"
              fill="#ffffff" fill-opacity="{{ $index % 2 === 0 ? 0.14 : 0.22 }}"/>

        @for ($r = 0; $r < $rows; $r++)
            @for ($c = 0; $c < $cols; $c++)
                @php
                    /* روشن/خاموش بودن پنجره‌ها قطعی است (بدون rand) تا خروجی
                       بین رندرهای مختلف پایدار بماند و پرش دیده نشود. */
                    $lit = (($r * 7 + $c * 3 + $index * 5 + $variant) % 4) !== 0;
                @endphp
                <rect
                    x="{{ round($x + $padX + $c * $cellW + ($cellW - $winW) / 2, 1) }}"
                    y="{{ round($y + $padY + $r * $cellH, 1) }}"
                    width="{{ round($winW, 1) }}" height="{{ round($winH, 1) }}" rx="2"
                    fill="#ffffff" fill-opacity="{{ $lit ? 0.62 : 0.2 }}"/>
            @endfor
        @endfor
    @endforeach

    {{-- زمین --}}
    <rect x="0" y="486" width="800" height="14" fill="#000000" opacity="0.14"/>
</svg>
