<?php

namespace App\Services\Health;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\SystemHealthNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * رساندنِ هشدارِ سلامت به آدم (R43).
 *
 * ─── سه مسیر، چون هیچ‌کدام به‌تنهایی کافی نیست ──────────────────────────────
 * ① **لاگِ کانالِ `alerts`** — همیشه، حتی وقتی دیتابیس مرده است. تنها مسیری
 *    که به هیچ سرویسِ دیگری وابسته نیست.
 * ② **اعلانِ درون‌برنامه‌ای به سوپرادمین** — دیده می‌شود، ولی فقط اگر کسی
 *    بتواند وارد شود؛ یعنی دقیقاً در بدترین حالت کار نمی‌کند.
 * ③ **وب‌هوک** — تنها مسیری که می‌تواند کسی را واقعاً خبر کند، و عمداً
 *    اختیاری است چون آدرسش را باید کارفرما بدهد.
 *
 * ⚠️ ایمیل و پیامک عمداً در این فهرست نیستند: ایمیل در این پروژه کنار گذاشته
 * شده و پیامک فقط برای کدِ یک‌بارمصرف است.
 */
class HealthAlerter
{
    /**
     * @param  array{status: string, checks: array<string, array<string, mixed>>}  $report
     * @return array<string, string> مشکلاتی که واقعاً اعلام شدند (خالی = چیزی اعلام نشد)
     */
    public function dispatch(array $report): array
    {
        $problems = $this->problems($report);

        if ($problems === []) {
            return [];
        }

        /*
         * ⚠️ خفه‌کن پیش از هر کاری.
         *
         * با اجرای هر پنج دقیقه، یک دیسکِ پر روزی ۲۸۸ هشدارِ یکسان می‌سازد.
         * صفی که پر از هشدارِ تکراری است همان‌قدر بی‌فایده است که هشدار
         * نداشته باشی — و بدتر، آدم‌ها یاد می‌گیرند نادیده‌اش بگیرند.
         *
         * کلید از **نامِ سنجه‌ها** ساخته می‌شود نه از متنِ شرح: «۸۶٪ اشغال» و
         * «۸۷٪ اشغال» دو متنِ متفاوت‌اند ولی یک مشکل، و با کلیدِ متنی هر
         * درصدِ تازه دوباره زنگ می‌زد.
         */
        $fresh = array_filter(
            $problems,
            fn (string $name): bool => $this->shouldAnnounce($name),
            ARRAY_FILTER_USE_KEY,
        );

        if ($fresh === []) {
            return [];
        }

        $this->toLog($report['status'], $fresh);
        $this->toSuperAdmins($report['status'], $fresh);
        $this->toWebhook($report['status'], $fresh);

        return $fresh;
    }

    /**
     * سنجه‌هایی که سالم نیستند.
     *
     * @param  array{status: string, checks: array<string, array<string, mixed>>}  $report
     * @return array<string, string>
     */
    public function problems(array $report): array
    {
        $problems = [];

        foreach ($report['checks'] as $name => $check) {
            if (($check['status'] ?? HealthReport::OK) !== HealthReport::OK) {
                $problems[$name] = (string) ($check['detail'] ?? 'نامشخص');
            }
        }

        return $problems;
    }

    /** آیا این سنجه در پنجره‌ی خفه‌کن تازه است؟ */
    private function shouldAnnounce(string $name): bool
    {
        $minutes = max(1, (int) config('health.alert.throttle_minutes', 60));

        // `add` اتمی است: اگر کلید باشد `false` می‌دهد و چیزی نمی‌نویسد.
        // با `has()` و بعد `put()`، دو اجرای هم‌زمان هر دو رد می‌شدند.
        return Cache::add("health:alerted:{$name}", true, $minutes * 60);
    }

    /**
     * @param  array<string, string>  $problems
     */
    private function toLog(string $status, array $problems): void
    {
        Log::channel('alerts')->critical('سلامتِ سامانه: '.$status, $problems);
    }

    /**
     * @param  array<string, string>  $problems
     */
    private function toSuperAdmins(string $status, array $problems): void
    {
        /*
         * ⚠️ خودِ اعلان‌دادن می‌تواند بشکند — و اگر بشکند، لاگِ بالا هم از
         * دست می‌رود چون استثنا کلِ دستور را می‌ترکاند. وقتی مشکل دقیقاً
         * «دیتابیس مرده است» باشد، این تقریباً حتمی است.
         */
        try {
            User::query()
                ->where('role', UserRole::SuperAdmin->value)
                ->each(function (User $admin) use ($status, $problems): void {
                    $admin->notify(new SystemHealthNotification($status, $problems));
                });
        } catch (Throwable $e) {
            Log::channel('alerts')->warning('اعلانِ سلامت به سوپرادمین نرسید: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, string>  $problems
     */
    private function toWebhook(string $status, array $problems): void
    {
        $url = config('health.alert.webhook');

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            /*
             * ⚠️ مهلتِ کوتاه، اجباری است. این کد از داخلِ زمان‌بند اجرا
             * می‌شود؛ یک وب‌هوکِ کندِ بی‌پاسخ می‌تواند اجرای بعدی را عقب
             * بیندازد و کلِ زمان‌بند را قفل کند — یعنی ابزارِ هشدار خودش
             * می‌شود منبعِ خرابیِ بعدی.
             */
            Http::timeout(5)->connectTimeout(3)->post($url, [
                'source' => config('app.name'),
                'status' => $status,
                'problems' => $problems,
                'at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::channel('alerts')->warning('وب‌هوکِ هشدار پاسخ نداد: '.$e->getMessage());
        }
    }
}
