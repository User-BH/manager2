<?php

namespace Tests\Feature;

use App\Http\Middleware\SetCurrentComplex;
use App\Models\Concerns\BelongsToComplex;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * سیاههٔ کاملِ endpointها — نه چند نمونه (فاز ۲).
 *
 * ─── 🔴 چرا سیاهه و نه تستِ نمونه ──────────────────────────────────────────
 * تستِ نمونه ثابت می‌کند «این چند مسیر محافظت دارند». چیزی که لازم است
 * ادعای دیگری است: **هیچ مسیری بی‌محافظ نیست.**
 *
 * ⚠️ تفاوتشان روزی معلوم می‌شود که کسی مسیرِ صد‌و‌پنجاه‌و‌پنجم را اضافه
 * کند و یادش برود `auth` بگذارد. تستِ نمونه سبز می‌ماند؛ این یکی قرمز
 * می‌کند.
 *
 * پس اینجا از **خودِ جدولِ مسیرها** پرسیده می‌شود، نه از فهرستی که دستی
 * نوشته شده باشد.
 */
class EndpointInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مسیرهایی که عمداً بدونِ ورود کار می‌کنند.
     *
     * ⚠️ هرکدام دلیلِ خودش را دارد و هیچ‌کدام به مدلِ مستأجری دست
     * نمی‌زند — تستِ بعدی همین را می‌سنجد.
     *
     * @return list<string>
     */
    private function publicApiRoutes(): array
    {
        return [
            'ads',                      // تبلیغاتِ سکو، عمداً بی‌مجتمع
            'blog', 'blog/posts/{slug}', // بلاگِ عمومی؛ سقفِ نرخ + پرچمِ blog، فقط منتشرشده (مرحلهٔ ۷ · فاز ۷)
            'client-errors',            // گزارشِ خطای مرورگر
            'csrf-token',
            'features',                 // پرچم‌های قابلیت
            'feedback',                 // فرمِ بازخوردِ پشتیبانی؛ سقفِ نرخ + تله‌ی ربات (مرحلهٔ ۷ · فاز ۴)
            'login', 'login/resend', 'login/verify',
            'me',                       // برای مهمان تهی برمی‌گرداند
            'password/forgot', 'password/forgot/verify', 'password/reset',
            'register', 'register/verify',
            'site-settings',
            'support/chat', 'support/starters',
            'web-vitals',
        ];
    }

    /**
     * @return list<array{method: string, uri: string, middleware: list<string>}>
     */
    private function apiRoutes(): array
    {
        $rows = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $rows[] = [
                    'method' => $method,
                    'uri' => $uri,
                    'middleware' => $route->gatherMiddleware(),
                ];
            }
        }

        $this->assertNotEmpty($rows, 'هیچ مسیرِ APIای پیدا نشد؛ محافظ پوچ می‌شود.');

        return $rows;
    }

    /**
     * 🔴 هر مسیرِ API یا در سیاههٔ عمومی است یا `auth` دارد.
     *
     * ⚠️ سیاههٔ عمومی **درونِ همین تست** است و نه در کد، تا افزودن به آن
     * یک تصمیمِ دیده‌شونده در بازبینیِ کد باشد، نه یک خطِ پیکربندی.
     */
    public function test_every_api_route_is_authenticated_or_explicitly_public(): void
    {
        $public = $this->publicApiRoutes();
        $unprotected = [];

        foreach ($this->apiRoutes() as $row) {
            $path = preg_replace('#^api/(v1/)?#', '', $row['uri']);

            if (in_array($path, $public, true)) {
                continue;
            }

            $hasAuth = (bool) array_filter(
                $row['middleware'],
                fn (string $m): bool => str_starts_with($m, 'auth') || str_contains($m, 'Authenticate'),
            );

            if (! $hasAuth) {
                $unprotected[] = $row['method'].' '.$row['uri'];
            }
        }

        $this->assertSame(
            [],
            array_unique($unprotected),
            "این مسیرها نه `auth` دارند نه در سیاههٔ عمومی‌اند:\n  "
            .implode("\n  ", array_unique($unprotected))
            ."\n⚠️ یا محافظ اضافه کنید، یا با دلیل به `publicApiRoutes()` ببرید.",
        );
    }

    /**
     * ⚠️ سیاههٔ عمومی نباید کهنه شود.
     *
     * اگر مسیری از سیاهه حذف یا تغییرِ نام دهد، ورودیِ مرده در سیاهه
     * می‌ماند و کسی متوجه نمی‌شود — و سیاههٔ پرِ ورودیِ مرده همان است که
     * دفعهٔ بعد بی‌دقت به آن اضافه می‌کنند.
     */
    public function test_the_public_allowlist_has_no_dead_entries(): void
    {
        $live = [];

        foreach ($this->apiRoutes() as $row) {
            $live[] = preg_replace('#^api/(v1/)?#', '', $row['uri']);
        }

        $dead = array_values(array_diff($this->publicApiRoutes(), array_unique($live)));

        $this->assertSame([], $dead, 'این ورودی‌های سیاههٔ عمومی دیگر مسیری ندارند: '.implode('، ', $dead));
    }

    /**
     * 🔴 مهمان نباید هیچ ردیفِ مستأجری ببیند.
     *
     * ─── شکافی که فاز ۲ پیدا کرد ───────────────────────────────────────────
     * ⚠️ `SetCurrentComplex` برای درخواستِ **بدونِ کاربر** هیچ‌کدام از دو
     * شاخه‌اش را نمی‌رفت، پس زمینه روی `null` می‌ماند — و `null` در
     * `ComplexScope` یعنی **«فیلتر نگذار»**.
     *
     * امروز هیچ مسیرِ عمومی‌ای به مدلِ مستأجری نمی‌رسید، پس نشتِ فعالی
     * نبود. ولی محافظت به «کسی چنین مسیری اضافه نکند» وابسته بود، نه به
     * کد.
     */
    public function test_no_public_route_touches_a_tenant_model(): void
    {
        $tenantModels = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            /*
             * ⚠️ از **خودِ کلاس** پرسیده می‌شود نه از متنِ فایل.
             *
             * `grep` روی «BelongsToComplex» بیست مدل می‌داد؛ شش‌تایشان فقط
             * در کامنت اسمش را برده بودند (مثلِ `Advertisement` که صریح
             * نوشته «این یکی ندارد»). همان تلهٔ تکرارشوندهٔ این پروژه.
             */
            if (in_array(BelongsToComplex::class, class_uses_recursive($class), true)) {
                $tenantModels[] = $class;
            }
        }

        $this->assertGreaterThanOrEqual(10, count($tenantModels), 'مدل‌های مستأجری پیدا نشدند؛ محافظ پوچ می‌شود.');

        // مهمان: هیچ کاربری وارد نشده
        foreach ($this->publicApiRoutes() as $path) {
            $this->getJson('/api/v1/'.$path);
        }

        /*
         * پس از عبورِ درخواستِ مهمان، زمینه باید «محروم» باشد. اگر
         * `null` بماند، هر کوئریِ مستأجری بی‌فیلتر اجرا می‌شود.
         */
        $this->assertTrue(
            app(TenantContext::class)->deniesAll(),
            '🔴 پس از درخواستِ مهمان، زمینهٔ مستأجری «محروم» نشد — یعنی فیلتری اعمال نمی‌شود.',
        );
    }

    /**
     * 🔴 هر مسیرِ مدل‌بندِ مستأجری باید تستِ حملهٔ بینامجتمعی داشته باشد.
     *
     * ─── چرا این ادعا و نه شمردنِ تست‌ها ───────────────────────────────────
     * ⚠️ «۱۵ تستِ جداسازی داریم» چیزی را ثابت نمی‌کند. پرسشِ درست این است:
     * **آیا مسیری هست که کسی سراغش نرفته باشد؟**
     *
     * سیاههٔ فاز ۲ پاسخ داد: بله. `ServiceRequest` شش مسیرِ مدل‌بند داشت و
     * **هیچ‌کدام** تستِ بینامجتمعی نداشتند — از جمله دانلودِ پیوست. و
     * `wallet/{unit}` که موجودیِ کیفِ واحد را می‌دهد هم پوشش نداشت.
     *
     * این تست از **جدولِ مسیرها** می‌پرسد نه از فهرستِ دستی، پس مسیرِ
     * تازه‌ای که فردا اضافه شود هم باید پوشش بگیرد.
     */
    public function test_every_tenant_bound_route_has_a_cross_tenant_attack(): void
    {
        $covered = [];

        foreach (TenantIsolationTest::crossTenantRoutes() as [$method, $template]) {
            $covered[] = $method.' '.$template;
        }

        /*
         * ⚠️ مسیرهایی که عمداً پوشش ندارند، با دلیل.
         *
         * هر کدام بیش از یک پارامترِ مدل دارند و ماتریسِ فعلی فقط یک شناسه
         * را جایگزین می‌کند. حملهٔ معنادارشان به رکوردِ تودرتوی مجتمعِ دیگر
         * نیاز دارد و کارِ فاز ۸ است.
         */
        $knownGaps = [
            'PATCH units/%d/tenures/%d/end',
        ];

        $uncovered = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $bindsTenantModel = false;

            foreach ($route->signatureParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type || $type->isBuiltin() || ! class_exists($type->getName())) {
                    continue;
                }

                if (in_array(BelongsToComplex::class, class_uses_recursive($type->getName()), true)) {
                    $bindsTenantModel = true;
                }
            }

            if (! $bindsTenantModel) {
                continue;
            }

            $template = preg_replace(['#^api/v1/#', '#\{[^}]+\}#'], ['', '%d'], $uri);

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = $method.' '.$template;

                if (! in_array($key, $covered, true) && ! in_array($key, $knownGaps, true)) {
                    $uncovered[] = $key;
                }
            }
        }

        $uncovered = array_values(array_unique($uncovered));
        sort($uncovered);

        $this->assertSame(
            [],
            $uncovered,
            "این مسیرها مدلِ مستأجری می‌گیرند ولی تستِ حملهٔ بینامجتمعی ندارند:\n  "
            .implode("\n  ", $uncovered)
            ."\n⚠️ به `TenantIsolationTest::crossTenantRoutes()` اضافه کنید.",
        );
    }

    /**
     * ⚠️ فقط دو مسیر حق دارند از میدل‌ورِ مستأجری مستثنا باشند.
     *
     * 🔴 هر دو callbackِ بانک‌اند: بانک بدونِ نشست و گاهی ساعت‌ها بعد
     * برمی‌گردد. اگر روزی مسیرِ سومی به این فهرست اضافه شود، باید تصمیمِ
     * آگاهانه باشد نه اثرِ جانبیِ یک کپی‌وپیست.
     */
    public function test_only_the_gateway_callbacks_skip_the_tenant_middleware(): void
    {
        /*
         * ⚠️ سنجه **فهرستِ استثنا**ی خودِ مسیر است، نه `gatherMiddleware()`.
         *
         * 🔴 نسخهٔ اول `gatherMiddleware()` را می‌خواند و هر ۳۰۸ مسیر را
         * «مستثنا» گزارش کرد. علتش این بود که `SetCurrentComplex` عضوِ
         * **گروهِ `web`** است و آن متد نامِ گروه را برمی‌گرداند نه
         * کلاس‌های داخلش.
         *
         * `excludedMiddleware()` دقیقاً همان چیزی است که پرسیده می‌شود:
         * کدام مسیر صریحاً `withoutMiddleware` گفته است.
         */
        $exempt = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array(SetCurrentComplex::class, $route->excludedMiddleware(), true)) {
                $exempt[] = $route->uri();
            }
        }

        $exempt = array_values(array_unique($exempt));
        sort($exempt);

        $this->assertSame(
            // checkout/callback (مرحلهٔ ۹ · فاز ۴): خریدارِ اولیه مجتمعی ندارد و بانک بی نشست برمی‌گردد
            ['checkout/callback/{intent}', 'pay/callback/{payment}', 'subscription/callback/{subscription}'],
            $exempt,
            "مسیرهای مستثنا از زمینهٔ مستأجری عوض شده‌اند:\n  ".implode("\n  ", $exempt),
        );
    }
}
