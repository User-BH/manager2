<?php

namespace App\Logging;

use App\Support\SystemSettings;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * پاک‌کردنِ رازها از هر خطِ لاگ، پیش از نوشتنش (R55).
 *
 * ─── نقصی که اندازه‌گیری پیدا کرد ──────────────────────────────────────────
 * ⚠️ `KavenegarSms` کلیدِ API را **داخلِ مسیرِ URL** می‌گذارد — این طراحیِ
 * خودِ سرویس است و راهی جز آن نیست:
 *
 *     https://api.kavenegar.com/v1/{apikey}/sms/send.json
 *
 * و درست پایینش:
 *
 *     Log::error('[SMS:kavenegar] exception: '.$e->getMessage());
 *
 * پیامِ استثنای HTTP در Guzzle **آدرسِ کامل** را در خود دارد. یعنی اولین
 * قطعیِ شبکه، کلیدِ زنده‌ی پیامک را در `storage/logs` می‌نوشت — همان‌جایی
 * که معمولاً مجوزش بازتر از دیتابیس است و در بکاپ‌ها هم می‌رود.
 *
 * ─── چرا پردازنده‌ی سراسری و نه رفعِ همان یک خط ────────────────────────────
 * ⚠️ رفعِ آن یک خط، امروز درست بود و فردا نه. هر `Log::` تازه‌ای که کسی
 * کنارِ یک اعتبارنامه بنویسد، همان نقص را برمی‌گرداند و در مرورِ کد دیده
 * نمی‌شود.
 *
 * این پردازنده روی **همه‌ی** خطوط اجرا می‌شود: پیام، و مقادیرِ داخلِ
 * `context` به‌صورتِ بازگشتی.
 *
 * ─── دو قاعده‌ی ایمنی ──────────────────────────────────────────────────────
 * ① هرگز استثنا پرتاب نمی‌کند. لاگ‌نویسی مسیرِ خطاست؛ اگر خودش بشکند،
 *   خطای اصلی برای همیشه گم می‌شود.
 * ② رازها **یک بار** خوانده و در همین پردازه نگه داشته می‌شوند. خواندنشان
 *   از کشِ `SystemSettings` است، ولی حتی همان هم نباید به ازای هر خطِ لاگ
 *   تکرار شود.
 */
class SecretScrubber implements ProcessorInterface
{
    private const MASK = '[راز پنهان شد]';

    /**
     * کلیدهایی که مقدارشان در `context` هرگز نباید نوشته شود.
     *
     * ⚠️ نامِ کلید سنجیده می‌شود نه مقدارش، چون مقدار می‌تواند هر چیزی
     * باشد. این لایه‌ی دوم است: لایه‌ی اول، رازهای شناخته‌شده‌اند.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'password', 'apikey', 'api_key', 'secret', 'token', 'authorization',
        'gateway_config', 'sms_config', 'private_key', 'credentials',
    ];

    /** @var array<int, string>|null */
    private static ?array $secrets = null;

    /** ⚠️ محافظِ بازگشت: خواندنِ رازها خودش نباید لاگ‌نویسیِ تازه بسازد. */
    private static bool $loading = false;

    public function __invoke(LogRecord $record): LogRecord
    {
        $secrets = self::secrets();

        return $record->with(
            message: self::mask($record->message, $secrets),
            context: self::maskArray($record->context, $secrets),
        );
    }

    /** برای تست: حافظه‌ی رازها را خالی می‌کند. */
    public static function flush(): void
    {
        self::$secrets = null;
    }

    /**
     * پاک‌سازیِ یک رشته، بیرون از مسیرِ لاگ (R64).
     *
     * 🔴 چرا لازم شد: این پردازنده فقط `storage/logs` را محافظت می‌کرد، ولی
     * هشدارِ R43 همان متن‌ها را به **وب‌هوک** هم می‌فرستد — یعنی به یک
     * سرویسِ بیرونی. متنِ خطایی که در فایلِ لاگِ خودمان پاک می‌شد، از آن
     * مسیر دست‌نخورده بیرون می‌رفت.
     *
     * ⚠️ مثلِ `__invoke` هرگز استثنا نمی‌دهد: `secrets()` خودش هر خطایی را
     * می‌بلعد و در بدترین حالت فهرستِ خالی می‌دهد.
     */
    public static function clean(string $text): string
    {
        return self::mask($text, self::secrets());
    }

    /**
     * رازهای شناخته‌شده‌ی این نصب.
     *
     * @return array<int, string>
     */
    private static function secrets(): array
    {
        if (self::$secrets !== null) {
            return self::$secrets;
        }

        if (self::$loading) {
            return [];
        }

        self::$loading = true;

        try {
            $config = SystemSettings::getJson('sms_config', []);

            $values = [];

            foreach ($config as $value) {
                /*
                 * ⚠️ مقدارهای خیلی کوتاه کنار گذاشته می‌شوند.
                 *
                 * نامِ فرستنده‌ی پیامک می‌تواند «۳۰۰۰» باشد؛ پنهان‌کردنِ
                 * چنین رشته‌ای یعنی هر عددِ مشابهی در لاگ هم پاک شود و
                 * لاگ بی‌فایده گردد.
                 */
                if (is_string($value) && mb_strlen($value) >= 8) {
                    $values[] = $value;
                }
            }

            self::$secrets = array_values(array_unique($values));
        } catch (Throwable) {
            // دیتابیس در دسترس نیست؛ لاگ نباید به‌خاطرش بشکند
            self::$secrets = [];
        } finally {
            self::$loading = false;
        }

        return self::$secrets;
    }

    /** @param  array<int, string>  $secrets */
    private static function mask(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        return $text;
    }

    /**
     * @param  array<mixed>  $data
     * @param  array<int, string>  $secrets
     * @return array<mixed>
     */
    private static function maskArray(array $data, array $secrets): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::MASK;

                continue;
            }

            $out[$key] = match (true) {
                is_array($value) => self::maskArray($value, $secrets),
                is_string($value) => self::mask($value, $secrets),
                default => $value,
            };
        }

        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
