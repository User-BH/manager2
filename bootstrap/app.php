<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AuthenticateTrustedDevice;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\LockInitialAccount;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentComplex;
use App\Services\Auth\TrustedDeviceService;
use App\Services\ErrorRecorder;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // مسیرهای API هم روی گروه «web» سوار می‌شوند (در routes/api.php)، چون
        // اپلیکیشن React از همین دامنه سرو می‌شود و احراز هویتش با نشست و
        // کوکی است، نه توکن bearer. این‌طور نیازی به Sanctum و مدیریت توکن
        // در سمت کلاینت نیست و حفاظت CSRF هم فعال می‌ماند.
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            /*
            | نسخه‌بندیِ API (R10).
            |
            | همان فایلِ مسیرها دو بار ثبت می‌شود:
            |
            |   /api/v1/...   ← نسخه‌ی رسمی؛ فرانت از این استفاده می‌کند
            |   /api/...      ← نامِ مستعارِ سازگاری
            |
            | چرا هر دو؟ چون نسخه‌بندی برای این است که روزی بشود `v2` را کنارِ
            | `v1` بالا آورد بی‌آنکه مصرف‌کننده‌های قدیمی بشکنند. اگر همین حالا
            | مسیرِ بدونِ نسخه را حذف می‌کردیم، هر بوکمارک، هر اسکریپت و هر
            | نسخه‌ی کش‌شده‌ی فرانت که هنوز در مرورگرِ کاربر باز است می‌شکست —
            | دقیقاً همان چیزی که نسخه‌بندی قرار بود جلویش را بگیرد.
            |
            | نام‌های مسیر فقط یک بار (روی v1) ثبت می‌شوند تا `route()` مبهم
            | نشود؛ نامِ مستعار بی‌نام می‌ماند.
            */
            Route::middleware('web')
                ->prefix('api/v1')
                ->name('api.')
                ->group(__DIR__.'/../routes/api.php');

            Route::middleware('web')
                ->prefix('api')
                ->group(__DIR__.'/../routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
        | پشت CDN (ArvanCloud، Cloudflare و…) درخواست با HTTP به سرور می‌رسد
        | حتی وقتی کاربر HTTPS باز کرده. بدون اعتماد به پراکسی، لاراول درخواست
        | را ناامن می‌بیند و آدرس‌ها را با http:// می‌سازد؛ نتیجه‌اش mixed
        | content و بلاک‌شدن CSS/JS روی صفحه‌ی HTTPS است. IP واقعی کاربر هم
        | به‌جای IP کاربر، IP سرورهای CDN ثبت می‌شود.
        |
        | مقدار TRUSTED_PROXIES در .env تعیین می‌شود:
        |   TRUSTED_PROXIES=*                 → همه (فقط وقتی مبدأ مستقیم در
        |                                       دسترس نیست یا فایروال دارد)
        |   TRUSTED_PROXIES=1.2.3.4,5.6.7.8   → فقط همین IPها
        |   خالی                              → هیچ پراکسی‌ای معتبر نیست
        */
        $proxies = trim((string) env('TRUSTED_PROXIES'));

        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_filter(array_map('trim', explode(',', $proxies))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        /*
        | فقط میزبان‌های خودمان (R17).
        |
        | بدون این، هدرِ `Host` (و با اعتماد به پراکسی، `X-Forwarded-Host`)
        | دستِ فرستنده‌ی درخواست است و لاراول آدرس‌های مطلق را با همان می‌سازد.
        | سنجیده شد نه فرض: درخواستی با `Host: evil.example.com` باعث می‌شد
        | `canonical` و `og:url` صفحه‌ی فرود همان دامنه را نشان بدهند — یعنی
        | اگر جایی کشِ اشتراکی باشد یا خزنده‌ای آن پاسخ را ببیند، اعتبارِ
        | سئوی سایت به دامنه‌ی مهاجم می‌رود.
        |
        | زیردامنه‌ها هم مجازند چون هر مجتمع ممکن است روی زیردامنه بیاید.
        |
        | `subdomains: true` خودش الگوی `^(.+\.)?<هاستِ APP_URL>$` را اضافه
        | می‌کند؛ پس فهرستِ صریح فقط برای دامنه‌های اضافی است (مثلاً دامنه‌ی
        | قدیمی در دوره‌ی مهاجرت) و معمولاً خالی می‌ماند.
        |
        | ⚠️ ورودی‌های `TRUSTED_HOSTS` **الگوی regex**‌اند نه نامِ ساده — این
        | قراردادِ خودِ سیمفونی است و اشتباه‌گرفتنش یعنی الگو هیچ‌وقت نمی‌خورد.
        |
        | لاراول این میان‌افزار را در `local` و هنگام تست خودبه‌خود غیرفعال
        | می‌کند، پس محیط توسعه دست‌نخورده می‌ماند.
        */
        $middleware->trustHosts(
            at: array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS')))),
            subdomains: true,
        );

        /*
         * کوکیِ دستگاه مورداعتماد از رمزنگاری کوکی مستثناست. مقدارش یک توکنِ
         * تصادفیِ پرآنتروپی است که سمت سرور هش می‌شود (مثل توکن API یا recaller)،
         * پس رمزنگاری امنیت تازه‌ای اضافه نمی‌کند. httpOnly هست و جاوااسکریپت
         * نمی‌تواند بخواندش.
         */
        /*
        | گزارشِ خطای مرورگر با `navigator.sendBeacon` فرستاده می‌شود و beacon
        | نمی‌تواند هدرِ سفارشی (از جمله X-CSRF-TOKEN) بفرستد. این مسیر هیچ
        | چیزی را تغییر نمی‌دهد جز افزودنِ یک ردیفِ خطا، پس مستثنا کردنش ریسکِ
        | معناداری ندارد؛ محافظتش محدودیتِ نرخ است (throttle:client-errors).
        */
        $middleware->validateCsrfTokens(except: [
            // هر دو شکلِ مسیر، چون نامِ مستعارِ بدونِ نسخه هنوز زنده است
            'api/v1/client-errors',
            'api/client-errors',
        ]);

        $middleware->encryptCookies(except: [
            TrustedDeviceService::COOKIE,
        ]);

        $middleware->web(append: [
            /*
             * هدرهای امنیتی روی همه‌ی پاسخ‌های web (شاملِ API، چون API هم روی
             * همین گروه سوار است). عمداً در لاراول و نه nginx: کانفیگِ nginx
             * طبق قید دست‌نخورده می‌ماند، و این‌طور هدرها با کد نسخه‌بندی
             * می‌شوند و تست دارند.
             */
            SecurityHeaders::class,
            AuthenticateTrustedDevice::class,
            EnsureActive::class,
            SetCurrentComplex::class,
            /*
             * قفلِ فقط‌خواندنیِ «حالتِ اولیه» (R21).
             *
             * پس از `SetCurrentComplex` می‌آید چون به کاربرِ واردشده نیاز
             * دارد، و پیش‌فرضش **بستن** است: هر مسیرِ نوشتنیِ تازه‌ای که فردا
             * اضافه شود خودبه‌خود قفل است، مگر صریحاً مستثنا شود.
             */
            LockInitialAccount::class,
        ]);

        /*
        | ترتیب اجرا مهم است، نه ترتیب نوشتن.
        |
        | لاراول میدل‌ورها را بر اساس فهرست اولویت مرتب می‌کند و
        | SubstituteBindings ته آن فهرست است. پس با اینکه دو میدل‌ور بالا
        | «بعد» از آن نوشته شده‌اند، عملاً هم بعد از آن اجرا می‌شدند: مدل از
        | روی پارامتر مسیر خوانده می‌شد در حالی که TenantContext هنوز خالی بود
        | و ComplexScope هیچ فیلتری نمی‌گذاشت.
        |
        | نتیجه‌اش نشت واقعی بین مجتمع‌ها بود؛ مدیر یک مجتمع می‌توانست با
        | دست‌کاری شناسه در URL، واحد یا اطلاعیه یا هزینه‌ی مجتمع دیگری را
        | ویرایش و حذف کند. اینجا صراحتاً پیش از بایندینگ می‌نشینند.
        |
        | این لایه‌ی اول است؛ لایه‌ی دوم `resolveRouteBinding` در
        | BelongsToComplex است که حتی اگر این ترتیب روزی به‌هم بخورد،
        | جداسازی را نگه می‌دارد.
        */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentComplex::class,
        );

        // و بررسی فعال بودن حساب پیش از هر دو، تا کاربر غیرفعال اصلاً به
        // مرحله‌ی خواندن داده نرسد.
        $middleware->prependToPriorityList(
            before: SetCurrentComplex::class,
            prepend: EnsureActive::class,
        );

        // ورود خودکارِ دستگاه مورداعتماد باید پیش از میدل‌ور `auth` اجرا شود،
        // وگرنه روی مسیرِ محافظت‌شده، نگهبان کاربر را رد می‌کند پیش از آنکه این
        // میدل‌ور فرصت واردکردنش را داشته باشد. StartSession در فهرست اولویت
        // جلوتر است، پس نشست تا این لحظه شروع شده.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: AuthenticateTrustedDevice::class,
        );

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | هر استثنای گزارش‌شدنی در جدولِ خودمان هم ثبت می‌شود تا پنلِ ادمین
        | حتی وقتی Sentry وصل نیست داده‌ی واقعی داشته باشد. `ErrorRecorder`
        | خودش هرگز استثنا پرتاب نمی‌کند، پس این قلاب نمی‌تواند درخواست را
        | بترکاند.
        */
        $exceptions->report(function (Throwable $e): void {
            ErrorRecorder::fromException($e, request()->fullUrl(), request()->method());
        });

        /*
        | تنها نقطه‌ی ساختِ پاسخِ خطای JSON در کلِ برنامه.
        |
        | پیش از این هر استثنا شکلِ خودش را داشت: بعضی `{message}`، بعضی
        | `{message, errors}`، محدودیتِ نرخ `{message, retryAfter}`، و ۵۰۰ در
        | محصول یک صفحه‌ی HTML که فرانت اصلاً نمی‌توانست بخواند.
        |
        | نکته‌ی ظریف: رندرهای اختصاصی (مثل آن‌که قبلاً برای throttle بود) با
        | این یکی رقابت می‌کردند و نتیجه به ترتیبِ ثبت وابسته می‌شد — که شکننده
        | است. حالا همه‌ی حالت‌ها داخلِ `ApiExceptionRenderer` تصمیم‌گیری
        | می‌شوند و ترتیب اهمیتی ندارد.
        */
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request));
    })->create();
