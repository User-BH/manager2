"""
ساختِ آیکون‌های نصبِ PWA از نشانِ اصلیِ ساکنا — بی صفحه‌ی سفید («چند نکته»، بندِ ۲).

    python scripts/generate-pwa-icons.py                      # همه‌ی آیکون‌ها از resources/icons/logo-master.png
    python scripts/generate-pwa-icons.py --extract OLD.png    # یک بار: نشانِ شفاف از آیکونِ قدیمیِ زمینه‌سفید

علتِ قابِ سفید: همه‌ی آیکون‌های نصب (`icon-*`، `maskable-*`، `apple-touch-icon`) مربعِ سفیدِ مات بودند با نشان در
میانه. اندروید maskable را به شکلِ خودش می‌بُرد و زمینه‌اش را تا لبه نشان می‌دهد، iOS شفافیت نمی‌پذیرد، و رومیزی همان
مربع را می‌گذارد — هر سه «نشان روی صفحه‌ی سفید». هویت همان نشان است (پیچکِ سبز دورِ ساختمان)، فقط زمینه عوض می‌شود:

- `any` (رومیزی، فهرستِ برنامه‌ها): زمینه‌ی **شفاف**، نشان تقریباً کلِ بوم.
- `maskable` (اندروید) و `apple-touch-icon` (iOS): زمینه‌ی **مات** به رنگِ برند و نشانِ سفیدِ یک‌دست — همان قاعده‌ی
  `LogoMark` روی زمینه‌های رنگی — کاملاً داخلِ ناحیه‌ی امنِ maskable (دایره‌ای به قطرِ ۸۰٪).

پس از این، `php artisan pwa:splash --force` صفحه‌های آغازینِ iOS را از `icon-512.png` ِ تازه می‌سازد.
"""
import sys
from collections import deque
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
MASTER = ROOT / 'resources/icons/logo-master.png'
BRAND = (15, 110, 86, 255)  # ‎#0f6e56، همان theme_color ِ manifest

NEAR_WHITE = 236  # همان آستانه‌ی `GenerateSplashScreens::dropOuterWhite`
EDGE_BAND = 3  # پیکسل‌های لبه که با سفید آمیخته‌اند و جدا می‌شوند
LOGO_INK = 70  # کم‌ترین کانالِ رنگِ نشانِ خالص — برای برآوردِ شفافیتِ لبه


def extract(source: Path) -> Image.Image:
    """زمینه‌ی سفیدِ **بیرونی** (پیوسته به لبه‌ها) را برمی‌دارد؛ سفیدهای درونِ نشان (پنجره‌ها) می‌مانند."""
    im = source and Image.open(source).convert('RGBA')
    w, h = im.size
    px = im.load()
    outside = bytearray(w * h)
    queue = deque((x, y) for x in range(w) for y in (0, h - 1))
    queue.extend((x, y) for y in range(h) for x in (0, w - 1))
    while queue:
        x, y = queue.popleft()
        i = y * w + x
        if outside[i] or min(px[x, y][:3]) <= NEAR_WHITE:
            continue
        outside[i] = 1
        for nx, ny in ((x + 1, y), (x - 1, y), (x, y + 1), (x, y - 1)):
            if 0 <= nx < w and 0 <= ny < h:
                queue.append((nx, ny))

    # فاصله تا زمینه، برای نوارِ لبه
    distance = [0 if outside[i] else EDGE_BAND + 1 for i in range(w * h)]
    for _ in range(EDGE_BAND):
        nxt = distance[:]
        for y in range(h):
            for x in range(w):
                i = y * w + x
                if distance[i] == 0:
                    continue
                for nx, ny in ((x + 1, y), (x - 1, y), (x, y + 1), (x, y - 1)):
                    if 0 <= nx < w and 0 <= ny < h:
                        nxt[i] = min(nxt[i], distance[ny * w + nx] + 1)
        distance = nxt

    out = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    op = out.load()
    for y in range(h):
        for x in range(w):
            i = y * w + x
            r, g, b, _ = px[x, y]
            if outside[i]:
                continue
            if distance[i] <= EDGE_BAND:
                # آمیخته با سفید: C = a·F + (1-a)·255 ← a از روشنیِ پیکسل، F با جداکردنِ سفید
                a = max(0.0, min(1.0, (255 - min(r, g, b)) / (255 - LOGO_INK)))
                if a < 0.02:
                    continue
                f = [max(0, min(255, round((c - (1 - a) * 255) / a))) for c in (r, g, b)]
                op[x, y] = (*f, round(a * 255))
            else:
                op[x, y] = (r, g, b, 255)
    return out.crop(out.getchannel('A').getbbox())


def fit(logo: Image.Image, size: int, fill: float) -> Image.Image:
    """نشان را در بومِ مربعی، با بلندترین ضلع = fill × بوم، در میانه می‌گذارد."""
    scale = fill * size / max(logo.size)
    scaled = logo.resize((max(1, round(logo.width * scale)), max(1, round(logo.height * scale))), Image.LANCZOS)
    canvas = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    canvas.alpha_composite(scaled, ((size - scaled.width) // 2, (size - scaled.height) // 2))
    return canvas


def white(logo: Image.Image) -> Image.Image:
    """
    نشانِ سفید روی رنگ — مثلِ `LogoMark` ِ monochrome — با یک تفاوت: بخش‌های روشنِ خودِ نشان (پنجره‌ها) شفاف می‌شوند
    تا رنگِ زمینه از آن‌ها پیدا باشد؛ وگرنه ساختمان یک تکه‌ی سفیدِ بی‌جزئیات می‌شد.
    """
    mono = Image.new('RGBA', logo.size, (255, 255, 255, 0))
    src = logo.load()
    dst = mono.load()
    for y in range(logo.height):
        for x in range(logo.width):
            r, g, b, a = src[x, y]
            ink = max(0.0, min(1.0, (255 - min(r, g, b)) / (255 - LOGO_INK)))
            dst[x, y] = (255, 255, 255, round(a * ink))
    return mono


def on_brand(logo: Image.Image, size: int, fill: float) -> Image.Image:
    canvas = Image.new('RGBA', (size, size), BRAND)
    canvas.alpha_composite(fit(white(logo), size, fill))
    return canvas.convert('RGB')


def main() -> None:
    if len(sys.argv) == 3 and sys.argv[1] == '--extract':
        MASTER.parent.mkdir(parents=True, exist_ok=True)
        extract(Path(sys.argv[2])).save(MASTER)
        print(f'{MASTER.relative_to(ROOT)} ساخته شد')
        return

    logo = Image.open(MASTER).convert('RGBA')
    for size in (72, 96, 128, 144, 152, 192, 256, 384, 512):
        fit(logo, size, 0.92).save(ROOT / f'public/icons/icon-{size}.png', optimize=True)
    # ناحیه‌ی امنِ maskable دایره‌ای به قطرِ ۸۰٪ است؛ نشانِ کشیده با ۶۲٪ کاملاً درونش می‌ماند
    for size in (192, 512):
        on_brand(logo, size, 0.62).save(ROOT / f'public/icons/maskable-{size}.png', optimize=True)
    # تک‌رنگ (اندروید ۱۳+، آیکونِ هم‌رنگِ تم): فقط شکلِ نشان مهم است؛ سیستم‌عامل رنگش را خودش می‌گذارد
    fit(white(logo), 512, 0.62).save(ROOT / 'public/icons/monochrome-512.png', optimize=True)
    # iOS گوشه را گرد می‌کند، نه دایره؛ نشان کمی بزرگ‌تر
    on_brand(logo, 180, 0.7).save(ROOT / 'public/apple-touch-icon.png', optimize=True)
    print('icon-*، maskable-*، monochrome-512 و apple-touch-icon ساخته شدند')


if __name__ == '__main__':
    main()
