"""
زیرمجموعه‌سازیِ فونتِ Vazirmatn — ابزارِ یک‌باره، نه بخشی از build (مرحلهٔ ۷ · فاز ۳).

⚠️ این اسکریپت در `npm run build` اجرا نمی‌شود و وابستگیِ پروژه نیست. فقط وقتی
فونتِ تازه‌ای از Vazirmatn جایگزین شود، یک بار دستی اجرا می‌شود و خروجی‌اش
(`resources/fonts/*.woff2`) کامیت می‌شود. پیش‌نیاز فقط روی همان ماشین:

    python -m pip install --user fonttools brotli

اجرا (از ریشه‌ی پروژه):

    python scripts/subset-fonts.py

ورودی: `resources/fonts/source/` (فایل‌های اصلیِ Vazirmatn، دست‌نخورده)
خروجی: `resources/fonts/` (همان نام‌ها؛ همان‌هایی که CSS بار می‌کند)

─── چه چیزی می‌ماند و چه چیزی نه ─────────────────────────────────────────────
می‌ماند — هرچه ممکن است در متنِ این سامانه بیاید، از جمله نوشته‌ی کاربر:
  • کلِ بلوکِ عربی/فارسی، مکملِ عربی، و صورت‌های نمایشیِ A و B (متنِ چسبانده‌شده
    از PDF گاهی همین‌هاست)
  • ASCII و Latin-1 («»، ×، ÷، ©، حرف‌های اروپاییِ رایج مثل é و ü)
  • نشانه‌گذاریِ عمومی (نیم‌فاصله، LRM/RLM، — … • « »)، بالانویس و ارز،
    پیکان‌ها و نمادهای ریاضی، دایره‌ی نقطه‌چین (U+25CC) و U+FFFD
  • همه‌ی ویژگی‌های چیدمان (GSUB/GPOS) — شکلِ پیوسته‌ی حروفِ فارسی از همین‌هاست
  • hinting، همان‌طور که فونتِ اصلی دارد

نمی‌ماند — حرف‌هایی که فارسی و انگلیسیِ این سامانه هرگز ندارند:
  • لاتینِ گسترده A و B (ş، ő، ł، …)، IPA، و نشانه‌های ترکیبیِ ویژه‌ی لاتین
اگر چنین حرفی در نوشته‌ی کاربری بیاید، مرورگر همان یک حرف را با فونتِ سیستم
نشان می‌دهد؛ چیزی نمی‌شکند.

`tests/js/fontSubset.test.ts` می‌سنجد که هر نویسه‌ای که در متن‌های پروژه به کار
رفته، در همین بازه‌ها باشد.
"""

import json
import os
import sys

from fontTools import subset

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOURCE = os.path.join(ROOT, 'resources', 'fonts', 'source')
TARGET = os.path.join(ROOT, 'resources', 'fonts')
WEIGHTS = ['Regular', 'Medium', 'SemiBold', 'Bold']

# بازه‌های نگه‌داشته — همین فهرست در `resources/fonts/subset.json` هم نوشته می‌شود
RANGES = [
    (0x0020, 0x007E, 'ASCII'),
    (0x00A0, 0x00FF, 'Latin-1'),
    (0x0600, 0x06FF, 'Arabic'),
    (0x0750, 0x077F, 'Arabic Supplement'),
    (0x2000, 0x206F, 'General Punctuation'),
    (0x2070, 0x20CF, 'Superscripts, Currency'),
    (0x2100, 0x22FF, 'Letterlike, Arrows, Math'),
    (0x25CA, 0x25CC, 'Lozenge, Dotted Circle'),
    (0xFB50, 0xFDFF, 'Arabic Presentation Forms-A'),
    (0xFE70, 0xFEFF, 'Arabic Presentation Forms-B'),
    (0xFFFC, 0xFFFD, 'Replacement'),
]


def unicodes() -> list[int]:
    return [cp for start, end, _ in RANGES for cp in range(start, end + 1)]


def run(source: str, target: str) -> None:
    options = subset.Options()
    options.flavor = 'woff2'
    options.layout_features = ['*']
    options.name_IDs = ['*']
    options.name_languages = ['*']
    options.notdef_outline = True
    options.hinting = True
    options.legacy_kern = True

    font = subset.load_font(source, options)
    subsetter = subset.Subsetter(options)
    subsetter.populate(unicodes=unicodes())
    subsetter.subset(font)
    subset.save_font(font, target, options)


def main() -> int:
    for weight in WEIGHTS:
        name = f'Vazirmatn-{weight}.woff2'
        source = os.path.join(SOURCE, name)
        target = os.path.join(TARGET, name)
        run(source, target)
        print(f'{name}: {os.path.getsize(source):>6} → {os.path.getsize(target):>6} بایت')

    with open(os.path.join(TARGET, 'subset.json'), 'w', encoding='utf8') as handle:
        json.dump(
            {
                'note': 'بازه‌های یونیکدِ نگه‌داشته در زیرمجموعه‌ی Vazirmatn — ساخته‌ی scripts/subset-fonts.py',
                'ranges': [[f'U+{s:04X}', f'U+{e:04X}', label] for s, e, label in RANGES],
            },
            handle,
            ensure_ascii=False,
            indent=2,
        )
        handle.write('\n')

    return 0


if __name__ == '__main__':
    sys.exit(main())
