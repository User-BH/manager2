<?php

namespace App\Console\Commands;

use App\Support\EnvironmentGuard;
use Illuminate\Console\Command;

/**
 * اعتبارسنجیِ پیکربندیِ محیط پیش از استقرار (R44).
 *
 * ─── چرا این، و نه فقط سه فایلِ نمونه ──────────────────────────────────────
 * ⚠️ فایلِ نمونه چیزی را **اجبار نمی‌کند**. کسی آن را کپی می‌کند، یک مقدار
 * را عوض می‌کند، و شش ماه بعد کسی می‌فهمد `SESSION_SECURE_COOKIE` هرگز
 * روشن نشده بود. نمونه نقطه‌ی شروع است؛ چیزی که خطا را می‌گیرد این است.
 *
 * در `deploy.yml` پیش از `artisan up` صدا زده می‌شود، پس پیکربندیِ ناامن
 * جلوی بالا آمدنِ سایت را می‌گیرد و بازگشت راه می‌افتد.
 */
class CheckEnvironment extends Command
{
    protected $signature = 'env:check {--env= : محیطی که قاعده‌هایش سنجیده شود (پیش‌فرض: محیطِ فعلی)}';

    protected $description = 'سنجشِ اینکه تنظیماتِ این محیط امن و کامل است';

    public function handle(): int
    {
        $environment = (string) ($this->option('env') ?: app()->environment());

        $problems = array_merge(
            $this->universalProblems(),
            $environment === 'production' ? $this->productionProblems() : [],
        );

        if ($problems === []) {
            $this->info("پیکربندیِ محیطِ «{$environment}» سالم است.");

            return self::SUCCESS;
        }

        $this->error("پیکربندیِ محیطِ «{$environment}» مشکل دارد:");

        foreach ($problems as $problem) {
            $this->line('  • '.$problem);
        }

        return self::FAILURE;
    }

    /**
     * قاعده‌هایی که در هر محیطی باید برقرار باشند.
     *
     * @return array<int, string>
     */
    private function universalProblems(): array
    {
        $problems = [];

        /*
         * ⚠️ `APP_KEY` خالی یعنی هر کوکیِ نشست و هر مقدارِ رمزشده (از جمله
         * فایلِ بکاپ و تنظیماتِ درگاه) غیرقابلِ‌رمزگشایی می‌شود — یا بدتر،
         * با کلیدِ قابلِ‌حدس رمز می‌شود.
         */
        if (! is_string(config('app.key')) || config('app.key') === '') {
            $problems[] = 'APP_KEY خالی است. `php artisan key:generate` را اجرا کنید.';
        }

        return $problems;
    }

    /**
     * قاعده‌هایی که فقط در محصول معنی دارند.
     *
     * @return array<int, string>
     */
    private function productionProblems(): array
    {
        $problems = [];

        /*
         * ⚠️ اینجا `env()` خوانده **نمی‌شود** و این دو دلیلِ اندازه‌گیری‌شده
         * دارد.
         *
         * ① `env()` مقدارِ `putenv()` را نمی‌بیند. نسخه‌ی اولِ این دستور
         *    `env('APP_DEBUG')` را می‌خواند و تستش با هر مقداری که
         *    می‌گذاشتم شکست می‌خورد، چون مخزنِ Env لاراول مقدارِ بوت را
         *    نگه می‌دارد. یعنی این قاعده اصلاً قابلِ آزمودن نبود.
         * ② `config()` همان چیزی است که برنامه واقعاً استفاده می‌کند.
         *    خواندنِ `env` مستقیم یعنی سنجیدنِ چیزی که ممکن است با رفتارِ
         *    واقعی فرق داشته باشد.
         *
         * ⚠️ و به همین دلیل `EnvironmentGuard::violations()` هم خوانده
         * می‌شود: روی سرورِ محصول، محافظ در بوتِ همین دستور `app.debug` را
         * از قبل خاموش کرده است. اگر فقط به config نگاه می‌کردیم، این
         * قاعده دقیقاً در محیطی که برایش ساخته شده کور می‌شد.
         */
        if (config('app.debug') === true || EnvironmentGuard::violations() !== []) {
            $problems[] = 'APP_DEBUG در محصول روشن است؛ صفحه‌ی خطا رمزها را چاپ می‌کند.';
        }

        /*
         * ⚠️ بدونِ این، کوکیِ نشست روی HTTP هم فرستاده می‌شود. یک درخواستِ
         * ساده به `http://` (مثلاً از یک لینکِ قدیمی) کوکی را روی شبکه‌ی
         * رمزنشده می‌فرستد و ربودنِ نشست ممکن می‌شود.
         */
        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE روشن نیست؛ کوکیِ نشست روی HTTP هم فرستاده می‌شود.';
        }

        // آدرسِ محلی در محصول یعنی هر لینکِ ساخته‌شده (ایمیل، سایت‌مپ، og:url) غلط است
        if (str_contains((string) config('app.url'), 'localhost')) {
            $problems[] = 'APP_URL هنوز localhost است.';
        }

        // لاگِ سطحِ debug در محصول، بدنه‌ی درخواست‌ها را روی دیسک می‌ریزد
        if (config('logging.channels.single.level') === 'debug' && config('app.env') === 'production') {
            $problems[] = 'LOG_LEVEL روی debug است؛ در محصول باید دستِ‌کم `warning` باشد.';
        }

        /*
         * ⚠️ با `single` فایلِ لاگ هرگز نمی‌چرخد (R43). چون نشست و صف و کش
         * همه روی همین سرورند، پرشدنِ دیسک یعنی قطعیِ کامل.
         */
        $stack = config('logging.channels.stack.channels', []);

        if (in_array('single', is_array($stack) ? $stack : [], true)) {
            $problems[] = 'LOG_STACK شاملِ `single` است؛ لاگ نمی‌چرخد و دیسک پر می‌شود.';
        }

        return $problems;
    }
}
