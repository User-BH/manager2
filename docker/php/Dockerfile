# syntax=docker/dockerfile:1

# ==============================================================================
#  ساکنا — تصویرِ PHP-FPM (R41)
# ==============================================================================
#
#  ⚠️ این تصویر **چیزی از سرورِ فعلی را عوض نمی‌کند**.
#
#  قیدِ کارفرما صریح است: کانفیگِ nginxِ موجود، MySQL/phpMyAdminِ نصب‌شده، و
#  مسیرِ PHP 7.4 نباید دست بخورند. پس این یک محیطِ **کاملاً جدا** است که در
#  کانتینرِ خودش می‌دود، پورت‌های غیرِ استاندارد می‌گیرد، و دیتابیسِ خودش را
#  دارد. برای توسعه و برای **آماده‌سازیِ** استقرار است، نه جایگزینِ آنچه
#  همین حالا روی سرور کار می‌کند.
#
#  ─── چرا چندمرحله‌ای ─────────────────────────────────────────────────────
#  Composer و Node فقط برای **ساخت** لازم‌اند. اگر در تصویرِ نهایی بمانند،
#  حدودِ ۴۰۰ مگابایت ابزارِ ساخت روی هر سرورِ محصول می‌نشیند که هرگز اجرا
#  نمی‌شود — و هر کدام سطحِ حمله‌ی خودش را دارد.
# ==============================================================================

ARG PHP_VERSION=8.4


# ──────────────────────────────────────────────────────────────────────────────
#  مرحله ۱ — وابستگی‌های PHP
# ──────────────────────────────────────────────────────────────────────────────
FROM composer:2 AS vendor

WORKDIR /app

# فقط همین دو فایل کپی می‌شوند تا لایه‌ی وابستگی‌ها با هر تغییرِ کدِ برنامه
# باطل نشود؛ نصبِ composer از کش می‌آید مگر خودِ وابستگی‌ها عوض شوند.
COPY composer.json composer.lock ./

# ⚠️ `--no-scripts` عمدی است: اسکریپت‌های لاراول به `artisan` نیاز دارند که
# هنوز کپی نشده. پس از کپیِ کد، در مرحله‌ی نهایی صدا زده می‌شوند.
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction


# ──────────────────────────────────────────────────────────────────────────────
#  مرحله ۲ — ساختِ دارایی‌های فرانت
# ──────────────────────────────────────────────────────────────────────────────
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./

# `npm ci` و نه `npm install`: دقیقاً همان نسخه‌های قفل‌شده را نصب می‌کند، پس
# بیلدِ امروز و شش ماه دیگر یکی است.
RUN npm ci

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
COPY public ./public

RUN npm run build


# ──────────────────────────────────────────────────────────────────────────────
#  مرحله ۳ — تصویرِ نهایی
# ──────────────────────────────────────────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS runtime

# ─── افزونه‌ها ───────────────────────────────────────────────────────────
#
# فهرست از `composer.lock` بیرون کشیده شده، نه از حدس. آن‌هایی که در تصویرِ
# پایه از قبل هستند (ctype، dom، json، mbstring، …) تکرار نشده‌اند.
#
# ⚠️ `gd` باید با freetype/jpeg/webp ساخته شود: mPDF فونتِ فارسی را با
# freetype رندر می‌کند و `pwa:splash` تصاویر را با همان می‌سازد. `gd`ِ
# ساده هر دو را بی‌صدا خراب می‌کند.
RUN apk add --no-cache \
        freetype libjpeg-turbo libpng libwebp libzip icu-libs oniguruma \
        fcgi \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS freetype-dev libjpeg-turbo-dev libpng-dev libwebp-dev \
        libzip-dev icu-dev oniguruma-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd zip intl bcmath pdo_mysql opcache exif pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# supervisor صف و زمان‌بند را زنده نگه می‌دارد؛ nginx در کانتینرِ خودش است
RUN apk add --no-cache supervisor

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-sakena.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/98-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-sakena.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf

WORKDIR /var/www/html

# کد و وابستگی‌ها
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# ⚠️ حالا که `artisan` هست، autoloaderِ بهینه ساخته و اسکریپت‌ها اجرا می‌شوند
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# ─── سلامتِ کانتینر ──────────────────────────────────────────────────────
#
# ⚠️ `cgi-fcgi` و نه `curl`: این کانتینر وب‌سرور ندارد و روی ۹۰۰۰ فقط
# FastCGI حرف می‌زند. یک `curl http://localhost` اینجا همیشه شکست می‌خورد و
# کانتینرِ سالم را ناسالم اعلام می‌کند.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD REQUEST_METHOD=GET SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping \
        cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
