#!/usr/bin/env bash
#
# راه‌اندازی اولیهٔ پلتفرم روی یک سرور (یا سیستم محلی).
#
#   ./scripts/setup.sh                 راه‌اندازی معمولی
#   ./scripts/setup.sh --demo          به‌همراه دادهٔ نمونه (DemoSeeder)
#   ./scripts/setup.sh --sqlite        دیتابیس SQLite برای تست سریع، بدون MySQL
#
# این اسکریپت به nginx، MySQL، phpMyAdmin یا نسخه‌های دیگر PHP دست نمی‌زند.
# در پایان، یک نمونه کانفیگ nginx برای شما تولید می‌کند تا خودتان — در صورت
# تمایل — کنار کانفیگ فعلی سرور اضافه کنید.

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

WITH_DEMO=0
USE_SQLITE=0
for arg in "$@"; do
    case "$arg" in
        --demo)   WITH_DEMO=1 ;;
        --sqlite) USE_SQLITE=1 ;;
        -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "گزینهٔ ناشناخته: $arg" ;;
    esac
done

# روی نصب تازه هنوز .env وجود ندارد، پس env_value خالی برمی‌گرداند.
APP_LABEL="$(env_value APP_NAME)"
printf '\n%sراه‌اندازی %s%s\n' "$C_INFO" "${APP_LABEL:-پلتفرم مدیریت مجتمع}" "$C_OFF"
note "مسیر پروژه: $APP_ROOT"

# ---------------------------------------------------------------- ۱) ابزارها
step "بررسی PHP و Composer"
PHP="$(find_php)"
COMPOSER="$(find_composer "$PHP")"
banner_php
ok "Composer: $COMPOSER"

# ---------------------------------------------------------------- ۲) فایل .env
step "تنظیم فایل .env"
ENV_CREATED=0
if [ ! -f .env ]; then
    cp .env.example .env
    ENV_CREATED=1
    ok ".env از روی .env.example ساخته شد"
else
    ok ".env از قبل وجود دارد و دست‌نخورده می‌ماند"
fi

if [ "$USE_SQLITE" = "1" ]; then
    # فقط برای تست سریع؛ برای سرویس واقعی MySQL را در .env تنظیم کنید.
    touch database/database.sqlite
    sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
    sed -i 's/^\(DB_HOST\|DB_PORT\|DB_DATABASE\|DB_USERNAME\|DB_PASSWORD\)=/# &/' .env
    ok "دیتابیس روی SQLite تنظیم شد (database/database.sqlite)"
fi

# اگر .env تازه ساخته شده و روی MySQL است، اطلاعات دیتابیس هنوز نمونه است.
# بهتر است همین‌جا متوقف شویم تا اسکریپت با اطلاعات اشتباه به دیتابیس وصل نشود.
if [ "$ENV_CREATED" = "1" ] && [ "$USE_SQLITE" = "0" ]; then
    printf '\n%s! فایل .env تازه ساخته شد و هنوز اطلاعات واقعی ندارد.%s\n' "$C_WARN" "$C_OFF"
    note "این مقادیر را در $APP_ROOT/.env تنظیم کنید و دوباره همین اسکریپت را اجرا کنید:"
    note "  APP_ENV=production        APP_DEBUG=false"
    note "  APP_URL=https://your-domain.com"
    note "  DB_DATABASE / DB_USERNAME / DB_PASSWORD"
    note ""
    note "برای یک تست سریع بدون MySQL: ./scripts/setup.sh --sqlite"
    exit 0
fi

require_php_extensions "$PHP"

# ---------------------------------------------------------------- ۳) وابستگی‌ها
step "نصب وابستگی‌های PHP"
if is_production; then
    composer_run install --no-dev --optimize-autoloader --no-interaction --prefer-dist
    ok "وابستگی‌ها نصب شدند (بدون بسته‌های توسعه)"
else
    composer_run install --no-interaction --prefer-dist
    ok "وابستگی‌ها نصب شدند"
fi

# ---------------------------------------------------------------- ۴) APP_KEY
# این بخش باید *بعد از* composer باشد: artisan بدون vendor/autoload.php
# اصلاً بالا نمی‌آید، و روی یک کلون تازه پوشهٔ vendor هنوز وجود ندارد.
step "کلید رمزنگاری برنامه"
if [ -z "$(env_value APP_KEY)" ]; then
    "$PHP" artisan key:generate --force --no-interaction >/dev/null
    ok "APP_KEY ساخته شد"
else
    ok "APP_KEY از قبل تنظیم است"
fi

# ---------------------------------------------------------------- ۵) assetها
build_assets

# ---------------------------------------------------------------- ۶) دیتابیس
check_database "$PHP"

step "ساخت جدول‌ها"
if [ "$WITH_DEMO" = "1" ]; then
    "$PHP" artisan migrate --seed --force --no-interaction
    ok "جدول‌ها ساخته و دادهٔ نمونه وارد شد"
    note "ورود با شماره 09120000001 (ادمین کل) و رمز: password"
else
    "$PHP" artisan migrate --force --no-interaction
    ok "جدول‌ها ساخته شدند"
fi

# migrate فقط جدول خالی می‌سازد. اگر هیچ کاربری وجود نداشته باشد، سامانه
# بالا می‌آید ولی هیچ‌کس نمی‌تواند وارد شود — و این حالت نباید به‌عنوان
# «راه‌اندازی موفق» گزارش شود.
USER_COUNT="$(count_users "$PHP")"
if [ "${USER_COUNT:-0}" = "0" ]; then
    printf '\n%s! دیتابیس هیچ کاربری ندارد؛ فعلاً امکان ورود به پنل نیست.%s\n' "$C_WARN" "$C_OFF"
    note "یکی از این دو را انجام دهید:"
    note ""
    note "  ۱) ساخت ادمین واقعی (پیشنهاد برای سرویس واقعی):"
    note "     $PHP artisan admin:create"
    note ""
    note "  ۲) وارد کردن دادهٔ نمونه برای تست (مجتمع، واحدها، قبوض ساختگی):"
    note "     ./scripts/setup.sh --demo"
    NO_USERS=1
else
    ok "تعداد کاربران موجود: $USER_COUNT"
fi

# ---------------------------------------------------------------- ۷) پایانی
fix_permissions
rebuild_caches
warn_if_debug_public

# نمونه کانفیگ nginx تولید می‌شود ولی *اعمال نمی‌شود* — تصمیمش با شماست.
"$(dirname "${BASH_SOURCE[0]}")/make-nginx-config.sh" "$PHP" || true

if [ "${NO_USERS:-0}" = "1" ]; then
    printf '\n%s✓ نصب کامل شد، اما هنوز کاربری برای ورود ساخته نشده.%s\n\n' "$C_WARN" "$C_OFF"
    note "قدم بعدی:  $PHP artisan admin:create"
else
    printf '\n%s✓ راه‌اندازی کامل شد.%s\n\n' "$C_OK" "$C_OFF"
fi

note "برای تست محلی:      $PHP artisan serve"
note "برای به‌روزرسانی بعدی: ./scripts/deploy.sh"
printf '\n'
