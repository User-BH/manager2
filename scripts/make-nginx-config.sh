#!/usr/bin/env bash
#
# یک نمونه کانفیگ nginx برای این پلتفرم تولید می‌کند.
#
# این اسکریپت *هیچ فایلی در /etc/nginx نمی‌نویسد و nginx را ریلود نمی‌کند*.
# خروجی را داخل storage/app/ می‌گذارد تا خودتان ببینید، ویرایش کنید و در صورت
# تمایل کپی کنید. دلیلش این است که روی سرور شما ممکن است سایت‌های دیگری
# (حتی روی PHP 7.4) سرو شوند و دست‌زدن خودکار به کانفیگ nginx می‌تواند
# آن‌ها را از کار بیندازد.
#
#   ./scripts/make-nginx-config.sh [php-binary]

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

PHP="${1:-$(find_php)}"
PHP_MM="$("$PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

# دامنه را از APP_URL برمی‌داریم
APP_URL="$(env_value APP_URL)"
DOMAIN="$(printf '%s' "$APP_URL" | sed -E 's#^https?://##; s#/.*$##')"
[ -n "$DOMAIN" ] && [ "$DOMAIN" != "localhost" ] || DOMAIN="your-domain.com"

# سوکت php-fpm همان نسخه‌ای که پروژه با آن اجرا می‌شود.
# اگر پیدا نشد، مقدار حدسی می‌گذاریم و هشدار می‌دهیم.
SOCKET=""
for candidate in \
    "/run/php/php${PHP_MM}-fpm.sock" \
    "/var/run/php/php${PHP_MM}-fpm.sock" \
    "/run/php-fpm/php${PHP_MM}-fpm.sock" \
    "/var/run/php-fpm/www.sock"
do
    [ -S "$candidate" ] && { SOCKET="$candidate"; break; }
done

SOCKET_FOUND=1
if [ -z "$SOCKET" ]; then
    SOCKET="/run/php/php${PHP_MM}-fpm.sock"
    SOCKET_FOUND=0
fi

OUT="$APP_ROOT/storage/app/nginx-${DOMAIN}.conf"
mkdir -p "$(dirname "$OUT")"

cat > "$OUT" <<CONF
# ------------------------------------------------------------------
# نمونه کانفیگ nginx برای «$(env_value APP_NAME)»
# تولیدشده توسط scripts/make-nginx-config.sh — قبل از استفاده مرور کنید.
#
# نکتهٔ مهم: fastcgi_pass به سوکت PHP ${PHP_MM} اشاره می‌کند. اگر سایت‌های
# دیگری روی این سرور با نسخهٔ دیگری از PHP (مثلاً 7.4) کار می‌کنند، آن‌ها
# سوکت خودشان را دارند و این بلوک هیچ تأثیری روی‌شان ندارد.
# ------------------------------------------------------------------

server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    # ریشه حتماً باید پوشهٔ public/ باشد، نه ریشهٔ پروژه.
    root ${APP_ROOT}/public;
    index index.php;

    charset utf-8;
    client_max_body_size 8M;          # آپلود رسید پرداخت تا ۴ مگابایت

    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # فایل‌های ساخته‌شدهٔ Vite هش دارند، پس می‌توانند طولانی کش شوند.
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    # سرویس‌ورکر نباید کش شود، وگرنه نسخهٔ قدیمی PWA گیر می‌کند.
    location = /sw.js {
        add_header Cache-Control "no-cache";
        try_files \$uri =404;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:${SOCKET};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120;
    }

    # جلوگیری از دسترسی به فایل‌های مخفی مثل .env و .git
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
CONF

step "نمونه کانفیگ nginx"
ok "ساخته شد: $OUT"
note "دامنه: ${DOMAIN}   |   سوکت PHP: ${SOCKET}"

if [ "$SOCKET_FOUND" = "0" ]; then
    warn "سوکت php${PHP_MM}-fpm روی این سیستم پیدا نشد؛ مسیر حدسی نوشته شد."
    note "مسیر واقعی را با این دستور ببینید و در فایل اصلاح کنید:"
    note "  ls /run/php/*.sock"
fi

note ""
note "این فایل به‌صورت خودکار اعمال نمی‌شود. اگر خواستید فعالش کنید:"
note "  sudo cp '$OUT' /etc/nginx/sites-available/${DOMAIN}"
note "  sudo ln -s /etc/nginx/sites-available/${DOMAIN} /etc/nginx/sites-enabled/"
note "  sudo nginx -t        # حتماً قبل از ریلود، صحت کل کانفیگ را بررسی کنید"
note "  sudo systemctl reload nginx"
note ""
note "برای HTTPS بعد از فعال‌سازی:  sudo certbot --nginx -d ${DOMAIN}"
note "(PWA و سرویس‌ورکر فقط روی HTTPS فعال می‌شوند)"
