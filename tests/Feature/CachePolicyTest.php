<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * چه چیزی اجازه دارد روی دیسک بنشیند (R60).
 *
 * ─── 🔴 دو نشتِ مستقل که اندازه‌گیری پیدا کرد ───────────────────────────────
 * ① `public/sw.js` **همه‌ی** مسیرهای `/api/` را کش می‌کرد — از موجودیِ
 *   کیفِ پول تا تصویرِ رسیدِ بانکی.
 * ② **هیچ پاسخِ APIی هدرِ کش نداشت** — نه `no-store` نه `private`. یعنی
 *   حتی بدونِ service worker، کشِ دیسکیِ مرورگر همان داده را نگه می‌داشت.
 *
 * ─── چرا هر دو لایه لازم‌اند ───────────────────────────────────────────────
 * ⚠️ اگر فقط `sw.js` اصلاح می‌شد، مرورگری که SW را اجرا نمی‌کند (یا
 * کاربری که غیرفعالش کرده) همچنان نشت داشت. اگر فقط هدر گذاشته می‌شد،
 * `sw.js` هدر را نادیده می‌گرفت و می‌نوشت.
 *
 * این کلاس هر دو را می‌سنجد، و مهم‌تر: می‌سنجد که **از یک فهرست** بیایند.
 */
class CachePolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $complex = Complex::create([
            'name' => 'مجتمع کش',
            'slug' => 'cache-'.uniqid(),
            'currency' => 'toman',
            'charge_due_day' => 10,
        ]);

        $this->resident = User::create([
            'complex_id' => $complex->id,
            'name' => 'ساکن',
            'phone' => '09121110020',
            'role' => UserRole::Owner,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $complex->id,
            'unit_number' => '1',
            'floor' => 1,
            'area' => 100,
            'residents_count' => 2,
            'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied,
            'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($unit->id, [
            'complex_id' => $complex->id,
            'relation' => 'owner',
            'share_percent' => 100,
            'is_current' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۱) هدرهای سرور
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 پاسخِ شخصی نباید هیچ‌جا ذخیره شود.
     *
     * ⚠️ `no-store` و نه `no-cache`: دومی یعنی «ذخیره کن ولی اعتبارش را
     * بپرس» — پاسخ روی دیسک می‌نشیند و همان چیزی است که این مرحله برای
     * رفعش آمده.
     */
    public function test_personal_api_responses_are_never_stored(): void
    {
        foreach (['/api/v1/me', '/api/v1/my-bills', '/api/v1/csrf-token'] as $path) {
            $response = $this->actingAs($this->resident)->getJson($path);

            $this->assertStringContainsString(
                'no-store',
                (string) $response->headers->get('Cache-Control'),
                "«{$path}» هدرِ no-store ندارد؛ کشِ مرورگر می‌تواند نگهش دارد.",
            );

            $this->assertStringContainsString(
                'Cookie',
                (string) $response->headers->get('Vary'),
                "«{$path}» نمی‌گوید پاسخش به کوکی وابسته است.",
            );
        }
    }

    /**
     * 🔴 پاسخِ **خطا** هم باید `no-store` بگیرد.
     *
     * ⚠️ این شکاف در R61 اندازه‌گیری شد و غافلگیرکننده بود: پاسخی که از
     * یک استثنا ساخته می‌شود، از «پس‌ازِ» میدل‌ورها رد نمی‌شود. یعنی
     * ۴۰۱ و ۴۰۳ و ۴۲۲ — که بدنه‌شان می‌تواند پیامِ اعتبارسنجی و نامِ
     * فیلد داشته باشد — بدونِ هیچ هدرِ کشی می‌رفتند، و هدرهای امنیتی هم
     * همراهشان نبود.
     */
    public function test_error_responses_are_never_stored_either(): void
    {
        $response = $this->getJson('/api/v1/my-bills');

        $this->assertSame(401, $response->getStatusCode());

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
            'پاسخِ خطای API هدرِ no-store ندارد.',
        );

        $this->assertSame(
            'nosniff',
            $response->headers->get('X-Content-Type-Options'),
            'پاسخِ خطا هدرهای امنیتی را از دست داده است.',
        );
    }

    /** ⚠️ متقارن: بدونِ این، «همه‌چیز no-store» هم سبز می‌شد و کشِ عمومی می‌مرد. */
    public function test_public_api_responses_stay_cacheable(): void
    {
        $control = (string) $this->getJson('/api/v1/features')->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $control);
        $this->assertStringNotContainsString('no-store', $control);
        $this->assertStringContainsString(
            'max-age='.config('pwa.public_api_ttl'),
            $control,
            'مدتِ تازگی با config نمی‌خواند.',
        );
    }

    /** ⚠️ صفحه‌های عادی نباید قربانیِ این سیاست شوند. */
    public function test_ordinary_pages_are_left_alone(): void
    {
        $this->assertStringNotContainsString(
            'no-store',
            (string) $this->get('/')->headers->get('Cache-Control'),
            'صفحه‌ی فرود هم no-store گرفت؛ سیاست بیش از حد گسترده است.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ۲) یگانگیِ فهرست
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 دو فهرست در دو زبان، و باید یکی بمانند.
     *
     * سرور از `config('pwa.public_api')` می‌خواند و service worker فهرستِ
     * خودش را در جاوااسکریپت دارد — چون `sw.js` فایلِ ایستایی است که
     * نمی‌تواند از PHP بخواند.
     *
     * ⚠️ اگر واگرا شوند، بدترین حالت این است: سرور می‌گوید `no-store` و
     * SW همچنان کش می‌کند، یا برعکس. این تست تنها چیزی است که آن دو را
     * به هم می‌دوزد.
     */
    public function test_the_service_worker_and_the_server_share_one_list(): void
    {
        $source = (string) file_get_contents(public_path('sw.js'));

        preg_match("/const PUBLIC_API = \[(.*?)\]/s", $source, $match);

        $this->assertNotEmpty($match, 'فهرستِ PUBLIC_API در sw.js پیدا نشد؛ الگو خراب است.');

        preg_match_all("/'([^']+)'/", $match[1], $entries);

        $this->assertSame(
            array_values((array) config('pwa.public_api')),
            $entries[1],
            'فهرستِ مسیرهای عمومی در sw.js با config/pwa.php یکی نیست.',
        );
    }

    /**
     * 🔴 محافظِ اصلی: هیچ مسیرِ نیازمندِ ورود نباید عمومی اعلام شود.
     *
     * ⚠️ این تست از **خودِ روتر** می‌خواند، نه از فهرستِ دستی. اگر کسی
     * روزی `my-bills` را به فهرستِ عمومی اضافه کند — چون «آفلاین لازمش
     * داریم» — همین‌جا قرمز می‌شود.
     *
     * بدونِ این، فهرستِ عمومی فقط یک قولِ نانوشته بود.
     */
    public function test_no_authenticated_route_is_declared_public(): void
    {
        foreach ((array) config('pwa.public_api') as $path) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === 'api/v1/'.$path && in_array('GET', $r->methods(), true));

            $this->assertNotNull($route, "مسیرِ عمومیِ «{$path}» اصلاً وجود ندارد.");

            $guarded = collect($route->gatherMiddleware())
                ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'auth'))
                ->values()
                ->all();

            $this->assertSame(
                [],
                $guarded,
                "مسیرِ «{$path}» احراز هویت می‌خواهد ولی «عمومی» اعلام شده — ".
                'یعنی پاسخِ یک کاربرِ مشخص روی دیسک می‌نشیند.',
            );
        }
    }

    /**
     * ⚠️ فایل‌های شخصی زیرِ `/api/` هستند و باید در دامنه‌ی همین سیاست
     * بمانند.
     *
     * تصویرِ رسیدِ بانکی حساس‌ترین چیزی است که این سامانه سرو می‌کند؛
     * پیش از R60 با `networkFirst` کش می‌شد.
     */
    public function test_private_file_downloads_are_under_the_api_prefix(): void
    {
        $paths = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->map(fn ($r) => $r->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'receipt') || str_contains($uri, 'attachment'))
            ->values();

        $this->assertNotEmpty($paths, 'هیچ مسیرِ فایلِ شخصی پیدا نشد؛ الگو خراب است.');

        foreach ($paths as $uri) {
            $this->assertTrue(
                str_starts_with($uri, 'api/') || str_ends_with($uri, '.pdf'),
                "مسیرِ فایلِ شخصیِ «{$uri}» بیرون از پیشوندِ api است و سیاستِ کش شاملش نمی‌شود.",
            );
        }
    }
}
