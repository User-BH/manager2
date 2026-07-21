#!/usr/bin/env bash
#
# به‌روزرسانی یک نصب موجود.
#
#   ./scripts/deploy.sh              اگر پوشه مخزن git باشد pull می‌کند، وگرنه رد می‌شود
#   ./scripts/deploy.sh --no-pull    حتی اگر git باشد pull نکن (فایل‌ها را خودتان آپلود کرده‌اید)
#   ./scripts/deploy.sh --no-down    بدون حالت تعمیرات (سایت لحظه‌ای هم قطع نشود)
#
# مثل setup.sh، این اسکریپت هم به nginx، MySQL یا نسخه‌های دیگر PHP دست نمی‌زند.

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

DO_PULL=auto
MAINTENANCE=1
for arg in "$@"; do
    case "$arg" in
        --no-pull) DO_PULL=0 ;;
        --no-down) MAINTENANCE=0 ;;
        -h|--help) sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "گزینهٔ ناشناخته: $arg" ;;
    esac
done

[ -f .env ] || die ".env پیدا نشد. اول ./scripts/setup.sh را اجرا کنید."

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
    git pull --ff-only
    after="$(git rev-parse --short HEAD)"

    if [ "$before" = "$after" ]; then
        ok "کد از قبل به‌روز بود ($after)"
    else
        ok "به‌روزرسانی شد: $before → $after"
    fi
else
    if [ "$DO_PULL" = "0" ]; then
        ok "pull رد شد (--no-pull)"
    else
        ok "مخزن git نیست؛ از فایل‌های موجود روی سرور استفاده می‌شود"
    fi
fi

# ---------------------------------------------------------------- ۲) تعمیرات
# artisan down باید *بعد* از گرفتن کد و *قبل* از migrate باشد.
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
if is_production; then
    composer_run install --no-dev --optimize-autoloader --no-interaction --prefer-dist
else
    composer_run install --no-interaction --prefer-dist
fi
ok "وابستگی‌ها به‌روز شدند"

build_assets

# ---------------------------------------------------------------- ۴) دیتابیس
step "اجرای migrationهای جدید"
pending="$("$PHP" artisan migrate:status 2>/dev/null | grep -ci 'pending' || true)"
if [ "${pending:-0}" -gt 0 ]; then
    "$PHP" artisan migrate --force --no-interaction
    ok "$pending migration اجرا شد"
else
    ok "migration جدیدی نبود"
fi

# ---------------------------------------------------------------- ۵) پایانی
fix_permissions
rebuild_caches

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
