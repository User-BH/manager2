<?php

namespace App\Services;

use App\Logging\SecretScrubber;
use App\Models\ErrorEvent;
use App\Support\RequestId;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * ثبتِ خطا در جدولِ `error_events` برای نمایش در پنلِ ادمین.
 *
 * ─── قاعده‌ی اول: این سرویس هرگز نباید خودش خطا بدهد ───────────────────────
 * اگر ثبتِ خطا بترکد، درخواستی که فقط یک خطای جزئی داشت به کرشِ کامل تبدیل
 * می‌شود و بدتر از آن، حلقه‌ی «خطا هنگام ثبتِ خطا» راه می‌افتد. پس همه‌جا
 * `try/catch` دارد و در بدترین حالت فقط در لاگِ فایل می‌نویسد.
 */
class ErrorRecorder
{
    /** استثناهایی که خطای برنامه نیستند و ثبتشان فقط پنل را شلوغ می‌کند. */
    private const IGNORED = [
        AuthenticationException::class,
        AuthorizationException::class,
        ValidationException::class,
        TokenMismatchException::class,
        ModelNotFoundException::class,
        NotFoundHttpException::class,
        MethodNotAllowedHttpException::class,
        ThrottleRequestsException::class,
    ];

    /**
     * فرمانِ artisan ِ در حالِ اجرا. در کنسول `request()` درخواستِ ساختگیِ `GET http://localhost` است؛ بی این، خطای
     * یک فرمان در پنل مثلِ خطای یک صفحه‌ی وب دیده می‌شد (۱۳/۹-ج).
     */
    private static ?string $command = null;

    public static function commandStarted(?string $command): void
    {
        self::$command = $command;
    }

    public static function commandFinished(): void
    {
        self::$command = null;
    }

    /** @return array{0: string, 1: string} جای خطا: فرمانِ artisan، یا درخواستِ HTTP ِ واقعی */
    public static function origin(): array
    {
        return self::$command !== null
            ? ['artisan '.self::$command, 'CLI']
            : [request()->fullUrl(), request()->method()];
    }

    public static function fromException(Throwable $e, ?string $url = null, ?string $method = null): void
    {
        if (! config('observability.error_log.enabled')) {
            return;
        }

        foreach (self::IGNORED as $ignored) {
            if ($e instanceof $ignored) {
                return;
            }
        }

        self::record([
            'source' => 'server',
            'type' => $e::class,
            'message' => $e->getMessage() ?: '(بدون پیام)',
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            // فقط چند فریمِ اول؛ استکِ کامل جدول را بی‌دلیل بزرگ می‌کند
            'stack' => self::trimStack($e->getTraceAsString()),
            'url' => $url,
            'method' => $method,
            'status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500,
        ]);
    }

    /**
     * خطای گزارش‌شده از مرورگر (Error Boundary فرانت).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromClient(array $payload): void
    {
        if (! config('observability.error_log.enabled')) {
            return;
        }

        self::record([
            'source' => 'client',
            'type' => (string) ($payload['type'] ?? 'Error'),
            'message' => (string) ($payload['message'] ?? '(بدون پیام)'),
            'file' => null,
            'line' => null,
            'stack' => self::trimStack((string) ($payload['stack'] ?? '')),
            'url' => (string) ($payload['url'] ?? ''),
            'method' => 'GET',
            'status' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function record(array $data): void
    {
        try {
            /*
             * 🔴 پیش از ثبت، دادهٔ حساس برداشته می‌شود (مرحلهٔ ۵ · فاز ۷).
             *
             * این جدول در پنلِ سوپرادمین دیده می‌شود و پیش از این سه راهِ نشت
             * داشت: متنِ استثنای دیتابیس (با مقدارهای خودِ کوئری — موبایل،
             * هشِ رمز)، استک، و **آدرسِ کامل با کوئری** (`fullUrl()` سمتِ سرور،
             * `location.href` سمتِ مرورگر).
             *
             * ⚠️ کوئری کامل حذف می‌شود، نه فقط پارامترهای «حساسِ» شناخته‌شده:
             * فهرستِ نام‌ها همیشه یک قدم عقب است، و برای یافتنِ یک خطا مسیر
             * بس است. پیش از اثرِ انگشت، تا یک خطا با دو شماره‌ی موبایل دو
             * ردیف نشود.
             */
            $data['message'] = SecretScrubber::clean((string) $data['message']);
            $data['stack'] = SecretScrubber::clean((string) $data['stack']);
            $data['url'] = self::withoutQuery($data['url'] ?? null);

            /*
             * اثرِ انگشت از نوع + پیام + محل ساخته می‌شود، نه از استکِ کامل:
             * استک بین دو رخدادِ یکسان هم فرق می‌کند (شماره‌ی خط در فریم‌های
             * میانی) و آن‌وقت هر رخداد ردیفِ جدا می‌گرفت.
             */
            $fingerprint = hash('sha256', implode('|', [
                $data['source'],
                $data['type'],
                mb_substr($data['message'], 0, 300),
                $data['file'] ?? '',
                $data['line'] ?? '',
            ]));

            $now = now();

            $existing = ErrorEvent::where('fingerprint', $fingerprint)->first();

            if ($existing) {
                /*
                 * با کوئری‌بیلدر و نه `$model->save()`.
                 *
                 * `forceFill(['occurrences' => DB::raw(...)])->save()` بی‌صدا
                 * بی‌اثر بود: Eloquent مقدارِ Expression را با کستِ `integer`
                 * مدل قاطی می‌کرد و در عمل هیچ به‌روزرسانی‌ای نمی‌رفت. یک
                 * UPDATEِ ساده هم درست کار می‌کند و هم اتمیک است، پس دو
                 * درخواستِ هم‌زمان شمارنده را خراب نمی‌کنند.
                 */
                ErrorEvent::whereKey($existing->getKey())->update([
                    'occurrences' => DB::raw('occurrences + 1'),
                    'last_seen_at' => $now,
                    /*
                     * ⚠️ شناسه‌ی **آخرین** رخداد، نه اولین (R63).
                     *
                     * ردیف‌ها با اثرِ انگشت ادغام می‌شوند، پس یک ردیف
                     * می‌تواند صدها رخداد باشد. کاربری که همین حالا زنگ
                     * می‌زند شناسه‌ی همین لحظه را در دست دارد؛ شناسه‌ی سه
                     * هفته پیش کمکی نمی‌کند و لاگش هم دیگر روی دیسک نیست.
                     */
                    'request_id' => RequestId::current(),
                    // خطایی که دوباره رخ داده، دیگر «بررسی‌شده» نیست
                    'is_resolved' => false,
                    'updated_at' => $now,
                ]);

                return;
            }

            ErrorEvent::create([
                ...$data,
                'fingerprint' => $fingerprint,
                'request_id' => RequestId::current(),
                'message' => mb_substr($data['message'], 0, 2000),
                'user_id' => Auth::id(),
                'complex_id' => app(TenantContext::class)->get(),
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } catch (Throwable $recordingError) {
            // آخرین سنگر: لاگِ فایل. نباید هیچ‌وقت به بالا پرتاب شود.
            Log::warning('ثبت رویداد خطا ناموفق بود', ['reason' => $recordingError->getMessage()]);
        }
    }

    private static function withoutQuery(?string $url): ?string
    {
        return $url === null ? null : (string) preg_replace('/[?#].*$/s', '', $url);
    }

    private static function trimStack(string $stack): string
    {
        $lines = array_slice(explode("\n", $stack), 0, 15);

        return mb_substr(implode("\n", $lines), 0, 5000);
    }
}
