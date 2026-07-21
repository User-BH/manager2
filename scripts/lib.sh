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

# همهٔ مفسرهای PHP موجود روی سیستم را فهرست می‌کند: «مسیر<TAB>نسخه»
#
# فقط به PATH تکیه نمی‌کنیم، چون روی پنل‌های میزبانی (cPanel، Plesk،
# DirectAdmin) و مخازن Remi/SCL نسخهٔ جدید PHP نصب است ولی در PATH نیست.
list_php_binaries() {
    local seen=" " p id
    {
        # نام‌های موجود در PATH
        for p in php8.5 php8.4 php8.3 php8.2 php8.1 php8.0 php7.4 php; do
            command -v "$p" 2>/dev/null || true
        done
        # مسیرهای رایج پنل‌های میزبانی و مخازن چندنسخه‌ای
        ls -1 /usr/bin/php8.* /usr/local/bin/php8.* 2>/dev/null || true
        ls -1 /opt/php*/bin/php /usr/local/php*/bin/php 2>/dev/null || true
        ls -1 /opt/cpanel/ea-php8*/root/usr/bin/php 2>/dev/null || true
        ls -1 /opt/plesk/php/8.*/bin/php 2>/dev/null || true
        ls -1 /opt/remi/php8*/root/usr/bin/php 2>/dev/null || true
        ls -1 /usr/local/lsws/lsphp8*/bin/lsphp 2>/dev/null || true
    } | while read -r p; do
        [ -n "$p" ] && [ -x "$p" ] || continue
        p="$(readlink -f "$p" 2>/dev/null || echo "$p")"
        case "$seen" in *" $p "*) continue ;; esac
        seen="$seen$p "
        id="$(php_version_id "$p")"
        [ "$id" != "0" ] && printf '%s\t%s\n' "$p" "$(php_version "$p")"
    done
}

# پیدا کردن مفسر PHP مناسب.
#
# مهم: عمداً `php` خالی آخرین گزینه است. روی سروری که یک سایت دیگر با PHP 7.4
# سرو می‌شود، `php` معمولاً همان ۷.۴ است؛ اگر کورکورانه از آن استفاده کنیم
# کل استقرار شکست می‌خورد. پس نسخهٔ همهٔ باینری‌های پیداشده را می‌سنجیم و
# جدیدترینِ واجد شرایط را برمی‌داریم.
find_php() {
    if [ -n "${PHP_BIN:-}" ]; then
        [ -x "$PHP_BIN" ] || command -v "$PHP_BIN" >/dev/null 2>&1 \
            || die "PHP_BIN=$PHP_BIN پیدا نشد یا قابل اجرا نیست."
        [ "$(php_version_id "$PHP_BIN")" -ge "$MIN_PHP_ID" ] \
            || die "PHP_BIN نسخهٔ $(php_version "$PHP_BIN") است؛ حداقل ۸.۳ لازم است."
        echo "$PHP_BIN"; return 0
    fi

    local found best='' best_id=0 path ver id
    found="$(list_php_binaries)"

    while IFS=$'\t' read -r path ver; do
        [ -n "$path" ] || continue
        id="$(php_version_id "$path")"
        if [ "$id" -ge "$MIN_PHP_ID" ] && [ "$id" -gt "$best_id" ]; then
            best="$path"; best_id="$id"
        fi
    done <<< "$found"

    if [ -n "$best" ]; then
        echo "$best"; return 0
    fi

    # هیچ نسخهٔ مناسبی نبود — به‌جای یک پیام کلی، بگوییم چه چیزی *هست*.
    {
        printf '\n%s✗ PHP نسخهٔ ۸.۳ یا بالاتر پیدا نشد.%s\n\n' "$C_ERR" "$C_OFF"
        if [ -n "$found" ]; then
            printf '  نسخه‌هایی که روی این سرور پیدا شد:\n'
            while IFS=$'\t' read -r path ver; do
                [ -n "$path" ] && printf '    %-46s %s\n' "$path" "$ver"
            done <<< "$found"
        else
            printf '  هیچ مفسر PHP‌ای پیدا نشد.\n'
        fi
        printf '\n  این پروژه به PHP 8.3+ نیاز دارد (Laravel 13).\n'
        printf '\n  %sنصب PHP 8.4 در کنار نسخهٔ فعلی — سایت‌های موجود آسیب نمی‌بینند:%s\n' "$C_INFO" "$C_OFF"
        printf '    Ubuntu/Debian:\n'
        printf '      sudo add-apt-repository ppa:ondrej/php && sudo apt update\n'
        printf '      sudo apt install php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml \\\n'
        printf '           php8.4-zip php8.4-gd php8.4-curl php8.4-intl php8.4-bcmath\n'
        printf '\n    AlmaLinux/Rocky/CentOS:\n'
        printf '      sudo dnf install epel-release && sudo dnf install https://rpms.remirepo.net/enterprise/remi-release-9.rpm\n'
        printf '      sudo dnf module install php:remi-8.4\n'
        printf '\n  %sنسخهٔ قدیمی حذف نمی‌شود:%s بسته‌ها، سرویس php-fpm و سوکت هر نسخه جداست،\n' "$C_DIM" "$C_OFF"
        printf '  و کانفیگ nginx سایت قبلی همچنان به سوکت خودش اشاره می‌کند.\n'
        printf '\n  اگر PHP 8.3+ نصب است ولی بالا فهرست نشده، مسیرش را مستقیم بدهید:\n'
        printf '    PHP_BIN=/full/path/to/php %s\n\n' "${0##*/}"
    } >&2
    exit 1
}

# نگاشت نام اکستنشن به نام بستهٔ سیستمی.
#
# این نگاشت لازم است چون نام بسته و نام اکستنشن یکی نیستند: بستهٔ php-xml
# پنج اکستنشن (dom, simplexml, xml, xmlreader, xmlwriter) می‌دهد و pdo_mysql
# از بستهٔ php-mysql می‌آید. بسته‌هایی به نام php8.4-ctype یا php8.4-json
# اصلاً وجود ندارند؛ این‌ها یا در php-common هستند یا در هستهٔ PHP.
ext_package() {
    case "$1" in
        dom|xml|simplexml|xmlreader|xmlwriter|xsl) echo xml ;;
        pdo_mysql|mysqli|mysqlnd)                  echo mysql ;;
        pdo_sqlite|sqlite3)                        echo sqlite3 ;;
        pdo_pgsql|pgsql)                           echo pgsql ;;
        # این‌ها در بستهٔ php-common هستند، نه بستهٔ هم‌نام خودشان
        pdo|ctype|fileinfo|tokenizer|calendar|exif|ftp|iconv|phar|posix) echo common ;;
        # این‌ها داخل هستهٔ PHP کامپایل می‌شوند و بستهٔ جدا ندارند
        json|filter|hash|pcre|spl|session|standard|core|date|reflection|openssl) echo '@core' ;;
        *) echo "$1" ;;   # mbstring، curl، gd، zip، bcmath، intl و…
    esac
}

# اکستنشن‌های لازم Laravel + mPDF + maatwebsite/excel
require_php_extensions() {
    local php="$1" missing=() ext modules

    # یک‌بار می‌خوانیم تا هم سریع‌تر باشد، هم بتوانیم هنگام خطا نشانش دهیم.
    modules="$("$php" -m 2>/dev/null | tr -d '\r')"

    [ -n "$modules" ] || die "دستور '$php -m' خروجی نداد؛ نصب PHP سالم به‌نظر نمی‌رسد."

    local required="pdo mbstring openssl tokenizer xml ctype json curl fileinfo gd zip bcmath"

    # درایور دیتابیس: بسته به چیزی که در .env انتخاب شده
    case "$(env_value DB_CONNECTION)" in
        mysql|mariadb) required="$required pdo_mysql" ;;
        sqlite)        required="$required pdo_sqlite" ;;
        pgsql)         required="$required pdo_pgsql" ;;
    esac

    for ext in $required; do
        printf '%s\n' "$modules" | grep -qix "$ext" || missing+=("$ext")
    done

    [ ${#missing[@]} -gt 0 ] || return 0

    local v pkgs=() core_missing=() pkg
    v="$("$php" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

    for ext in "${missing[@]}"; do
        pkg="$(ext_package "$ext")"
        if [ "$pkg" = "@core" ]; then
            core_missing+=("$ext")
            continue
        fi
        case " ${pkgs[*]-} " in *" $pkg "*) ;; *) pkgs+=("$pkg") ;; esac
    done

    {
        printf '\n%s✗ اکستنشن‌های PHP زیر نصب نیستند: %s%s\n\n' "$C_ERR" "${missing[*]}" "$C_OFF"

        if [ ${#pkgs[@]} -gt 0 ]; then
            if command -v apt-get >/dev/null 2>&1; then
                printf '  Ubuntu/Debian:\n    sudo apt install %s\n' "$(printf "php${v}-%s " "${pkgs[@]}")"
            elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
                printf '  RHEL/AlmaLinux/Rocky:\n    sudo dnf install %s\n' "$(printf "php-%s " "${pkgs[@]}")"
            else
                printf '  بسته‌های لازم: %s\n' "${pkgs[*]}"
            fi
            printf '\n  سپس:  sudo systemctl restart php%s-fpm\n' "$v"
            printf '  این کار فقط بسته‌های PHP %s را نصب می‌کند و نسخه‌های دیگر PHP دست‌نخورده می‌مانند.\n' "$v"
        fi

        # روی دبیان/اوبونتو ممکن است بسته نصب باشد ولی ماژول غیرفعال شده باشد؛
        # در آن حالت apt کاری نمی‌کند و باید با phpenmod فعالش کرد.
        if command -v phpenmod >/dev/null 2>&1; then
            printf '\n  اگر apt گفت بسته‌ها از قبل نصب‌اند، یعنی ماژول‌ها غیرفعال شده‌اند:\n'
            printf '    sudo phpenmod -v %s %s\n' "$v" "${missing[*]}"
            printf '    sudo systemctl restart php%s-fpm\n' "$v"
        fi

        if [ ${#core_missing[@]} -gt 0 ]; then
            printf '\n  %sهشدار:%s این اکستنشن‌ها معمولاً داخل هستهٔ PHP هستند و بستهٔ جدا ندارند:\n' "$C_WARN" "$C_OFF"
            printf '    %s\n' "${core_missing[*]}"
            printf '  نبودنشان یعنی نصب PHP ناقص است یا در php.ini غیرفعال شده‌اند.\n'
            printf '  فایل‌های پیکربندی را ببینید:  %s --ini\n' "$php"
        fi

        printf '\n  %sاکستنشن‌هایی که همین حالا فعال‌اند:%s\n' "$C_DIM" "$C_OFF"
        printf '%s\n' "$modules" | grep -v '^\[' | grep -v '^$' | paste -sd' ' - | fold -s -w 76 | sed 's/^/    /'
        printf '\n'
    } >&2

    exit 1
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

# artisan فقط وقتی قابل اجراست که وابستگی‌ها نصب شده باشند؛ روی یک کلون تازه
# پوشهٔ vendor وجود ندارد و هر فراخوانی artisan با خطای autoload می‌شکند.
has_vendor() { [ -f "$APP_ROOT/vendor/autoload.php" ]; }

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
# Vite 8 نسخهٔ Node را محدود کرده: ^20.19.0 || >=22.12.0
# با Node قدیمی‌تر، npm ci خطای مبهم می‌دهد؛ پس زودتر و با پیام روشن می‌ایستیم.
node_version_ok() {
    local v major minor
    v="$(node -v 2>/dev/null | sed 's/^v//')"
    [ -n "$v" ] || return 1
    major="${v%%.*}"
    minor="$(printf '%s' "$v" | cut -d. -f2)"

    if   [ "$major" -ge 23 ]; then return 0
    elif [ "$major" -eq 22 ] && [ "$minor" -ge 12 ]; then return 0
    elif [ "$major" -eq 20 ] && [ "$minor" -ge 19 ]; then return 0
    fi
    return 1
}

print_node_install_help() {
    printf '  %sنصب Node.js 22 (LTS):%s\n' "$C_INFO" "$C_OFF"
    if command -v apt-get >/dev/null 2>&1; then
        printf '    curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -\n'
        printf '    sudo apt install -y nodejs\n'
    elif command -v dnf >/dev/null 2>&1; then
        printf '    sudo dnf module reset nodejs && sudo dnf module enable nodejs:22\n'
        printf '    sudo dnf install -y nodejs\n'
    else
        printf '    https://nodejs.org/en/download\n'
    fi
    printf '\n  Node فقط برای ساخت فایل‌های CSS/JS استفاده می‌شود؛ به PHP، nginx و\n'
    printf '  سایت‌های دیگر روی این سرور کاری ندارد.\n'
    printf '\n  %sاگر نمی‌خواهید Node روی سرور نصب کنید:%s\n' "$C_DIM" "$C_OFF"
    printf '    روی سیستم خودتان «npm run build» بزنید و پوشهٔ public/build را\n'
    printf '    به %s/public/ آپلود کنید.\n' "$APP_ROOT"
}

build_assets() {
    if [ "${SKIP_BUILD:-0}" = "1" ]; then
        warn "ساخت assetها رد شد (SKIP_BUILD=1)"
        return 0
    fi

    if command -v npm >/dev/null 2>&1 && command -v node >/dev/null 2>&1; then
        if ! node_version_ok; then
            if [ -f public/build/manifest.json ]; then
                warn "نسخهٔ Node ($(node -v)) برای Vite قدیمی است؛ از public/build موجود استفاده می‌شود."
                return 0
            fi
            {
                printf '\n%s✗ نسخهٔ Node روی این سرور %s است؛ Vite به ^20.19 یا >=22.12 نیاز دارد.%s\n\n' \
                    "$C_ERR" "$(node -v)" "$C_OFF"
                print_node_install_help
                printf '\n'
            } >&2
            exit 1
        fi

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

    {
        printf '\n%s✗ npm نصب نیست و public/build هم وجود ندارد.%s\n' "$C_ERR" "$C_OFF"
        printf '  بدون این مرحله سایت بدون CSS بالا می‌آید.\n\n'
        print_node_install_help
        printf '\n'
    } >&2
    exit 1
}

# ------------------------------------------------------------------ دسترسی فایل
# کاربری که واقعاً PHP را اجرا می‌کند. از روی پروسهٔ در حال اجرا می‌خوانیم،
# چون حدس‌زدن «www-data» روی همهٔ توزیع‌ها درست نیست (nginx، apache، پنل‌های
# میزبانی هرکدام کاربر خودشان را دارند). پروسهٔ master با root اجرا می‌شود،
# پس آن را کنار می‌گذاریم و دنبال کاربر workerها می‌گردیم.
detect_web_user() {
    local u=''
    for proc in php-fpm php8.4-fpm php8.3-fpm nginx apache2 httpd; do
        u="$(ps -o user= -C "$proc" 2>/dev/null | sort -u | grep -v '^root$' | head -1)"
        [ -n "$u" ] && { printf '%s' "$u"; return 0; }
    done
    for u in www-data nginx apache; do
        id -u "$u" >/dev/null 2>&1 && { printf '%s' "$u"; return 0; }
    done
    printf ''
}

# storage و bootstrap/cache باید برای کاربر وب‌سرور قابل نوشتن باشند.
#
# نکتهٔ مهم: اگر اسکریپت با root اجرا شود، همهٔ فایل‌هایی که composer و artisan
# می‌سازند مالکشان root می‌شود و php-fpm (که با www-data اجرا می‌شود) نمی‌تواند
# در storage بنویسد. نتیجه‌اش خطای ۵۰۰ هنگام کامپایل قالب‌های Blade است. چون
# در آن حالت خودمان root هستیم، مالکیت را همان‌جا اصلاح می‌کنیم.
fix_permissions() {
    step "بررسی دسترسی نوشتن"

    # روی کلون تازه ممکن است زیرپوشه‌ها ساخته نشده باشند
    mkdir -p storage/framework/views storage/framework/cache/data \
             storage/framework/sessions storage/logs bootstrap/cache 2>/dev/null || true
    chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

    local web_user owner
    web_user="$(detect_web_user)"
    owner="$(stat -c '%U' storage 2>/dev/null || echo '?')"

    if [ -z "$web_user" ]; then
        ok "مجوزها تنظیم شد (کاربر وب‌سرور تشخیص داده نشد)"
        return 0
    fi

    if [ "$owner" = "$web_user" ]; then
        ok "storage و bootstrap/cache متعلق به '$web_user' هستند"
        return 0
    fi

    if [ "$(id -u)" = "0" ]; then
        chown -R "$web_user:$web_user" storage bootstrap/cache
        ok "مالکیت storage و bootstrap/cache از '$owner' به '$web_user' تغییر کرد"
        return 0
    fi

    # نه root هستیم و نه مالک درست است — این حالت قطعاً به خطای ۵۰۰ می‌رسد،
    # پس نباید آن را به‌عنوان موفقیت گزارش کنیم.
    warn "مالک storage کاربر '$owner' است، نه '$web_user' — سایت خطای ۵۰۰ می‌دهد."
    note "این را با دسترسی root بزنید:"
    note "  sudo chown -R ${web_user}:${web_user} '$APP_ROOT/storage' '$APP_ROOT/bootstrap/cache'"
}

# ------------------------------------------------------------------ اختلاف .env
# وقتی PDO با مقادیر خام .env وصل می‌شود ولی Laravel «Access denied» می‌گیرد،
# یعنی پارسر Dotenv مقدار دیگری از همان خط برداشت کرده.
#
# Dotenv روی مقدارِ بدون کوتیشن این کارها را می‌کند:
#   • از اولین #، بقیهٔ خط را کامنت حساب می‌کند   →  pa#ss  به  pa  تبدیل می‌شود
#   • ${VAR} و گاهی $VAR را جایگزین می‌کند
#   • فاصله‌های ابتدا و انتها را حذف می‌کند
# در حالی که خواندن ساده با sed (کاری که PDO probe می‌کند) عین متن را برمی‌دارد.
#
# طول رمز را مقایسه می‌کنیم، نه خودش را؛ رمز هیچ‌وقت چاپ نمی‌شود.
explain_env_mismatch() {
    local php="$1" raw_len laravel_len raw

    raw="$(env_value DB_PASSWORD)"
    raw_len="${#raw}"

    laravel_len="$("$php" -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $c = config("database.default");
        echo strlen((string) config("database.connections.$c.password"));
    ' 2>/dev/null | tail -1)"

    printf '\n  %sطول رمز عبور:%s\n' "$C_DIM" "$C_OFF"
    printf '    آنچه در .env نوشته شده : %s کاراکتر\n' "$raw_len"
    printf '    آنچه Laravel می‌خواند   : %s کاراکتر\n' "${laravel_len:-؟}"

    if [ -n "$laravel_len" ] && [ "$laravel_len" != "$raw_len" ]; then
        printf '\n    %s← همین است.%s Laravel رمز را ناقص می‌خواند.\n' "$C_ERR" "$C_OFF"
    fi

    printf '\n  %sراه‌حل: رمز را داخل کوتیشن تکی بگذارید:%s\n' "$C_INFO" "$C_OFF"
    printf "    DB_PASSWORD='رمز-دقیق-شما'\n"
    printf '\n    کوتیشن تکی جلوی کامنت‌شدن از # و جایگزینی $ را می‌گیرد.\n'
    printf '    اگر خود رمز کوتیشن تکی دارد، از کوتیشن دوتایی استفاده کنید.\n'
    printf '\n    سپس دوباره:  ./scripts/setup.sh\n'
}

# ------------------------------------------------------------------ دیتابیس
# بررسی اتصال، با نمایش *علت واقعی* خطا.
#
# نسخهٔ اول این تابع خروجی artisan را به /dev/null می‌فرستاد و فقط می‌گفت
# «وصل نشد»، که برای عیب‌یابی از راه دور بی‌فایده است. حالا هم پیام Laravel
# و هم خطای خام PDO چاپ می‌شود، چون هرکدام یک بخش از ماجرا را می‌گویند:
# Laravel مشکلات پیکربندی را نشان می‌دهد و PDO مشکل واقعی شبکه/دسترسی را.
check_database() {
    local php="$1" out driver host port name user

    driver="$(env_value DB_CONNECTION)"
    host="$(env_value DB_HOST)"; port="$(env_value DB_PORT)"
    name="$(env_value DB_DATABASE)"; user="$(env_value DB_USERNAME)"

    step "بررسی اتصال به دیتابیس"

    # کش کانفیگ باقی‌مانده از اجرای قبلی باعث می‌شود artisan مقادیر قدیمی را
    # بخواند و تغییرات .env نادیده گرفته شود — یکی از شایع‌ترین علت‌های
    # «ورودی‌ها درست است ولی وصل نمی‌شود».
    if [ -f bootstrap/cache/config.php ]; then
        "$php" artisan config:clear >/dev/null 2>&1 || true
        note "کش کانفیگ قدیمی پاک شد تا مقادیر .env دوباره خوانده شوند"
    fi

    if out="$("$php" artisan db:show --json 2>&1)"; then
        ok "اتصال برقرار است (${driver}${name:+ / $name})"
        return 0
    fi

    {
        printf '\n%s✗ اتصال به دیتابیس برقرار نشد.%s\n\n' "$C_ERR" "$C_OFF"

        printf '  %sمقادیری که Laravel از .env خواند:%s\n' "$C_DIM" "$C_OFF"
        printf '    DB_CONNECTION=%s\n' "${driver:-<خالی>}"
        printf '    DB_HOST=%s   DB_PORT=%s\n' "${host:-<خالی>}" "${port:-<خالی>}"
        printf '    DB_DATABASE=%s   DB_USERNAME=%s\n' "${name:-<خالی>}" "${user:-<خالی>}"
        printf '    DB_PASSWORD=%s\n' "$([ -n "$(env_value DB_PASSWORD)" ] && echo '<تنظیم شده>' || echo '<خالی>')"

        # فقط خط‌های معنادار؛ stack trace کامل Laravel ده‌ها خط است و بخش
        # مفیدتر (خطای PDO) را از صفحه بیرون می‌راند.
        printf '\n  %sپیام خطای Laravel:%s\n' "$C_DIM" "$C_OFF"
        local summary
        summary="$(printf '%s\n' "$out" | grep -aE 'SQLSTATE|Exception|Error|could not|Access denied' | head -3)"
        printf '%s\n' "${summary:-$(printf '%s\n' "$out" | head -6)}" \
            | sed 's/^[[:space:]]*//; s/^/    /'

        # خطای خام PDO دقیق‌ترین سرنخ را می‌دهد: «Access denied»، «Unknown
        # database»، «Connection refused» یا «No such file or directory»
        # (یعنی سوکت اشتباه) هرکدام راه‌حل کاملاً متفاوتی دارند.
        if [ "$driver" = "mysql" ] || [ "$driver" = "mariadb" ]; then
            local pdo_err
            pdo_err="$(DB_H="$host" DB_P="$port" DB_N="$name" DB_U="$user" \
                DB_W="$(env_value DB_PASSWORD)" "$php" -r '
                $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s",
                    getenv("DB_H") ?: "127.0.0.1", getenv("DB_P") ?: "3306", getenv("DB_N"));
                try { new PDO($dsn, getenv("DB_U"), getenv("DB_W"),
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                      echo "PDO_OK"; }
                catch (Throwable $e) { echo $e->getMessage(); }
            ' 2>&1)"

            printf '\n  %sاتصال مستقیم PDO:%s\n' "$C_DIM" "$C_OFF"
            if [ "$pdo_err" = "PDO_OK" ]; then
                printf '    %sوصل شد!%s\n\n' "$C_WARN" "$C_OFF"
                printf '  → دیتابیس، کاربر و رمز همگی درست‌اند. مشکل این است که Laravel\n'
                printf '    مقدار متفاوتی از .env می‌خواند.\n'
                explain_env_mismatch "$php"
            else
                printf '    %s\n' "$pdo_err"

                # روی کد عددی MySQL تطبیق می‌دهیم، نه متن پیام: متن بین
                # لینوکس/ویندوز و بین نسخه‌های MariaDB فرق می‌کند، ولی کد ثابت است.
                case "$pdo_err" in
                    *"[1045]"*|*"Access denied"*)
                        printf '\n  → نام کاربری یا رمز اشتباه است، یا کاربر از این host مجوز ندارد:\n'
                        printf "    CREATE USER '%s'@'%s' IDENTIFIED BY 'رمز';\n" "${user:-USER}" "$([ "$host" = "127.0.0.1" ] && echo '127.0.0.1' || echo 'localhost')"
                        printf "    GRANT ALL PRIVILEGES ON %s.* TO '%s'@'%s';\n" "${name:-DBNAME}" "${user:-USER}" "$([ "$host" = "127.0.0.1" ] && echo '127.0.0.1' || echo 'localhost')"
                        printf '    FLUSH PRIVILEGES;\n'
                        printf '\n    نکته: مجوز کاربر برای %s@localhost و %s@127.0.0.1 جداست.\n' "${user:-USER}" "${user:-USER}" ;;
                    *"[1049]"*|*"Unknown database"*)
                        printf '\n  → دیتابیس ساخته نشده است:\n'
                        printf '    CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "${name:-DBNAME}" ;;
                    *"No such file or directory"*)
                        printf '\n  → با «localhost» از سوکت یونیکس وصل می‌شود و مسیر سوکت پیدا نشد.\n'
                        printf '    در .env مقدار DB_HOST=127.0.0.1 بگذارید تا از TCP وصل شود.\n' ;;
                    *"[2002]"*|*"refused"*)
                        printf '\n  → سرویس دیتابیس روی %s:%s پاسخ نمی‌دهد.\n' "${host:-127.0.0.1}" "${port:-3306}"
                        printf '    sudo systemctl status mariadb    # یا mysql\n'
                        printf '    ss -lntp | grep %s\n' "${port:-3306}"
                        printf '\n    اگر MySQL فقط روی سوکت گوش می‌دهد، DB_HOST=localhost را امتحان کنید.\n' ;;
                    *"[2006]"*|*"gone away"*)
                        printf '\n  → اتصال قطع شد؛ معمولاً محدودیت max_allowed_packet یا timeout سرور است.\n' ;;
                esac
            fi
        fi
        printf '\n'
    } >&2

    exit 1
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

# APP_DEBUG=true روی سایت عمومی، کد منبع، مسیرها و گاهی مقادیر .env را در
# صفحهٔ خطا به هر بازدیدکننده‌ای نشان می‌دهد.
warn_if_debug_public() {
    local url; url="$(env_value APP_URL)"

    [ "$(env_value APP_DEBUG)" = "true" ] || return 0
    case "$url" in ''|*localhost*|*127.0.0.1*) return 0 ;; esac

    printf '\n%s! هشدار امنیتی: APP_DEBUG=true و سایت روی %s در دسترس عموم است.%s\n' \
        "$C_WARN" "$url" "$C_OFF"
    note "صفحهٔ خطا کد منبع و مسیرهای سرور را به هر بازدیدکننده نشان می‌دهد."
    note "در .env این دو مقدار را بگذارید و دوباره اسکریپت را اجرا کنید:"
    note "  APP_ENV=production"
    note "  APP_DEBUG=false"
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
