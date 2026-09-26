<?php

namespace App\Providers;

use App\Models\Advertisement;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\Building;
use App\Models\ChargeRule;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Plan;
use App\Models\Unit;
use App\Observers\AuditObserver;
use App\Support\EnvironmentGuard;
use App\Support\Jalali;
use App\Support\Phone;
use App\Support\RequestId;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Active complex for the multi-tenant query scope. A null id means
        // "no scoping" (super-admin or console). Middleware sets it per request.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        /*
         * ⚠️ اولین کارِ بوت، و عمداً.
         *
         * اندازه‌گیری شد که با `APP_ENV=production` و `APP_DEBUG=true` یک
         * استثنای مهارنشده پاسخی ۱٫۴ مگابایتی می‌دهد که **مقدارِ واقعیِ
         * متغیرهای محیطی** (رمزِ دیتابیس، APP_KEY، کلیدِ درگاه) در آن است.
         * هر چیزی که پس از این خط اجرا شود می‌تواند استثنا بدهد، پس محافظ
         * باید پیش از همه‌ی آن‌ها بنشیند.
         */
        (new EnvironmentGuard)->enforce();

        Paginator::useTailwind();
        Date::macro('jalali', fn () => Jalali::date($this));

        $this->registerRateLimiters();
        $this->registerAuditObservers();
        $this->propagateRequestId();

        /*
         * `@csp` در Blade به `nonce="..."` تبدیل می‌شود.
         *
         * بدونِ این، هر اسکریپتِ درون‌خطی نیاز به `'unsafe-inline'` در CSP
         * داشت که عملاً محافظت در برابر XSS را از بین می‌برد. مقدار را
         * `SecurityHeaders` روی همین درخواست گذاشته است.
         */
        Blade::directive('csp', fn () => "<?php echo 'nonce=\"'.e(request()->attributes->get(\App\Http\Middleware\SecurityHeaders::NONCE_KEY, '')).'\"'; ?>");
    }

    /**
     * بردنِ شناسه‌ی درخواست تا داخلِ کارهای صف (R63).
     *
     * ─── 🔴 مسئله‌ای که حل می‌کند ──────────────────────────────────────────
     * ⚠️ سنگین‌ترین کارهای این سامانه در صف انجام می‌شوند: ساختِ بکاپ،
     * تولیدِ PDFِ دسته‌ی قبض‌ها، ارسالِ اعلان. یعنی وقتی کاربر می‌گوید
     * «قبض‌هایم ساخته نشد»، خطاهایش **در لاگِ کارگرِ صف** است، نه در لاگِ
     * درخواستِ او.
     *
     * بدونِ این دو قلاب، آن دو دسته لاگ هیچ ربطی به هم نداشتند و پیداکردنِ
     * «کدام کار مالِ کدام کلیک بود» فقط با حدسِ زمان ممکن بود.
     *
     * ─── چرا این دو قلاب و نه تغییرِ هر Job ────────────────────────────────
     * `createPayloadUsing` روی **هر** کاری که به صف می‌رود اجرا می‌شود —
     * از جمله کارهای خودِ فریم‌ورک. اگر به‌جایش هر Job را دستی عوض
     * می‌کردیم، اولین Jobِ تازه‌ای که کسی بنویسد از قلم می‌افتاد.
     *
     * ⚠️ و `Queue::before` شناسه را در کارگر **بازمی‌گرداند**، نه اینکه
     * فقط لاگش کند: از آن لحظه هر خطِ لاگِ آن کار همان شناسه را دارد.
     */
    private function propagateRequestId(): void
    {
        Queue::createPayloadUsing(fn () => [RequestId::PAYLOAD_KEY => RequestId::current()]);

        Queue::before(function (JobProcessing $event): void {
            $payload = $event->job->payload();

            RequestId::set($payload[RequestId::PAYLOAD_KEY] ?? null);
        });
    }

    /**
     * ثبتِ خودکارِ حذف‌ها در لاگ فعالیت.
     *
     * فهرست صریح است و نه «همه‌ی مدل‌ها»: جدول‌هایی مثل `otp_codes`،
     * `announcement_reads` و `trusted_devices` مدام پاک می‌شوند و لاگ‌کردنشان
     * فقط جدول را پر می‌کند و رویدادهای مهم را زیرِ نوفه دفن می‌کند.
     *
     * `User` هم عمداً اینجا نیست: حذفِ کاربر بسته به زمینه معنیِ متفاوتی دارد
     * (ساکن، مدیرِ مجتمع، عضوِ سامانه) و کنترلرها با نامِ دقیقِ همان زمینه
     * لاگ می‌کنند. یک `user.deleted`ِ عمومی آن تمایز را از بین می‌برد.
     */
    private function registerAuditObservers(): void
    {
        $auditable = [
            Advertisement::class,
            Announcement::class,
            Bill::class,
            Building::class,
            ChargeRule::class,
            Discount::class,
            Expense::class,
            Income::class,
            Plan::class,
            Unit::class,
        ];

        foreach ($auditable as $model) {
            $model::observe(AuditObserver::class);
        }
    }

    /**
     * محدودیت نرخ درخواست روی مسیرهای احراز هویت.
     *
     * بدون این‌ها، هم حدس‌زدن رمز و کد پیامکی بی‌هزینه بود و هم مهم‌تر:
     * هر کسی می‌توانست با درخواست انبوهِ کد، اعتبار پیامکِ سامانه را
     * (که پولی است) تمام کند.
     *
     * کلید هر محدودیت ترکیبی از IP و شماره تلفن است تا نه یک IP بتواند
     * روی شماره‌های مختلف مانور بدهد و نه چند IP روی یک شماره.
     */
    private function registerRateLimiters(): void
    {
        // ورود با رمز: ۵ تلاش در دقیقه برای هر شماره، و ۲۰ در دقیقه برای هر IP
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($this->phoneKey($request)),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        /*
         * درخواست کد پیامکی سخت‌گیرانه‌ترین است، چون هر درخواست یک پیامکِ
         * واقعی و هزینه‌دار می‌فرستد. OtpService خودش ۶۰ ثانیه فاصله‌ی
         * ارسال مجدد دارد ولی آن فقط per-phone است و جلوی حمله روی
         * شماره‌های متعدد را نمی‌گیرد.
         */
        /*
         * سقفِ روزانه هم لازم است، نه فقط پنجره‌ی ده‌دقیقه‌ای (R18).
         *
         * حساب کنید: ۳ پیامک در هر ۱۰ دقیقه یعنی **۴۳۲ پیامک در شبانه‌روز**
         * برای یک شماره. هر کدام پول واقعی است. پنجره‌ی کوتاه فقط «تندی» را
         * می‌گیرد، نه «مجموع» را — و حمله‌ی هزینه‌ای دقیقاً آهسته و مداوم است.
         *
         * کاربرِ واقعی در یک روز نهایتاً دو-سه بار کد می‌خواهد، پس ۱۰ سخاوتمند
         * است.
         */
        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by($this->phoneKey($request)),
            Limit::perMinutes(10, 15)->by($request->ip()),
            Limit::perDay(10)->by('otp-day:'.$this->phoneKey($request)),
            /*
             * سقفِ IP عمداً بالاست. اپراتورهای موبایل ایران CGNAT سنگین دارند و
             * هزاران کاربر پشتِ یک IP می‌نشینند؛ عددِ سخت‌گیرانه اینجا یعنی
             * قفل‌شدنِ کاربرانِ واقعی، نه مهاجم.
             */
            Limit::perDay(60)->by('otp-day-ip:'.$request->ip()),
        ]);

        // تایید کد: کد ۵ رقمی است، پس تعداد تلاش باید کم بماند
        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute(5)->by($this->phoneKey($request)),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // ثبت‌نام: جلوگیری از ساخت انبوه حساب
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perHour(5)->by($request->ip()),
            // ⚠️ فاز ۹: پنج در ساعت یعنی ۱۲۰ حساب در روز از یک IP
            Limit::perDay(20)->by('day:'.$request->ip()),
        ]);

        /*
         * بازگشت از درگاه پشت میدل‌ور `auth` نیست (توضیحش در routes/web.php)،
         * پس محدودیت نرخ جای آن را می‌گیرد تا نشود شناسه‌ی تراکنش‌ها را پیمود.
         * سقف دست‌ودل‌بازانه است چون یک پرداختِ سالم فقط یک بار برمی‌گردد و
         * رفرش‌های کاربر هم نباید به دیوار بخورند.
         */
        RateLimiter::for('gateway-callback', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip()),
        ]);

        /*
         * بازیابی کل سیستم. هر اجرای واقعی، کل داده را جایگزین می‌کند و یک
         * بکاپ ایمنی روی دیسک می‌گذارد، پس تکرار سریعش نه معنا دارد نه رایگان است.
         *
         * ولی اجرای آزمایشی (`dry_run`) فقط فایل را می‌خواند و چیزی را عوض
         * نمی‌کند. اگر همان سقف را داشته باشد، ادمینی که چند فایل را بررسی
         * می‌کند یا عبارت تایید را چند بار اشتباه تایپ می‌کند، درست وسط یک
         * بحران یک ساعت از بازیابی محروم می‌شود. پس فقط اجرای واقعی سقف دارد.
         */
        RateLimiter::for('system-restore', fn (Request $request) => $request->boolean('dry_run')
            ? Limit::none()
            : Limit::perHour(10)->by($request->user()?->id ?: $request->ip()));

        /*
        | مسیرهای گران.
        |
        | تا پیش از این فقط مسیرهای ورود سقف داشتند و بقیه آزاد بودند. هیچ‌کدام
        | از این‌ها «حمله» لازم ندارند تا سرور را زمین بزنند؛ یک اسکریپت ساده یا
        | حتی یک تب که گیر کرده کافی است.
        */

        // جستجو در هر فراخوانی شش کوئری LIKE '%…%' روی جدول‌های بزرگ می‌زند
        RateLimiter::for('search', fn (Request $request) => [
            Limit::perMinute(40)->by($request->user()?->id ?: $request->ip()),
        ]);

        // پیام‌رسان هر ۸ ثانیه poll می‌شود (≈۷ در دقیقه)؛ سقف جای تنفس دارد
        RateLimiter::for('messenger', fn (Request $request) => [
            Limit::perMinute(40)->by($request->user()?->id ?: $request->ip()),
        ]);

        /*
         * گزارشِ خطای مرورگر: یک صفحه‌ی خراب می‌تواند در حلقه‌ی رندر ده‌ها بار
         * خطا بدهد. سقف باید آن‌قدر باشد که خطاهای واقعی برسند، ولی جدول را
         * سیل نکند.
         */
        RateLimiter::for('client-errors', fn (Request $request) => [
            Limit::perMinute((int) config('observability.error_log.client_rate_limit', 20))
                ->by($request->user()?->id ?: $request->ip()),
        ]);

        /*
         * سنجه‌های کارایی: هر بازدید یک بسته می‌فرستد (هنگامِ ترکِ صفحه)، پس
         * سقفِ کم کافی است. اگر کسی بخواهد جدول را پر کند، همین‌جا می‌ایستد.
         */
        RateLimiter::for('web-vitals', fn (Request $request) => [
            Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()),
        ]);

        /*
         * کارزارِ پیامک (R27): سهمیه‌ی واقعی در دیتابیس است و ماهی یکی، پس
         * این محدودیت فقط ضربه‌گیرِ کلیکِ تکراری و باگِ رابط است — نه
         * جایگزینِ سهمیه.
         */
        RateLimiter::for('sms-campaign', fn (Request $request) => [
            Limit::perHour(5)->by($request->user()?->id ?: $request->ip()),
        ]);

        // هر بکاپ یک فایل کامل روی دیسک می‌سازد
        RateLimiter::for('backups', fn (Request $request) => [
            Limit::perHour(12)->by($request->user()?->id ?: $request->ip()),
        ]);

        // صدور قبض برای کل مجتمع، سنگین‌ترین محاسبه‌ی سامانه است
        RateLimiter::for('bills-generate', fn (Request $request) => [
            Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
        ]);

        // چت پشتیبانی: عمومی است، پس سقفش هم باید سخاوتمند باشد (یک گفت‌وگوی
        // واقعی چند پیام دارد) و هم جلوی اسکریپت را بگیرد.
        RateLimiter::for('support-chat', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // آپلود رسید: هم فضا مصرف می‌کند و هم صف بررسی مدیر را پر می‌کند
        RateLimiter::for('receipt-upload', fn (Request $request) => [
            Limit::perHour(20)->by($request->user()?->id ?: $request->ip()),
        ]);

        /*
         * داشبورد — گران‌ترین مسیرِ بی‌سقفِ سامانه (R66).
         *
         * ─── چرا این یکی جا مانده بود ──────────────────────────────────────
         * سقف‌های بالا برای مسیرهایی گذاشته شدند که **آشکارا** گران‌اند:
         * جست‌وجو، ساختِ بکاپ، صدورِ قبض. داشبورد در آن فهرست نبود چون
         * «فقط یک صفحه‌ی خواندنی» به‌نظر می‌رسید.
         *
         * ⚠️ اندازه‌گیریِ R65 خلافش را نشان داد: **۲۳ کوئری** در هر
         * فراخوانی — بیش از سه برابرِ سنگین‌ترین مسیرِ بعدی. یک صفحه، ده
         * تجمیعِ مالیِ متفاوت.
         *
         * 🔴 و هیچ سقفِ سراسری‌ای روی `/api` نیست. یعنی یک ساکنِ واردشده —
         * یا یک تبِ گیرکرده — می‌تواند همان مسیر را در حلقه بزند و به‌ازای
         * هر درخواست ۲۳ کوئری بسازد، بدونِ اینکه چیزی جلویش را بگیرد.
         *
         * ─── چرا شصت ─────────────────────────────────────────────────────
         * فرانت `staleTime` سی‌ثانیه‌ای دارد و `refetchOnWindowFocus` خاموش
         * است، پس مصرفِ واقعی حداکثر دو بار در دقیقه است. شصت یعنی سی برابر
         * فضا: کاربرِ واقعی هرگز نمی‌بیندش و حلقه‌ی خراب فوراً می‌ایستد.
         */
        RateLimiter::for('dashboard', fn (Request $request) => [
            Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()),
        ]);

        /*
        | سقفِ سراسری و جلوگیری از سوءاستفاده (مرحلهٔ ۵ · فاز ۹ · بندهای ۷۲ و ۷۳).
        |
        | 🔴 تا این فاز ۱۵۹ مسیرِ تغییردهنده هیچ سقفی نداشتند؛ از جمله ارسالِ
        | پیام، ثبتِ درخواستِ خدمات با پیوست، تغییرِ رمز، شروعِ پرداخت، پیامکِ
        | آزمایشی و همه‌ی APIهای مدیریتی. یک ساکنِ واردشده می‌توانست با یک حلقه
        | هزاران پیام برای همه‌ی همسایه‌ها بفرستد.
        |
        | ⚠️ دو لایه: سقفِ **سراسری** برای هر درخواستِ API (پیش‌فرضِ امن برای
        | مسیرِ تازه‌ای که کسی یادش نرود)، و سقفِ **ویژه** برای کارهایی که
        | هزینه یا آسیبشان به دیگران می‌رسد.
        */

        /*
         * سراسری. ۲۴۰ در دقیقه یعنی چهار در ثانیه: بارگذاریِ داشبورد حدودِ
         * ده درخواست است و polling چند در دقیقه، پس کاربرِ واقعی به آن
         * نمی‌رسد. نوشتن جدا و تنگ‌تر است چون هر نوشتن تراکنش و ممیزی دارد.
         *
         * ⚠️ کلیدها پیشوندِ جدا دارند: دو Limit با یک کلید در یک محدودکننده
         * یک شمارنده را می‌شمارند و سقفِ تنگ‌تر عملاً سقفِ هر دو می‌شد.
         */
        RateLimiter::for('api', function (Request $request): array {
            $who = $request->user()?->id ?: $request->ip();
            $limits = [Limit::perMinute(240)->by('all:'.$who)];

            if (! $request->isMethodSafe()) {
                $limits[] = Limit::perMinute(60)->by('write:'.$who);
            }

            return $limits;
        });

        // پیام به همه‌ی همسایه‌ها می‌رسد: ده در دقیقه برای گفت‌وگو کافی است، سیصد در روز برای هر آدمی
        RateLimiter::for('messenger-send', fn (Request $request) => [
            Limit::perMinute(10)->by('min:'.$request->user()?->id),
            Limit::perDay(300)->by('day:'.$request->user()?->id),
        ]);

        // درخواستِ خدمات و نظرش به صفِ کارِ مدیر می‌رود و پیوست دارد
        RateLimiter::for('service-requests', fn (Request $request) => [
            Limit::perMinute(5)->by('min:'.$request->user()?->id),
            Limit::perDay(50)->by('day:'.$request->user()?->id),
        ]);

        /*
         * تغییرِ رمز رمزِ فعلی را می‌خواهد. بدونِ سقف، کسی که نشستِ باز را
         * دزدیده می‌توانست رمزِ فعلی را حدس بزند و حساب را برای همیشه بگیرد.
         */
        RateLimiter::for('password-change', fn (Request $request) => [
            Limit::perMinutes(15, 5)->by($request->user()?->id ?: $request->ip()),
        ]);

        /*
         * تأییدِ دوباره‌ی رمز (مرحلهٔ ۵ · فاز ۱۰). بدونِ سقف، کسی که نشستِ
         * دزدیده دارد می‌توانست رمز را از همین راه حدس بزند — بی‌آنکه به سقفِ
         * صفحه‌ی ورود بخورد.
         */
        RateLimiter::for('reauth', fn (Request $request) => [
            Limit::perMinutes(15, 5)->by('reauth:'.($request->user()?->id ?: $request->ip())),
        ]);

        /*
         * شروعِ پرداخت هر بار یک درخواستِ بیرونی به بانک و یک `ref_id` تازه
         * است (همان نقصِ فاز ۴). کاربرِ واقعی در دقیقه یکی دو بار می‌زند.
         */
        RateLimiter::for('payment-start', fn (Request $request) => [
            Limit::perMinute(6)->by('min:'.($request->user()?->id ?: $request->ip())),
            Limit::perHour(30)->by('hour:'.($request->user()?->id ?: $request->ip())),
        ]);

        // پیامکِ آزمایشی پیامکِ واقعی است و پولِ واقعی
        RateLimiter::for('sms-test', fn (Request $request) => [
            Limit::perHour(10)->by($request->user()?->id ?: $request->ip()),
        ]);
    }

    /** کلید یکتا بر پایه‌ی شماره‌ی نرمال‌شده + IP. */
    private function phoneKey(Request $request): string
    {
        $phone = (string) $request->input('phone', '');

        return Phone::normalize($phone).'|'.$request->ip();
    }
}
