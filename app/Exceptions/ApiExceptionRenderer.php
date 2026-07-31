<?php

namespace App\Exceptions;

use App\Support\Jalali;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * شکلِ یکسانِ همه‌ی پاسخ‌های خطا.
 *
 * ─── قرارداد ───────────────────────────────────────────────────────────────
 *
 *     {
 *       "success": false,
 *       "message": "پیامِ فارسیِ قابلِ نمایش",
 *       "code":    "payment.pending_exists",   // اختیاری، ماشین‌خوان
 *       "errors":  { "phone": ["..."] }        // فقط برای خطای اعتبارسنجی
 *     }
 *
 * ─── چرا پاسخ‌های موفق پوششِ `{success, data}` نگرفتند ──────────────────────
 * برنامه‌ی کار پوششِ یکسان برای همه‌ی پاسخ‌ها را می‌خواست. برای **خطاها**
 * انجام شد چون آنجا واقعاً ناهمگونی داشتیم (هر استثنا شکلِ خودش را داشت).
 * ولی پوشاندنِ پاسخ‌های موفق یعنی تغییرِ شکلِ خروجیِ ۴۱ کنترلر، بازنویسیِ همه‌ی
 * صفحه‌های فرانت و ۲۹۴ تست — با سودی که خودِ کدِ وضعیتِ HTTP از قبل می‌دهد
 * (‏۲۰۰ یعنی موفق؛ فیلدِ `success: true` تکرارِ همان است).
 *
 * پس اینجا آگاهانه فقط خطاها یکدست شدند. اگر پوششِ کامل را لازم دارید، کارِ
 * جدایی است با هزینه‌ی روشن، نه چیزی که بی‌صدا داخلِ این مرحله جا شود.
 *
 * ─── نکته‌ی امنیتی ─────────────────────────────────────────────────────────
 * در محصول، پیامِ خطای ۵۰۰ هرگز به کاربر نشان داده نمی‌شود؛ متنِ خام می‌تواند
 * مسیرِ فایل‌ها، نامِ جدول‌ها یا حتی مقادیرِ حساس را لو بدهد.
 */
class ApiExceptionRenderer
{
    /**
     * اگر این استثنا باید پاسخِ JSON بدهد، آن را بساز؛ وگرنه `null` تا لاراول
     * رفتارِ عادیِ خودش (صفحه‌ی HTML) را داشته باشد.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof DomainException => self::payload(
                $e->getMessage(),
                $e->status,
                $e->errorCode,
                $e->errors,
            ),

            $e instanceof ValidationException => self::payload(
                $e->getMessage(),
                422,
                'validation_failed',
                $e->errors(),
            ),

            $e instanceof AuthenticationException => self::payload(
                'برای این کار باید وارد شوید.',
                401,
                'unauthenticated',
            ),

            $e instanceof AuthorizationException => self::payload(
                // پیامِ خودِ Policy اگر داده شده باشد، وگرنه متنِ عمومی
                $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.'
                    ? $e->getMessage()
                    : 'شما به این بخش دسترسی ندارید.',
                403,
                'forbidden',
            ),

            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::payload(
                'موردِ درخواستی پیدا نشد.',
                404,
                'not_found',
            ),

            /*
             * محدودیتِ نرخ. پیامِ پیش‌فرضِ لاراول انگلیسی است
             * («Too Many Attempts.») و کاربرِ فارسی‌زبان از آن چیزی نمی‌فهمد،
             * پس زمانِ انتظار را هم به فارسی می‌گوییم.
             *
             * `retryAfter` جدا از `code` می‌آید چون عددی است و فرانت با آن
             * شمارشِ معکوس نشان می‌دهد.
             */
            $e instanceof ThrottleRequestsException => self::throttled($e),

            /*
             * `abort(403, '...')`های ساده (مثل میدل‌ورِ نقش) هم باید همان
             * کدِ ماشین‌خوانی را بگیرند که `AuthorizationException` می‌گیرد؛
             * وگرنه فرانت برای دو ۴۰۳ِ یکسان دو رفتار می‌دید — بسته به اینکه
             * کدام لایه جلویش را گرفته، که جزئیاتِ درونیِ ما است نه چیزی که
             * مصرف‌کننده باید بداند.
             */
            $e instanceof HttpExceptionInterface => self::payload(
                $e->getMessage() ?: self::defaultMessageFor($e->getStatusCode()),
                $e->getStatusCode(),
                self::codeFor($e->getStatusCode()),
            ),

            default => self::payload(
                // جزئیاتِ فنی فقط در حالتِ توسعه
                config('app.debug')
                    ? $e->getMessage()
                    : 'خطای غیرمنتظره‌ای رخ داد. لطفاً دوباره تلاش کنید.',
                500,
                'server_error',
            ),
        };
    }

    /**
     * @param  array<string, string[]>  $errors
     */
    private static function payload(
        string $message,
        int $status,
        ?string $code = null,
        array $errors = [],
    ): JsonResponse {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'errors' => $errors ?: null,
        ], fn ($value) => $value !== null), $status);
    }

    /** پیامِ فارسیِ محدودیتِ نرخ، به‌همراه هدرهای اصلیِ لاراول. */
    private static function throttled(ThrottleRequestsException $e): JsonResponse
    {
        $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

        $wait = $seconds > 90
            ? Jalali::digits((int) ceil($seconds / 60)).' دقیقه'
            : Jalali::digits($seconds).' ثانیه';

        return response()->json([
            'success' => false,
            'message' => "تعداد تلاش‌ها بیش از حد مجاز است. لطفاً {$wait} دیگر دوباره تلاش کنید.",
            'code' => 'too_many_requests',
            // فرانت با این عدد شمارشِ معکوس نشان می‌دهد
            'retryAfter' => $seconds,
        ], 429, $e->getHeaders());
    }

    /** کدِ ماشین‌خوانِ متناظرِ وضعیت‌های رایج. */
    private static function codeFor(int $status): ?string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            419 => 'csrf_token_mismatch',
            default => null,
        };
    }

    private static function defaultMessageFor(int $status): string
    {
        return match ($status) {
            400 => 'درخواست نامعتبر است.',
            401 => 'برای این کار باید وارد شوید.',
            403 => 'شما به این بخش دسترسی ندارید.',
            404 => 'موردِ درخواستی پیدا نشد.',
            409 => 'این عملیات با وضعیتِ فعلی سازگار نیست.',
            419 => 'نشست شما منقضی شده است. صفحه را تازه کنید.',
            default => 'خطایی رخ داد.',
        };
    }
}
