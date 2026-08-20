<?php

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * سنجشِ واقعیِ سلامتِ سرویس (R43).
 *
 * ─── چرا این کلاس لازم شد ──────────────────────────────────────────────────
 * ⚠️ مسیرِ `/up` پیش از این همان مسیرِ پیش‌فرضِ لاراول بود و **فقط** می‌گفت
 * «فریم‌ورک بوت شد». اندازه‌گیری شد: با بردنِ اتصالِ دیتابیس روی مسیرِ
 * ناموجود، `select 1` خطا داد ولی `/up` همچنان **۲۰۰** برگرداند.
 *
 * این یعنی همان چیزی که در R42 دروازه‌ی بازگشتِ استقرار شد، دقیقاً در
 * بدترین حالتِ ممکن سبز می‌ماند: استقراری که دیتابیس را از دست داده،
 * بررسیِ سلامت را رد می‌کند و هرگز برنمی‌گردد.
 *
 * ─── دو درجه‌ی خرابی، و اینکه چرا تفکیکشان حیاتی است ────────────────────────
 * `down`     → این گره نمی‌تواند سرویس بدهد → HTTP 503
 * `degraded` → سرویس می‌دهد ولی چیزی خراب است → HTTP 200 + هشدار
 *
 * ⚠️ اگر «دیسک ۸۵٪ پر است» را هم ۵۰۳ حساب کنیم، فاجعه می‌سازیم: دیسک روی
 * **همه‌ی** گره‌ها هم‌زمان پر می‌شود، پس متعادل‌کننده‌ی بار همه را با هم از
 * مدار خارج می‌کند و یک هشدارِ ظرفیت تبدیل به قطعیِ کاملِ سامانه می‌شود —
 * قطعی‌ای که خودِ ابزارِ پایش ساخته است. فقط چیزی ۵۰۳ می‌گیرد که بدونِ آن
 * پاسخ‌دادن ممکن نیست.
 */
class HealthReport
{
    public const OK = 'ok';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    /** درصدِ اشغالِ دیسک که از آن به بعد هشدار داده می‌شود. */
    public const DISK_WARNING_PERCENT = 85;

    /**
     * درصدی که از آن به بعد گره واقعاً نمی‌تواند کار کند.
     *
     * نشست، صف و کشِ این پروژه همه روی دیتابیس‌اند و لاگ هم روی همین دیسک
     * نوشته می‌شود؛ با دیسکِ پر، نوشتنِ نشست شکست می‌خورد و کاربر حتی
     * نمی‌تواند وارد شود. پس این یکی واقعاً `down` است، نه هشدار.
     */
    public const DISK_CRITICAL_PERCENT = 97;

    /** تعدادِ Jobِ شکست‌خورده که از آن به بعد یعنی چیزی به‌طورِ سیستمی خراب است. */
    public const FAILED_JOBS_WARNING = 50;

    /** بیشترین فاصله‌ی قابل‌قبول از آخرین اجرای زمان‌بند (دقیقه). */
    public const SCHEDULER_STALE_MINUTES = 90;

    /** کلیدِ ضربانِ زمان‌بند — دستورِ `health:heartbeat` می‌نویسد، اینجا خوانده می‌شود. */
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    /**
     * @return array{status: string, checks: array<string, array<string, mixed>>, checked_at: string}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'storage' => $this->storage(),
            'disk' => $this->disk(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
        ];

        return [
            'status' => $this->worst($checks),
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * بدترین وضعیتِ میانِ همه‌ی سنجه‌ها — یک `down` کلِ گزارش را `down` می‌کند.
     *
     * @param  array<string, array<string, mixed>>  $checks
     */
    public function worst(array $checks): string
    {
        $states = array_column($checks, 'status');

        if (in_array(self::DOWN, $states, true)) {
            return self::DOWN;
        }

        return in_array(self::DEGRADED, $states, true) ? self::DEGRADED : self::OK;
    }

    /**
     * ⚠️ حیاتی: نشست، صف و کش همگی روی دیتابیس‌اند. بدونِ آن هیچ‌کس حتی
     * نمی‌تواند وارد شود، پس این سنجه واقعاً `down` است.
     *
     * @return array<string, mixed>
     */
    private function database(): array
    {
        return $this->timed(function (): array {
            DB::connection()->select('select 1');

            return ['status' => self::OK, 'detail' => 'اتصال برقرار است'];
        }, self::DOWN);
    }

    /**
     * رفت‌وبرگشتِ واقعی، نه فقط اتصال.
     *
     * یک کشِ **خواندنی ولی نانوشتنی** (دیسکِ پر، Redisِ در حالتِ فقط‌خواندن)
     * از بیرون سالم به نظر می‌رسد ولی هر قفل و هر throttle را می‌شکند. پس
     * هم می‌نویسیم هم می‌خوانیم.
     *
     * @return array<string, mixed>
     */
    private function cache(): array
    {
        return $this->timed(function (): array {
            $key = 'health:probe';
            $value = Str::random(8);

            Cache::put($key, $value, 30);

            if (Cache::get($key) !== $value) {
                return ['status' => self::DOWN, 'detail' => 'نوشتن انجام شد ولی خوانده نشد'];
            }

            Cache::forget($key);

            return ['status' => self::OK, 'detail' => 'رفت‌وبرگشت سالم است'];
        }, self::DOWN);
    }

    /**
     * دیسکِ فایل‌های کاربر: رسیدها، پیوست‌ها و بکاپ‌ها.
     *
     * نانوشتنی‌بودنش یعنی ساکن نمی‌تواند رسیدِ پرداخت را بفرستد و بکاپ هم
     * ساخته نمی‌شود — هر دو چیزهایی که دیرتر و بدتر فهمیده می‌شوند.
     *
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        return $this->timed(function (): array {
            $disk = Storage::disk('local');
            $path = 'health/probe-'.Str::random(8).'.txt';

            $disk->put($path, 'ok');
            $written = $disk->get($path) === 'ok';
            $disk->delete($path);

            return $written
                ? ['status' => self::OK, 'detail' => 'قابلِ نوشتن است']
                : ['status' => self::DOWN, 'detail' => 'نوشته شد ولی خوانده نشد'];
        }, self::DOWN);
    }

    /**
     * فضای دیسک — تا آستانه‌ی بحرانی فقط هشدار است، نه ۵۰۳ (دلیلش بالای کلاس).
     *
     * @return array<string, mixed>
     */
    private function disk(): array
    {
        return $this->timed(function (): array {
            $total = @disk_total_space(base_path());
            $free = @disk_free_space(base_path());

            if ($total === false || $free === false || $total <= 0.0) {
                return ['status' => self::OK, 'detail' => 'فضای دیسک از این محیط خوانده نمی‌شود'];
            }

            $usedPercent = (int) round((($total - $free) / $total) * 100);

            $status = match (true) {
                $usedPercent >= self::DISK_CRITICAL_PERCENT => self::DOWN,
                $usedPercent >= self::DISK_WARNING_PERCENT => self::DEGRADED,
                default => self::OK,
            };

            return [
                'status' => $status,
                'detail' => $usedPercent.'٪ اشغال',
                'used_percent' => $usedPercent,
                'free_mb' => (int) round($free / 1048576),
            ];
        }, self::DEGRADED);
    }

    /**
     * صف: انباشتِ کار و Jobهای شکست‌خورده.
     *
     * ⚠️ عمداً `degraded` است نه `down`. با کارگرِ مرده، کاربر همچنان
     * می‌تواند وارد شود و صفحه‌ها را ببیند؛ فقط قبض و بکاپ ساخته نمی‌شود.
     * ۵۰۳‌دادن اینجا یعنی سایتِ سالم را از مدار خارج کنیم.
     *
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        return $this->timed(function (): array {
            $failed = DB::table('failed_jobs')->count();
            $pending = DB::table('jobs')->count();

            return [
                'status' => $failed >= self::FAILED_JOBS_WARNING ? self::DEGRADED : self::OK,
                'detail' => $pending.' در صف، '.$failed.' شکست‌خورده',
                'pending' => $pending,
                'failed' => $failed,
            ];
        }, self::DEGRADED);
    }

    /**
     * ⚠️ ارزشمندترین سنجه‌ی این فهرست.
     *
     * اگر cron خاموش باشد هیچ چیزی نمی‌شکند و هیچ خطایی در لاگ نمی‌آید —
     * فقط بکاپ گرفته نمی‌شود، قبض یادآوری نمی‌شود، جدول‌ها هرس نمی‌شوند و
     * `failed_jobs` بی‌صدا بزرگ می‌شود. این خرابی می‌تواند **هفته‌ها** دیده
     * نشود، و درست وقتی فهمیده می‌شود که به آخرین بکاپ نیاز داری و نیست.
     *
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        return $this->timed(function (): array {
            $last = Cache::get(self::HEARTBEAT_KEY);

            if (! is_string($last)) {
                return [
                    'status' => self::DEGRADED,
                    'detail' => 'هنوز هیچ اجرایی ثبت نشده است',
                ];
            }

            $minutes = (int) now()->diffInMinutes($last, absolute: true);

            return [
                'status' => $minutes > self::SCHEDULER_STALE_MINUTES ? self::DEGRADED : self::OK,
                'detail' => 'آخرین اجرا '.$minutes.' دقیقه پیش',
                'minutes_ago' => $minutes,
            ];
        }, self::DEGRADED);
    }

    /**
     * اجرای یک سنجه با زمان‌گیری و مهارِ استثنا.
     *
     * ⚠️ هیچ سنجه‌ای حق ندارد خودِ بررسیِ سلامت را بترکاند. اگر یکی خطای
     * غیرمنتظره بدهد و اینجا گرفته نشود، کلِ endpoint پانصد می‌شود و آن‌وقت
     * پنج سنجه‌ی دیگر هم دیده نمی‌شوند — یعنی درست وقتی که بیشترین نیاز را
     * به گزارش داریم، گزارشی نداریم.
     *
     * @param  callable(): array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private function timed(callable $probe, string $onFailure): array
    {
        $started = microtime(true);

        try {
            $result = $probe();
        } catch (Throwable $e) {
            $result = [
                'status' => $onFailure,
                // ⚠️ پیام کوتاه و یک‌خطی می‌شود: استثناهای PDO رشته‌ی اتصال،
                // نامِ کاربر و گاهی مسیرِ سرور را در خود دارند
                'detail' => Str::limit(str_replace(["\n", "\r"], ' ', $e->getMessage()), 120),
            ];
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }
}
