<?php

namespace App\Support;

/**
 * مبدأهای بیرونی که ارزشِ اتصالِ زودهنگام دارند (R36).
 *
 * ─── چرا شرطی و نه فهرستِ ثابت ─────────────────────────────────────────────
 * ⚠️ `preconnect` به دامنه‌ای که هیچ درخواستی به آن نمی‌رود **مضر** است، نه
 * خنثی: یک دستِ کاملِ DNS + TCP + TLS باز می‌شود، سهمیه‌ی اتصال‌های هم‌زمانِ
 * مرورگر را می‌گیرد، و آن سهمیه از منابعِ واقعیِ صفحه کم می‌شود. یعنی
 * «بهینه‌سازی»‌ای که صفحه را کندتر می‌کند.
 *
 * این پروژه هیچ‌کدام از شناسه‌های تحلیلی را هنوز ندارد (GA4، GTM، Clarity،
 * Sentry همگی خالی‌اند)، پس امروز این فهرست **تهی** برمی‌گردد و هیچ تگی
 * تولید نمی‌شود. روزی که کارفرما شناسه‌ای وارد کند، همان روز خودکار اضافه
 * می‌شود.
 */
class ResourceHints
{
    /**
     * نگاشتِ «کدام شناسه ⟶ کدام مبدأ».
     *
     * @var array<string,array<int,string>>
     */
    private const ORIGINS = [
        'ga4MeasurementId' => ['https://www.googletagmanager.com'],
        'gtmContainerId' => ['https://www.googletagmanager.com'],
        'clarityProjectId' => ['https://www.clarity.ms'],
    ];

    /**
     * @return array<int,string>
     */
    public static function origins(): array
    {
        $config = Observability::clientConfig();
        $origins = [];

        foreach (self::ORIGINS as $key => $hosts) {
            if (! empty($config[$key])) {
                $origins = [...$origins, ...$hosts];
            }
        }

        /*
         * Sentry مبدأش داخلِ خودِ DSN است و ثابت نیست (هر سازمان زیردامنه‌ی
         * خودش را دارد)، پس از همان‌جا بیرون کشیده می‌شود.
         */
        $dsn = $config['sentryDsn'] ?? null;

        if (is_string($dsn) && $dsn !== '') {
            $host = parse_url($dsn, PHP_URL_HOST);
            $scheme = parse_url($dsn, PHP_URL_SCHEME);

            if (is_string($host) && is_string($scheme)) {
                $origins[] = $scheme.'://'.$host;
            }
        }

        return array_values(array_unique($origins));
    }
}
