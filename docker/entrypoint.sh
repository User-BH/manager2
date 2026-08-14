#!/bin/sh
# ==============================================================================
#  نقطه‌ی ورودِ کانتینرِ برنامه (R41)
# ==============================================================================
#
#  ⚠️ `set -e` نیست، `set -eu` است.
#
#  `-u` یعنی خواندنِ متغیرِ تعریف‌نشده خطاست. بدونش، غلطِ املایی در نامِ
#  متغیر به رشته‌ی خالی تبدیل می‌شود و اسکریپت با مقدارِ اشتباه ادامه
#  می‌دهد — بدترین حالت، چون هیچ‌جا خطایی دیده نمی‌شود.
set -eu

# ─── انتظار برای دیتابیس ─────────────────────────────────────────────────
#
# ⚠️ `depends_on`ِ کامپوز فقط می‌گوید کانتینر **شروع** شده، نه اینکه MySQL
# آماده‌ی پذیرشِ اتصال است. اولین `migrate` بدونِ این انتظار با
# «Connection refused» می‌افتد و کانتینر می‌میرد.
#
# در compose برای دیتابیس `healthcheck` هست و `depends_on: condition:
# service_healthy` گذاشته شده؛ این حلقه تورِ ایمنیِ دوم است برای وقتی کسی
# کانتینر را دستی و بدونِ کامپوز اجرا می‌کند.
wait_for_database() {
    attempt=1

    while [ "$attempt" -le 30 ]; do
        if php -r "
            \$dsn = 'mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT');
            try { new PDO(\$dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0); }
            catch (Throwable \$e) { exit(1); }
        " 2>/dev/null; then
            echo "[entrypoint] دیتابیس آماده است."
            return 0
        fi

        echo "[entrypoint] انتظار برای دیتابیس… (${attempt}/30)"
        attempt=$((attempt + 1))
        sleep 2
    done

    echo "[entrypoint] دیتابیس در ۶۰ ثانیه آماده نشد." >&2

    return 1
}

# ─── آماده‌سازی ──────────────────────────────────────────────────────────
#
# فقط کانتینرِ وب مهاجرت و کش را انجام می‌دهد. اگر کارگرِ صف هم همین را
# می‌کرد، دو پردازه هم‌زمان `migrate` می‌زدند و جدولِ قفل به‌هم می‌ریخت.
if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    wait_for_database

    echo "[entrypoint] اجرای مهاجرت‌ها…"
    php artisan migrate --force --no-interaction

    # ⚠️ ترتیب مهم است: `config:cache` باید **پس از** آماده‌بودنِ متغیرهای
    # محیطی اجرا شود، وگرنه مقادیرِ خالی داخلِ کش قفل می‌شوند و تا پاک‌شدنِ
    # دستیِ کش هیچ تنظیمی اثر نمی‌کند.
    echo "[entrypoint] ساختِ کشِ پیکربندی…"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    # پیوندِ storage برای فایل‌های عمومی؛ اگر از قبل باشد خطا نمی‌دهد
    php artisan storage:link --force >/dev/null 2>&1 || true
fi

echo "[entrypoint] اجرای: $*"

exec "$@"
