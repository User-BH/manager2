<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * مقدارِ مؤثرِ تنظیماتِ پایش و تحلیل.
 *
 * ─── قاعده‌ی اولویت ────────────────────────────────────────────────────────
 *
 *     پنلِ ادمین  ⟶  .env  ⟶  خاموش
 *
 * دلیلِ این ترتیب: `.env` مالِ کسی است که به سرور دسترسی دارد و معمولاً
 * مقدارِ «رسمیِ» استقرار است؛ ولی وقتی صاحبِ پروژه عوض می‌شود یا کسی می‌خواهد
 * سریع حسابِ تحلیلیِ خودش را وصل کند، نباید منتظرِ SSH و دیپلوی بماند. پس
 * تنظیمِ پنل بر `.env` مقدم است و خالی‌گذاشتنش یعنی «برگرد به `.env`».
 *
 * ─── کدام مقدار محرمانه است؟ ───────────────────────────────────────────────
 * شناسه‌های GA4/GTM/Clarity و DSNِ فرانت **ذاتاً عمومی‌اند**؛ در سورسِ صفحه‌ی
 * هر بازدیدکننده دیده می‌شوند و رمزکردنشان امنیتِ واقعی اضافه نمی‌کند.
 * ولی `api_secret` و `auth_token` هرگز به مرورگر نمی‌روند و کلیدِ نوشتن‌اند؛
 * این‌ها رمزنگاری‌شده ذخیره می‌شوند و در پاسخِ API فقط ماسک‌شده برمی‌گردند.
 */
class Observability
{
    private const SETTING_KEY = 'observability';

    /** مقادیری که رمزنگاری‌شده ذخیره و ماسک‌شده خوانده می‌شوند. */
    private const SECRET_FIELDS = ['sentry_auth_token', 'ga4_api_secret'];

    /** همه‌ی کلیدهای قابلِ‌تنظیم از پنل. */
    public const FIELDS = [
        'sentry_dsn',
        'sentry_client_dsn',
        'sentry_environment',
        'sentry_traces_sample_rate',
        'sentry_auth_token',
        'ga4_measurement_id',
        'ga4_api_secret',
        'gtm_container_id',
        'clarity_project_id',
    ];

    /**
     * تنظیماتِ ذخیره‌شده در پنل (خام، با مقادیرِ محرمانه‌ی رمزگشایی‌شده).
     *
     * @return array<string, string>
     */
    private static function stored(): array
    {
        $raw = SystemSettings::getJson(self::SETTING_KEY, []);

        foreach (self::SECRET_FIELDS as $field) {
            if (! empty($raw[$field])) {
                try {
                    $raw[$field] = Crypt::decryptString($raw[$field]);
                } catch (DecryptException) {
                    /*
                     * کلیدِ برنامه عوض شده و مقدارِ قدیمی قابلِ بازگشایی نیست.
                     * به‌جای ترکاندنِ کلِ درخواست، آن یک مقدار را نادیده
                     * می‌گیریم تا ادمین بتواند از پنل دوباره واردش کند.
                     */
                    unset($raw[$field]);
                }
            }
        }

        return $raw;
    }

    /** مقدارِ مؤثرِ یک کلید: اول پنل، بعد `.env`. */
    private static function value(string $field, mixed $envValue): mixed
    {
        $stored = self::stored();

        // رشته‌ی خالی در پنل یعنی «تنظیم نکرده‌ام»، نه «خالی باشد»
        $panel = $stored[$field] ?? null;

        return ($panel === null || $panel === '') ? $envValue : $panel;
    }

    /**
     * پیکربندیِ کامل و مؤثر — منبعِ حقیقت برای بک‌اند.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'sentry' => [
                'dsn' => self::value('sentry_dsn', config('observability.sentry.dsn')),
                'client_dsn' => self::value('sentry_client_dsn', config('observability.sentry.client_dsn'))
                    ?: self::value('sentry_dsn', config('observability.sentry.dsn')),
                'environment' => self::value('sentry_environment', config('observability.sentry.environment')),
                'traces_sample_rate' => (float) self::value(
                    'sentry_traces_sample_rate',
                    config('observability.sentry.traces_sample_rate')
                ),
                'auth_token' => self::value('sentry_auth_token', config('observability.sentry.auth_token')),
            ],
            'ga4' => [
                'measurement_id' => self::value('ga4_measurement_id', config('observability.ga4.measurement_id')),
                'api_secret' => self::value('ga4_api_secret', config('observability.ga4.api_secret')),
            ],
            'gtm' => [
                'container_id' => self::value('gtm_container_id', config('observability.gtm.container_id')),
            ],
            'clarity' => [
                'project_id' => self::value('clarity_project_id', config('observability.clarity.project_id')),
            ],
        ];
    }

    /**
     * فقط چیزهایی که مرورگر باید بداند.
     *
     * این خروجی در `<head>` هر صفحه تزریق می‌شود، پس **هیچ مقدارِ محرمانه‌ای
     * نباید اینجا بیاید**. مقدارهای خالی هم حذف می‌شوند تا فرانت با یک شرطِ
     * ساده بفهمد سرویس خاموش است.
     *
     * @return array<string, mixed>
     */
    public static function clientConfig(): array
    {
        $config = self::config();
        $dsn = $config['sentry']['client_dsn'] ?: null;

        return array_filter([
            'sentryDsn' => $dsn,
            /*
             * محیط و نرخِ نمونه‌برداری فقط وقتی معنا دارند که DSN باشد.
             * بدونِ این شرط، `sentry_environment` که پیش‌فرضش از `APP_ENV`
             * می‌آید همیشه مقدار داشت و تگِ پیکربندی در هر صفحه چاپ می‌شد —
             * حتی وقتی هیچ سرویسی تنظیم نشده بود.
             */
            'sentryEnvironment' => $dsn ? ($config['sentry']['environment'] ?: null) : null,
            'sentryTracesSampleRate' => $dsn ? ($config['sentry']['traces_sample_rate'] ?: null) : null,
            'ga4MeasurementId' => $config['ga4']['measurement_id'] ?: null,
            'gtmContainerId' => $config['gtm']['container_id'] ?: null,
            'clarityProjectId' => $config['clarity']['project_id'] ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * مقادیر برای نمایش در پنل — محرمانه‌ها ماسک می‌شوند.
     *
     * همراهِ هر کلید می‌گوییم مقدار از کجا آمده (`panel` / `env` / `unset`) تا
     * ادمین بفهمد چرا چیزی که در `.env` گذاشته اثر ندارد.
     *
     * @return array<string, array{value: string|null, source: string, isSecret: bool}>
     */
    public static function forPanel(): array
    {
        $stored = self::stored();
        $envMap = [
            'sentry_dsn' => config('observability.sentry.dsn'),
            'sentry_client_dsn' => config('observability.sentry.client_dsn'),
            'sentry_environment' => config('observability.sentry.environment'),
            'sentry_traces_sample_rate' => config('observability.sentry.traces_sample_rate'),
            'sentry_auth_token' => config('observability.sentry.auth_token'),
            'ga4_measurement_id' => config('observability.ga4.measurement_id'),
            'ga4_api_secret' => config('observability.ga4.api_secret'),
            'gtm_container_id' => config('observability.gtm.container_id'),
            'clarity_project_id' => config('observability.clarity.project_id'),
        ];

        $result = [];

        foreach (self::FIELDS as $field) {
            $panelValue = $stored[$field] ?? null;
            $envValue = $envMap[$field] ?? null;

            $hasPanel = $panelValue !== null && $panelValue !== '';
            $effective = $hasPanel ? $panelValue : $envValue;
            $isSecret = in_array($field, self::SECRET_FIELDS, true);

            $result[$field] = [
                'value' => $isSecret ? self::mask($effective) : (string) ($effective ?? ''),
                'source' => $hasPanel ? 'panel' : (($envValue !== null && $envValue !== '') ? 'env' : 'unset'),
                'isSecret' => $isSecret,
            ];
        }

        return $result;
    }

    /**
     * ذخیره‌ی مقادیرِ پنل.
     *
     * قاعده‌ی مهم برای محرمانه‌ها: اگر مقدارِ ارسالی همان ماسک باشد یعنی کاربر
     * دستش نزده، پس مقدارِ قبلی باید بماند. بدونِ این، هر بار ذخیره‌ی فرم،
     * توکن را با رشته‌ی «••••» خراب می‌کرد.
     *
     * @param  array<string, string|null>  $values
     */
    public static function save(array $values): void
    {
        $stored = SystemSettings::getJson(self::SETTING_KEY, []);

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $incoming = $values[$field];
            $isSecret = in_array($field, self::SECRET_FIELDS, true);

            // خالی‌کردنِ عمدی: یعنی «برگرد به .env»
            if ($incoming === null || $incoming === '') {
                unset($stored[$field]);

                continue;
            }

            if ($isSecret) {
                // ماسکِ دست‌نخورده ⇒ مقدارِ قبلی حفظ شود
                if (self::looksLikeMask($incoming)) {
                    continue;
                }
                $stored[$field] = Crypt::encryptString($incoming);
            } else {
                $stored[$field] = $incoming;
            }
        }

        SystemSettings::set(self::SETTING_KEY, $stored);
    }

    /** وضعیتِ روشن/خاموشِ هر سرویس، برای کارت‌های پنل. */
    public static function status(): array
    {
        $config = self::config();
        $panelSources = self::forPanel();

        return [
            [
                'id' => 'sentry',
                'label' => 'Sentry (رهگیری خطا)',
                'enabled' => ! empty($config['sentry']['dsn']) || ! empty($config['sentry']['client_dsn']),
                'source' => $panelSources['sentry_dsn']['source'],
                'docsUrl' => 'https://sentry.io/settings/projects/',
            ],
            [
                'id' => 'ga4',
                'label' => 'Google Analytics 4',
                'enabled' => ! empty($config['ga4']['measurement_id']),
                'source' => $panelSources['ga4_measurement_id']['source'],
                'docsUrl' => 'https://analytics.google.com/',
            ],
            [
                'id' => 'gtm',
                'label' => 'Google Tag Manager',
                'enabled' => ! empty($config['gtm']['container_id']),
                'source' => $panelSources['gtm_container_id']['source'],
                'docsUrl' => 'https://tagmanager.google.com/',
            ],
            [
                'id' => 'clarity',
                'label' => 'Microsoft Clarity',
                'enabled' => ! empty($config['clarity']['project_id']),
                'source' => $panelSources['clarity_project_id']['source'],
                'docsUrl' => 'https://clarity.microsoft.com/',
            ],
        ];
    }

    /** `abc…xyz` → `••••••xyz` تا ادمین بفهمد مقداری هست، بی‌آنکه لو برود. */
    private static function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return str_repeat('•', 8).mb_substr($value, -4);
    }

    private static function looksLikeMask(string $value): bool
    {
        return str_starts_with($value, '••••');
    }
}
