#!/usr/bin/env bash
#
# به‌روزرسانی یک نصب موجود.
#
#   ./scripts/deploy.sh              اگر پوشه مخزن git باشد pull می‌کند، وگرنه رد می‌شود
#   ./scripts/deploy.sh --no-pull    حتی اگر git باشد pull نکن (فایل‌ها را خودتان آپلود کرده‌اید)
#   ./scripts/deploy.sh --no-down    بدون حالت تعمیرات (سایت لحظه‌ای هم قطع نشود)
#   ./scripts/deploy.sh --ref=<commit>
#                                    به‌جای آخرین کد، دقیقاً همین commit (فقط پیش‌روی، بی تغییرِ تاریخچه).
#                                    روی محصول: همان commit ای که در نوارِ Preview بازبینی و تأیید شد
#   ./scripts/deploy.sh --reset-preview
#                                    فقط روی Preview (APP_ENV=preview): پایگاه‌داده‌ی Preview را
#                                    پاک و با دادهٔ نمونه از نو می‌سازد (php artisan preview:reset)
#
# پیش از هر migrationِ تازه روی محصول بکاپِ کامل گرفته و سنجیده می‌شود؛ بی بکاپِ
# معتبر migration اجرا نمی‌شود (بندِ ۲۴ ِ فایلِ ۲، مرحلهٔ ۹ · فاز ۸).
#
# مثل setup.sh، این اسکریپت هم به nginx، MySQL یا نسخه‌های دیگر PHP دست نمی‌زند.

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

DO_PULL=auto
MAINTENANCE=1
RESET_PREVIEW=0
REF=""
for arg in "$@"; do
    case "$arg" in
        --no-pull) DO_PULL=0 ;;
        --ref=*) REF="${arg#--ref=}" ;;
        --no-down) MAINTENANCE=0 ;;
        --reset-preview) RESET_PREVIEW=1 ;;
        -h|--help) sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "گزینهٔ ناشناخته: $arg" ;;
    esac
done

[ -f .env ] || die ".env پیدا نشد. اول ./scripts/setup.sh را اجرا کنید."

# دادهٔ نمونه فقط روی Preview؛ روی محصول یعنی پاک‌شدنِ همه‌چیز
if [ "$RESET_PREVIEW" = "1" ] && ! is_preview; then
    die "--reset-preview فقط روی Preview (APP_ENV=preview) مجاز است. این نصب: $(env_value APP_ENV)"
fi

step "بررسی PHP و Composer"
PHP="$(find_php)"
COMPOSER="$(find_composer "$PHP")"
banner_php
require_php_extensions "$PHP"

# ---------------------------------------------------------------- ۱) گرفتن کد
# هر دو روش پشتیبانی می‌شود: هم سروری که خودش از git می‌گیرد، هم سروری که
# فایل‌ها با FTP/rsync رویش کپی می‌شوند.
if [ "$DO_PULL" != "0" ] && [ -d .git ] && command -v git >/dev/null 2>&1; then
    step "دریافت آخرین تغییرات از git"

    # اگر روی سرور فایلی دستی تغییر داده شده، pull آن را بی‌صدا از بین می‌برد.
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        warn "فایل‌های تغییریافتهٔ commit‌نشده روی سرور وجود دارد:"
        git status --short --untracked-files=no | sed 's/^/      /'
        die "برای جلوگیری از پاک‌شدن ناخواستهٔ این تغییرات، ادامه نمی‌دهم.
    یا آن‌ها را commit/stash کنید، یا با --no-pull اجرا کنید."
    fi

    before="$(git rev-parse --short HEAD)"
    if [ -n "$REF" ]; then
        # فقط commit ای که روی Preview بازبینی شد (۲۰۲۶-۱۰-۲۹، بندِ ۵): پیش‌روی تا همان، نه بیشتر و نه بازنویسیِ تاریخچه
        git fetch --quiet origin
        git merge --ff-only "$REF" || die "به $REF نمی‌شود پیش رفت (نیست، یا پیش‌روی نیست). نخست همان را روی Preview مستقر کنید."
    else
        git pull --ff-only
    fi
    after="$(git rev-parse --short HEAD)"

    if [ "$before" = "$after" ]; then
        ok "کد از قبل به‌روز بود ($after)"
    else
        ok "به‌روزرسانی شد: $before → $after"
    fi
    # نسخه‌ی مستقرشده: نوارِ Preview همین را نشان می‌دهد تا محصول با --ref به همان برسد
    git rev-parse --short HEAD > storage/app/deployed-commit 2>/dev/null || true
else
    if [ "$DO_PULL" = "0" ]; then
        ok "pull رد شد (--no-pull)"
    else
        ok "مخزن git نیست؛ از فایل‌های موجود روی سرور استفاده می‌شود"
    fi
fi

# ---------------------------------------------------------------- ۲) تعمیرات
# artisan down باید *بعد* از گرفتن کد و *قبل* از migrate باشد.
# روی نصب تازه (بدون vendor) artisan هنوز اجرا نمی‌شود، و سایتی هم بالا نیست
# که بخواهیم پایینش بیاوریم؛ پس حالت تعمیرات را رد می‌کنیم.
if [ "$MAINTENANCE" = "1" ] && ! has_vendor; then
    MAINTENANCE=0
    warn "پوشهٔ vendor موجود نیست (نصب تازه)؛ حالت تعمیرات رد شد."
fi

CLEANUP_DONE=0
bring_up() {
    [ "$CLEANUP_DONE" = "1" ] && return 0
    CLEANUP_DONE=1
    if [ "$MAINTENANCE" = "1" ]; then
        "$PHP" artisan up >/dev/null 2>&1 || true
    fi
}
# اگر وسط کار خطایی رخ داد، سایت نباید در حالت تعمیرات گیر کند.
trap bring_up EXIT

if [ "$MAINTENANCE" = "1" ]; then
    step "فعال‌سازی حالت تعمیرات"
    "$PHP" artisan down --retry=15 >/dev/null 2>&1 || true
    ok "سایت موقتاً در حالت تعمیرات است"
fi

# ---------------------------------------------------------------- ۳) وابستگی‌ها
step "به‌روزرسانی وابستگی‌های PHP"
if is_deployed; then
    composer_run install --no-dev --optimize-autoloader --no-interaction --prefer-dist
else
    composer_run install --no-interaction --prefer-dist
fi
ok "وابستگی‌ها به‌روز شدند"

build_assets

# ---------------------------------------------------------------- ۴) دیتابیس
step "اجرای migrationهای جدید"
pending="$("$PHP" artisan migrate:status 2>/dev/null | grep -ci 'pending' || true)"
if [ "$RESET_PREVIEW" = "1" ]; then
    # Preview از صفر: پاک‌سازی و ساختِ دوباره + دادهٔ نمونه + برچسبِ پایگاه‌داده (خودِ دستور قفل‌هایش را دارد)
    "$PHP" artisan preview:reset --force --no-interaction
    ok "پایگاه‌داده‌ی Preview از نو ساخته شد"
elif [ "${pending:-0}" -gt 0 ]; then
    if is_production; then
        # 🔴 بندِ ۲۴: پیش از تغییرِ ساختار، بکاپِ معتبر و قابلِ بازیابی — وگرنه توقف
        step "بکاپ پیش از migration ($pending مورد)"
        "$PHP" artisan backups:run --note="پیش از migration در استقرار" --no-interaction \
            || die "بکاپ ساخته نشد؛ migration اجرا نمی‌شود. مشکل را رفع کنید و دوباره استقرار دهید."
        "$PHP" artisan backups:verify --no-interaction \
            || die "بکاپِ تازه سنجش را رد کرد (قابلِ بازیابی نیست)؛ migration اجرا نمی‌شود."
        ok "بکاپ ساخته و سنجیده شد"
    fi
    "$PHP" artisan migrate --force --no-interaction
    ok "$pending migration اجرا شد"
else
    ok "migration جدیدی نبود"
fi

# مقاله‌های همراهِ کد (مرحلهٔ ۷ · فاز ۷). اجرای دوباره بی‌خطر است: مقاله‌ی
# موجود — که شاید ادمین ویرایشش کرده — دست نمی‌خورد. شکستش استقرار را
# نمی‌خواباند؛ بلاگ بی‌مقاله هم صفحه‌ی خالیِ سالم دارد.
if "$PHP" artisan blog:import --no-interaction; then
    ok "مقاله‌های بلاگ بررسی شدند"
else
    warn "واردکردنِ مقاله‌های بلاگ ناموفق بود؛ بعداً دستی: $PHP artisan blog:import"
fi

# ---------------------------------------------------------------- ۵) پایانی
fix_permissions
rebuild_caches
warn_if_debug_public

bring_up
trap - EXIT
[ "$MAINTENANCE" = "1" ] && ok "سایت از حالت تعمیرات خارج شد"

# php-fpm فقط وقتی لازم است که OPcache کد قدیمی را نگه داشته باشد.
# خودمان ریستارت نمی‌کنیم چون سرویس php-fpm ممکن است بین چند سایت مشترک باشد.
printf '\n%s✓ استقرار کامل شد.%s\n\n' "$C_OK" "$C_OFF"
if "$PHP" -m 2>/dev/null | grep -qi 'zend opcache'; then
    note "اگر تغییرات را در سایت نمی‌بینید، OPcache را با ریلود php-fpm تازه کنید:"
    note "  sudo systemctl reload php$("$PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
    note "این فقط همان نسخه را ریلود می‌کند و به سایت‌های روی نسخه‌های دیگر PHP کاری ندارد."
    printf '\n'
fi
