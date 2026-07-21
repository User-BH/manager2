#!/usr/bin/env bash
#
# توابع مشترک setup.sh و deploy.sh
#
# قاعدهٔ کلی این اسکریپت‌ها: هیچ‌چیزی بیرون از پوشهٔ پروژه تغییر نمی‌کند.
# nginx، MySQL، phpMyAdmin و نسخه‌های دیگر PHP که روی سرور هستند دست‌نخورده
# می‌مانند. هرجا کاری نیاز به root داشته باشد، اسکریپت خودش انجام نمی‌دهد و
# فقط دستورش را چاپ می‌کند تا خودتان با آگاهی اجرا کنید.

set -euo pipefail

# ------------------------------------------------------------------ چاپ پیام‌ها
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_OK=$'\033[32m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'
    C_INFO=$'\033[36m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
    C_OK=''; C_WARN=''; C_ERR=''; C_INFO=''; C_DIM=''; C_OFF=''
fi

step()  { printf '\n%s==>%s %s\n' "$C_INFO" "$C_OFF" "$1"; }
ok()    { printf '%s  ✓%s %s\n' "$C_OK" "$C_OFF" "$1"; }
warn()  { printf '%s  ! %s%s\n' "$C_WARN" "$1" "$C_OFF"; }
note()  { printf '%s    %s%s\n' "$C_DIM" "$1" "$C_OFF"; }
die()   { printf '\n%s✗ %s%s\n\n' "$C_ERR" "$1" "$C_OFF" >&2; exit 1; }

# ------------------------------------------------------------------ ریشهٔ پروژه
# اسکریپت‌ها از هر مسیری قابل اجرا هستند.
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_ROOT"

MIN_PHP_ID=80300   # PHP 8.3.0 — پایین‌تر از این، composer.json نصب نمی‌شود

php_version_id() { "$1" -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0; }
php_version()    { "$1" -r 'echo PHP_VERSION;' 2>/dev/null || echo '?'; }

# پیدا کردن مفسر PHP مناسب.
#
# مهم: عمداً `php` خالی آخرین گزینه است. روی سروری که یک سایت دیگر با PHP 7.4
# سرو می‌شود، `php` معمولاً همان ۷.۴ است؛ اگر کورکورانه از آن استفاده کنیم
# کل استقرار شکست می‌خورد. پس اول نسخه‌های نام‌دار جدید را می‌گردیم و در هر
# حالت نسخه را اعتبارسنجی می‌کنیم.
#
# اگر باینری شما نام دیگری دارد (مثلاً مسیر cPanel یا Plesk)، با متغیر محیطی
# مسیرش را بدهید:  PHP_BIN=/opt/php84/bin/php ./scripts/deploy.sh
find_php() {
    if [ -n "${PHP_BIN:-}" ]; then
        command -v "$PHP_BIN" >/dev/null 2>&1 || die "PHP_BIN=$PHP_BIN پیدا نشد."
        [ "$(php_version_id "$PHP_BIN")" -ge "$MIN_PHP_ID" ] \
            || die "PHP_BIN نسخهٔ $(php_version "$PHP_BIN") است؛ حداقل ۸.۳ لازم است."
        echo "$PHP_BIN"; return 0
    fi

    local candidate best='' best_id=0 id
    for candidate in php8.5 php8.4 php8.3 php; do
        command -v "$candidate" >/dev/null 2>&1 || continue
        id="$(php_version_id "$candidate")"
        if [ "$id" -ge "$MIN_PHP_ID" ] && [ "$id" -gt "$best_id" ]; then
            best="$candidate"; best_id="$id"
        fi
    done

    [ -n "$best" ] || die "PHP نسخهٔ ۸.۳ یا بالاتر پیدا نشد. اگر نصب است ولی نام دیگری دارد:  PHP_BIN=/path/to/php $0"
    echo "$best"
}

# اکستنشن‌های لازم Laravel + mPDF + maatwebsite/excel
require_php_extensions() {
    local php="$1" missing=() ext
    for ext in pdo mbstring openssl tokenizer xml ctype json curl fileinfo gd zip bcmath; do
        "$php" -m 2>/dev/null | grep -qix "$ext" || missing+=("$ext")
    done

    # درایور دیتابیس: بسته به چیزی که در .env انتخاب شده
    local driver; driver="$(env_value DB_CONNECTION)"
    case "$driver" in
        mysql|mariadb) "$php" -m | grep -qix pdo_mysql  || missing+=(pdo_mysql) ;;
        sqlite)        "$php" -m | grep -qix pdo_sqlite || missing+=(pdo_sqlite) ;;
        pgsql)         "$php" -m | grep -qix pdo_pgsql  || missing+=(pdo_pgsql) ;;
    esac

    if [ ${#missing[@]} -gt 0 ]; then
        local v; v="$("$php" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
        printf '\n%s✗ اکستنشن‌های PHP زیر نصب نیستند: %s%s\n' "$C_ERR" "${missing[*]}" "$C_OFF" >&2
        note "روی Ubuntu/Debian:"
        note "  sudo apt install $(printf "php${v}-%s " "${missing[@]}")"
        note "این کار فقط بستهٔ PHP ${v} را نصب می‌کند و به نسخه‌های دیگر PHP روی سرور کاری ندارد."
        exit 1
    fi
}

find_composer() {
    local php="$1" c php_dir
    php_dir="$(dirname "$(command -v "$php")")"

    if [ -n "${COMPOSER_BIN:-}" ]; then
        [ -f "$COMPOSER_BIN" ] || die "COMPOSER_BIN=$COMPOSER_BIN پیدا نشد."
        echo "$COMPOSER_BIN"; return 0
    fi

    for c in \
        "$APP_ROOT/composer.phar" \
        "$php_dir/composer.phar" \
        "$(command -v composer 2>/dev/null || true)" \
        "$(command -v composer.phar 2>/dev/null || true)" \
        /usr/local/bin/composer \
        "${HOME:-/root}/.composer/vendor/bin/composer"
    do
        [ -n "$c" ] && [ -f "$c" ] && { echo "$c"; return 0; }
    done

    die "composer پیدا نشد. نصب: https://getcomposer.org/download/
    یا اگر جای دیگری نصب است، مسیرش را بدهید:  COMPOSER_BIN=/path/to/composer $0"
}

# composer را با همان PHP انتخاب‌شده اجرا می‌کنیم.
#
# دلیلش مهم است: فایل composer یک phar با شبانگ `#!/usr/bin/env php` است.
# اگر مستقیم صدایش بزنیم، شبانگ ما را به `php` پیش‌فرض سرور می‌برد که روی
# سرورهای چندنسخه‌ای ممکن است ۷.۴ باشد و نصب با خطای نسخه شکست بخورد.
composer_run() {
    if head -n 1 "$COMPOSER" 2>/dev/null | grep -qi 'php'; then
        "$PHP" "$COMPOSER" "$@"
    else
        # اسکریپت پوششی (wrapper) است، نه phar — خودش اجرا شود
        "$COMPOSER" "$@"
    fi
}

# ------------------------------------------------------------------ خواندن .env
env_value() {
    [ -f "$APP_ROOT/.env" ] || { echo ''; return 0; }
    sed -n "s/^${1}=//p" "$APP_ROOT/.env" | head -1 | sed 's/^"\(.*\)"$/\1/; s/^'"'"'\(.*\)'"'"'$/\1/'
}

is_production() { [ "$(env_value APP_ENV)" = "production" ]; }

# ------------------------------------------------------------------ ساخت assetها
# public/build در .gitignore است، پس روی سروری که با git کد را می‌گیرد باید
# ساخته شود؛ وگرنه سایت بدون CSS بالا می‌آید. اگر Node نبود و پوشه هم نبود،
# با خطا متوقف می‌شویم تا این مورد بی‌سروصدا رد نشود.
build_assets() {
    if [ "${SKIP_BUILD:-0}" = "1" ]; then
        warn "ساخت assetها رد شد (SKIP_BUILD=1)"
        return 0
    fi

    if command -v npm >/dev/null 2>&1; then
        step "ساخت CSS/JS"
        if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi
        npm run build
        ok "assetها ساخته شدند"
        return 0
    fi

    if [ -f public/build/manifest.json ]; then
        warn "npm نصب نیست، ولی public/build از قبل موجود است؛ از همان استفاده می‌شود."
        return 0
    fi

    die "npm نصب نیست و public/build هم وجود ندارد.
    یکی از این دو کار را بکنید:
      • Node.js 18+ را روی سرور نصب کنید، یا
      • روی سیستم خودتان 'npm run build' بزنید و پوشهٔ public/build را آپلود کنید
    بدون این مرحله سایت بدون CSS بالا می‌آید."
}

# ------------------------------------------------------------------ دسترسی فایل
# storage و bootstrap/cache باید برای کاربر وب‌سرور قابل نوشتن باشند.
# chown نیاز به root دارد، پس اگر لازم بود فقط دستورش را نشان می‌دهیم.
fix_permissions() {
    step "بررسی دسترسی نوشتن"
    chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

    local web_user=''
    if id -u www-data >/dev/null 2>&1; then web_user=www-data
    elif id -u nginx  >/dev/null 2>&1; then web_user=nginx
    fi

    local owner; owner="$(stat -c '%U' storage 2>/dev/null || echo '?')"

    if [ -n "$web_user" ] && [ "$owner" != "$web_user" ] && [ "$(id -u)" != "0" ]; then
        warn "مالک storage کاربر '$owner' است، نه '$web_user'."
        note "اگر سایت خطای نوشتن داد، یک‌بار این را با دسترسی root بزنید:"
        note "  sudo chown -R ${web_user}:${web_user} '$APP_ROOT/storage' '$APP_ROOT/bootstrap/cache'"
    else
        ok "دسترسی نوشتن روی storage و bootstrap/cache برقرار است"
    fi
}

# ------------------------------------------------------------------ کش‌های Laravel
rebuild_caches() {
    step "بازسازی کش‌های Laravel"
    "$PHP" artisan config:clear >/dev/null
    "$PHP" artisan view:clear   >/dev/null
    "$PHP" artisan route:clear  >/dev/null

    if is_production; then
        "$PHP" artisan config:cache >/dev/null
        "$PHP" artisan route:cache  >/dev/null
        "$PHP" artisan view:cache   >/dev/null
        ok "کش‌های production ساخته شدند"
    else
        # در محیط توسعه کش کردن config باعث می‌شود تغییرات .env دیده نشود
        ok "کش‌ها پاک شدند (محیط production نیست، کش ساخته نشد)"
    fi
}

banner_php() {
    ok "PHP: $PHP ($(php_version "$PHP"))"
    if command -v php >/dev/null 2>&1; then
        local default_id; default_id="$(php_version_id php)"
        if [ "$default_id" -lt "$MIN_PHP_ID" ]; then
            note "php پیش‌فرض سرور $(php_version php) است و دست‌نخورده می‌ماند؛ سایت‌های دیگر تحت تأثیر قرار نمی‌گیرند."
        fi
    fi
}
