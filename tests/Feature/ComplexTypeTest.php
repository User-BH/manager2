<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Account\ComplexUpgrader;
use App\Services\Subscription\ComplexPurchase;
use App\Support\PrivateFiles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\TestPlans;
use Tests\TestCase;

/**
 * مجتمعِ مسکونی و تجاری (R67).
 *
 * ─── تصمیمِ کارفرما، و آنچه از آن نتیجه می‌شود ──────────────────────────────
 * ① یک مجتمع **یا** مسکونی است **یا** تجاری — هرگز هر دو. پس نوع روی
 *   مجتمع می‌نشیند، نه روی واحد.
 * ② تفاوتِ واقعی **تعرفه‌ی پکیج‌های اشتراک** است.
 *
 * 🔴 و از بندِ ② یک الزامِ امنیتی بیرون می‌آید که این کلاس بیشترش را
 * می‌سنجد: اگر تعرفه به نوع وابسته باشد، **هر سه** مسیرِ خرید باید نوع را
 * بسنجند. فیلترکردنِ فقط فهرست یعنی مدیر می‌تواند slugِ نوعِ دیگر را
 * مستقیم POST کند و تعرفه‌ی ارزان‌تر را بگیرد.
 */
class ComplexTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚠️ قاعده‌ی خودِ پروژه، و `ArchitectureTest` همان اجرا گرفتش:
         * تستی که `UploadedFile::fake()` دارد باید دیسک را هم fake کند،
         * وگرنه رسیدهای آزمایشی در storageِ واقعی می‌نشینند.
         */
        Storage::fake(PrivateFiles::name());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  پایه
    // ─────────────────────────────────────────────────────────────────────

    /** ⚠️ هر مجتمعی که پیش از R67 ساخته شده مسکونی است؛ این یک واقعیت است نه حدس. */
    public function test_a_complex_is_residential_unless_told_otherwise(): void
    {
        $complex = Complex::factory()->create();

        $this->assertSame(ComplexType::Residential, $complex->fresh()->type);
    }

    public function test_the_type_round_trips_as_an_enum(): void
    {
        $complex = Complex::factory()->create(['type' => ComplexType::Commercial]);

        $this->assertSame(ComplexType::Commercial, $complex->fresh()->type);
        $this->assertSame('تجاری', $complex->fresh()->type->label());
    }

    /**
     * ⚠️ واژگان از **داده** مشتق می‌شود، نه از رشته‌ی ثابت در کد.
     *
     * تفاوتش با «تغییرِ چند Label» همین است: نوعِ سومی که روزی اضافه شود،
     * یک‌جا تعریف می‌شود نه با گشتنِ دنبالِ کلمه در ده‌ها فایل.
     */
    public function test_each_type_carries_its_own_vocabulary(): void
    {
        $this->assertSame('ساکن', ComplexType::Residential->occupantLabel());
        $this->assertSame('کسب‌وکار', ComplexType::Commercial->occupantLabel());
        $this->assertNotSame(
            ComplexType::Residential->unitLabel(),
            ComplexType::Commercial->unitLabel(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  فهرستِ پکیج‌ها
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_residential_complex_sees_only_its_own_tariffs(): void
    {
        $this->plan('res', ComplexType::Residential, 100000);
        $this->plan('com', ComplexType::Commercial, 300000);
        $this->plan('both', null, 200000);

        $slugs = collect(
            $this->actingAs($this->admin(ComplexType::Residential))
                ->getJson('/api/v1/subscription')
                ->assertOk()
                ->json('plans')
        )->pluck('value')->all();

        $this->assertContains('res', $slugs);
        $this->assertContains('both', $slugs, 'پکیجِ بی‌نوع باید برای هر دو دیده شود.');
        $this->assertNotContains('com', $slugs);
    }

    public function test_a_commercial_complex_sees_the_commercial_tariff(): void
    {
        $this->plan('res', ComplexType::Residential, 100000);
        $this->plan('com', ComplexType::Commercial, 300000);

        $slugs = collect(
            $this->actingAs($this->admin(ComplexType::Commercial))
                ->getJson('/api/v1/subscription')
                ->assertOk()
                ->json('plans')
        )->pluck('value')->all();

        $this->assertContains('com', $slugs);
        $this->assertNotContains('res', $slugs);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  🔴 یکپارچگیِ صورت‌حساب
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 مهم‌ترین تستِ این مرحله.
     *
     * فیلترِ فهرست تزئینی است اگر مسیرِ خرید هر slugی را بپذیرد: مدیرِ یک
     * مجتمعِ تجاری می‌تواند slugِ ارزانِ مسکونی را مستقیم بفرستد، بی‌آنکه
     * هرگز آن پکیج را در صفحه‌اش دیده باشد.
     */
    public function test_a_commercial_complex_cannot_buy_a_residential_tariff_by_the_retired_receipt_route(): void
    {
        $this->plan('res', ComplexType::Residential, 100000);

        // ۱۲/۲: رسید بازنشسته است — هیچ پکیجی از این راه، نه نوعِ دیگر و نه نوعِ خودش
        $this->actingAs($this->admin(ComplexType::Commercial))
            ->postJson('/api/v1/subscription/receipt', [
                'plan' => 'res',
                'receipt' => UploadedFile::fake()->image('r.jpg'),
            ])
            ->assertStatus(410);

        $this->assertSame(0, Subscription::count(), 'اشتراکی با تعرفه‌ی نوعِ دیگر ساخته شد.');
    }

    /** و همان محافظ روی مسیرِ خریدِ آنلاین. */
    public function test_a_commercial_complex_cannot_buy_a_residential_tariff_online(): void
    {
        $this->plan('res', ComplexType::Residential, 100000);

        /*
         * ⚠️ اینجا عمداً کدِ وضعیت سنجیده نمی‌شود.
         *
         * این مسیر وب است (مرورگر واقعاً به سایتِ بانک می‌رود) و شکلِ
         * نمایشِ `DomainException` رویش رفتارِ ازپیش‌موجودِ پروژه است — نه
         * چیزی که R67 عوضش کرده باشد؛ همان اتفاق برای پکیجِ نامعتبرِ
         * قدیمی هم می‌افتد. آنچه این تست باید ثابت کند نتیجه است.
         */
        $this->actingAs($this->admin(ComplexType::Commercial))
            ->post('/subscription/checkout', ['plan' => 'res']);

        $this->assertSame(0, Subscription::count(), 'اشتراکی با تعرفه‌ی نوعِ دیگر ساخته شد.');
    }

    /** ⚠️ و همان مجتمع باید بتواند تعرفه‌ی **خودش** را بخرد، وگرنه محافظ زیادی سفت است. */
    public function test_the_matching_tariff_is_still_purchasable(): void
    {
        $this->plan('com', ComplexType::Commercial, 300000);
        config(['subscription.gateway' => 'sandbox']);

        $this->actingAs($this->admin(ComplexType::Commercial))
            ->post('/subscription/checkout', ['plan' => 'com'])
            ->assertRedirect();

        $subscription = Subscription::firstOrFail();
        $this->assertSame([300000, 'online', 'pending'], [(int) $subscription->amount, $subscription->method, $subscription->status]);
    }

    /**
     * پکیجِ بی‌نوع برای هر دو قابلِ خرید است — همان رفتارِ پیش از R67.
     *
     * ⚠️ دو خرید در **دو اجرای جدا**، نه یک حلقه: زمینه‌ی مستأجری در طولِ
     * یک تست باقی می‌ماند و محافظِ «درخواستِ در انتظار داری» روی مجتمعِ
     * اول قفل می‌کرد — یعنی تست چیزی را می‌سنجید که ادعا نمی‌کرد.
     */
    #[DataProvider('bothTypes')]
    public function test_an_untyped_plan_stays_purchasable_by_everyone(ComplexType $type): void
    {
        $this->plan('both', null, 200000);
        config(['subscription.gateway' => 'sandbox']);

        $this->actingAs($this->admin($type))
            ->post('/subscription/checkout', ['plan' => 'both'])
            ->assertRedirect();

        $this->assertSame(1, Subscription::where('method', 'online')->where('amount', 200000)->count());
    }

    /** @return array<string, array{ComplexType}> */
    public static function bothTypes(): array
    {
        return [
            'مسکونی' => [ComplexType::Residential],
            'تجاری' => [ComplexType::Commercial],
        ];
    }

    /**
     * ⚠️ محافظِ ساختاری: هیچ‌کس نباید پکیجِ خرید را بدونِ فیلترِ نوع پیدا کند.
     *
     * سه مسیرِ مستقل همین کار را می‌کنند و اولین مسیرِ چهارمی که کسی
     * بنویسد، بی‌این محافظ از قلم می‌افتد — دقیقاً همان الگویی که در R63
     * (قلاب‌های صف) و R64 (شمارشِ پیامک) تکرار شد.
     */
    public function test_no_purchase_path_resolves_a_plan_without_the_type_filter(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(base_path()) + 1));

            /*
             * 🔴 کامنت‌ها **پیش از** سنجش پاک می‌شوند.
             *
             * نسخه‌ی اولِ این محافظ دنبالِ رشته‌ی خامِ `forComplexType`
             * می‌گشت. خرابکاریِ «فیلتر را از مسیرِ خرید بردار» از تورش رد
             * شد، چون داکبلاکِ بالای همان متد کلمه را در خود داشت — یعنی
             * محافظ با **نثرِ خودم** فریب خورد.
             *
             * همان تله‌ی آشنای این پروژه (R52): چیزی که مثلِ استفاده به
             * نظر می‌رسد ولی استفاده نیست. حالا هم کامنت حذف می‌شود و هم
             * الگو روی **فراخوانی** لنگر دارد نه روی نام.
             */
            $source = (string) file_get_contents($file->getPathname());
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $source);
            $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

            // فقط جاهایی که پکیج را از روی slug پیدا می‌کنند
            if (! preg_match("/Plan::where\(\s*'slug'/", $code)) {
                continue;
            }

            if (! str_contains($code, '->forComplexType(')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "این فایل‌ها پکیج را از روی slug پیدا می‌کنند بدونِ فیلترِ نوع:\n  ".implode("\n  ", $offenders),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  چه کسی نوع را عوض می‌کند
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 مدیرِ مجتمع نباید بتواند نوعش را عوض کند.
     *
     * وگرنه با یک سوییچ تعرفه‌ی ارزان‌تر را می‌گیرد و برمی‌گرداند. این
     * ورودیِ صورت‌حساب است، نه یک تنظیمِ ظاهری.
     */
    public function test_a_complex_admin_cannot_change_the_type(): void
    {
        $admin = $this->admin(ComplexType::Residential);

        $this->actingAs($admin)
            ->patchJson('/api/v1/system/complexes/'.$admin->complex_id.'/type', [
                'type' => ComplexType::Commercial->value,
            ])
            ->assertForbidden();

        $this->assertSame(ComplexType::Residential, $admin->complex->fresh()->type);
    }

    /** ⚠️ و تنظیماتِ خودِ مجتمع هم نباید مسیرِ دومی برایش باشد. */
    public function test_the_complex_settings_endpoint_ignores_the_type(): void
    {
        $admin = $this->admin(ComplexType::Residential);

        /*
         * 🔴 `assertOk` اینجا حیاتی است، نه تزئینی.
         *
         * نسخه‌ی اولِ این تست مسیر را اشتباه نوشته بود (`/settings/complex`
         * به‌جای `/settings`) و ۴۰۴ می‌گرفت — یعنی «نوع عوض نشد» را ثابت
         * می‌کرد چون **هیچ اتفاقی نیفتاده بود**. خرابکاریِ «مسیرِ تنظیمات
         * نوع را هم بنویسد» از تورش رد شد و همین لوش داد.
         *
         * حالا درخواست باید واقعاً موفق شود و **با وجودِ آن** نوع دست‌نخورده
         * بماند.
         */
        $this->actingAs($admin)->putJson('/api/v1/settings', [
            'name' => 'مجتمع', 'currency' => 'toman', 'charge_due_day' => 10,
            'payment_gateway' => 'none', 'penalty_type' => 'percent', 'penalty_value' => 0,
            'penalty_grace_days' => 0,
            'type' => ComplexType::Commercial->value,
        ])->assertOk();

        $this->assertSame(
            ComplexType::Residential,
            $admin->complex->fresh()->type,
            'نوع از مسیرِ تنظیمات عوض شد؛ آن مسیر نباید این ستون را بپذیرد.',
        );
    }

    public function test_a_super_admin_can_change_the_type(): void
    {
        $complex = Complex::factory()->create(['type' => ComplexType::Residential]);

        $this->actingAs($this->superAdmin())
            ->patchJson('/api/v1/system/complexes/'.$complex->id.'/type', [
                'type' => ComplexType::Commercial->value,
            ])
            ->assertOk();

        $this->assertSame(ComplexType::Commercial, $complex->fresh()->type);
    }

    /**
     * ⚠️ با اشتراکِ فعال، نوع قفل است.
     *
     * پکیجی که خریده شده مالِ نوعِ قبلی است؛ پس از تغییر، مجتمع اشتراکی
     * دارد که در فهرستِ خودش وجود ندارد — حالتی که هیچ صفحه‌ای بلد نیست
     * نشانش بدهد.
     */
    public function test_the_type_is_locked_while_a_subscription_is_active(): void
    {
        $complex = Complex::factory()->create(['type' => ComplexType::Residential]);
        $plan = $this->plan('res', ComplexType::Residential, 100000);

        Subscription::create([
            'complex_id' => $complex->id,
            'user_id' => $this->superAdmin()->id,
            'plan' => 'pro',
            'plan_id' => $plan->id,
            'status' => 'active',
            'method' => 'receipt',
            'amount' => 100000,
            'months' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->actingAs($this->superAdmin())
            ->patchJson('/api/v1/system/complexes/'.$complex->id.'/type', [
                'type' => ComplexType::Commercial->value,
            ])
            ->assertStatus(422);

        $this->assertSame(ComplexType::Residential, $complex->fresh()->type);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ساختِ مجتمع
    // ─────────────────────────────────────────────────────────────────────

    public function test_a_buyer_picks_the_type_when_the_purchase_creates_the_complex(): void
    {
        $plan = $this->plan('com', ComplexType::Commercial, 300000);

        $buyer = User::factory()->create(['role' => UserRole::Owner->value, 'complex_id' => null, 'is_active' => true]);

        // مرحلهٔ ۹ · فاز ۵: ساختنِ مجتمع فقط با خریدِ آنلاینِ تأییدشده
        $purchase = app(ComplexPurchase::class);
        $purchase->complete($purchase->begin($buyer, $plan, 'برجِ تجاری', ComplexType::Commercial, null), 'TRK-COM');

        $this->assertSame(ComplexType::Commercial, $buyer->fresh()->complex->type);
    }

    /**
     * 🔴 نبودنِ نوع دیگر «مسکونی» معنی نمی‌دهد — رد می‌شود (فاز ۱).
     *
     * ─── این تست عمداً برعکسِ نسخه‌ی قبلی‌اش است ────────────────────────────
     * ⚠️ نسخه‌ی قبلی می‌سنجید که نبودنِ فیلد **بپذیرد** و مسکونی ثبت کند، با
     * این استدلال که فیلدِ اجباریِ تازه تبِ بازِ کاربر را می‌شکند و «فرانت
     * همیشه می‌فرستدش».
     *
     * 🔴 آن استدلال روی فرضی غلط بنا شده بود: بازبینیِ فاز ۱ نشان داد **هیچ
     * فرمی در فرانت این فیلد را نمی‌فرستد** — در واقع هیچ فرمی اصلاً مجتمع
     * نمی‌سازد. یعنی آن «سازگاری» چیزی را حفظ نمی‌کرد؛ فقط باعث می‌شد هر
     * مجتمعِ تجاری بی‌صدا مسکونی ثبت شود و تعرفه‌ی اشتباه بگیرد.
     *
     * تست حذف نشد — **معکوس** شد، تا اگر روزی کسی پیش‌فرض را برگرداند،
     * همین‌جا قرمز شود.
     */
    public function test_a_missing_type_is_refused_instead_of_defaulting(): void
    {
        $this->plan('both', null, 200000);

        $buyer = User::factory()->create(['role' => UserRole::Owner->value, 'complex_id' => null, 'is_active' => true]);

        // مرحلهٔ ۹ · فاز ۵: همان قاعده روی تنها مسیرِ ساختنِ مجتمع — خریدِ آنلاین
        $this->actingAs($buyer)
            ->post('/subscription/checkout', [
                'plan' => 'both',
                'complex_name' => 'مجتمعِ بی‌نوع',
            ])
            ->assertSessionHasErrors('complex_type');

        /*
         * ⚠️ ادعای اصلی این است، نه خطای اعتبارسنجی: **هیچ مجتمعی ساخته نشده باشد.**
         * اعتبارسنجی‌ای که رد کند ولی رکورد بسازد، بدتر از نبودنش است.
         */
        $this->assertNull($buyer->fresh()->complex_id, 'با وجودِ رد شدن، مجتمع ساخته شد.');
        $this->assertSame(0, CheckoutIntent::count());
    }

    public function test_the_super_admin_creation_form_records_the_type(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/system/complexes', [
                'name' => 'پاساژِ مرکزی',
                'type' => ComplexType::Commercial->value,
                // مرحلهٔ ۹ · فاز ۶: مدیر با دعوت، نه با نام و رمزی که ادمین می‌نویسد
                'admin_phone' => '09121112233',
            ])
            ->assertCreated();

        $this->assertSame(
            ComplexType::Commercial,
            Complex::where('name', 'پاساژِ مرکزی')->first()->type,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  دیده‌شدن
    // ─────────────────────────────────────────────────────────────────────

    /** فرانت واژگانش را از سرور می‌گیرد، پس `/me` باید بدهدش. */
    public function test_the_current_user_payload_carries_the_type_vocabulary(): void
    {
        $complex = $this->actingAs($this->admin(ComplexType::Commercial))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->json('user.complex');

        $this->assertSame('commercial', $complex['type']);
        $this->assertSame('کسب‌وکار', $complex['occupantLabel']);
        $this->assertSame('واحد تجاری', $complex['unitLabel']);
    }

    /** ادمینِ کل بدونِ تفکیکِ نوع نمی‌داند تعرفه‌ی تجاری اصلاً مشتری دارد یا نه. */
    public function test_the_platform_stats_split_by_type(): void
    {
        Complex::factory()->create(['type' => ComplexType::Residential]);
        Complex::factory()->count(2)->create(['type' => ComplexType::Commercial]);

        $stats = $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/system/stats')
            ->assertOk()
            ->json('complexes');

        $this->assertSame(1, $stats['residential']);
        $this->assertSame(2, $stats['commercial']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  کمکی
    // ─────────────────────────────────────────────────────────────────────

    private function plan(string $slug, ?ComplexType $type, int $price): Plan
    {
        return TestPlans::create([
            'name' => $slug,
            'slug' => $slug,
            'complex_type' => $type,
            'price' => $price,
            'months' => 1,
            'is_active' => true,
        ]);
    }

    private function admin(ComplexType $type): User
    {
        $complex = Complex::factory()->create(['type' => $type]);

        return User::factory()->create([
            'role' => UserRole::ComplexAdmin->value,
            'complex_id' => $complex->id,
            'is_active' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return $this->superAdmin ??= User::factory()->create([
            'role' => UserRole::SuperAdmin->value,
            'is_active' => true,
        ]);
    }

    private ?User $superAdmin = null;

    /**
     * 🔴 امضای ارتقادهنده نباید نوع را اختیاری کند.
     *
     * ─── این تست هم از خرابکاری آمد ────────────────────────────────────────
     * ⚠️ خرابکاریِ «`ComplexType $type` را دوباره `?ComplexType $type = null`
     * کن» از تور رد شد. هیچ تستی قرمز نشد، چون همه‌ی فراخوان‌ها نوع را
     * می‌فرستند — تا روزی که یکی نفرستد.
     *
     * محافظِ واقعیِ آن مسیر **خودِ اعلانِ نوعِ PHP** است، نه یک شرطِ داخلِ
     * متد. و اعلانِ نوع را نمی‌شود با فراخوانی سنجید (کامپایلر جلویش را
     * می‌گیرد)، پس از بازتاب پرسیده می‌شود.
     *
     * ⚠️ این تنها جای فاز ۱ است که به‌جای رفتار، **امضا** سنجیده می‌شود — و
     * دلیلش این است که خودِ امضا اینجا محافظ است.
     */
    public function test_the_upgrader_cannot_be_called_without_a_type(): void
    {
        $parameter = collect(
            (new \ReflectionMethod(ComplexUpgrader::class, 'upgrade'))->getParameters()
        )->firstWhere(fn (\ReflectionParameter $p): bool => $p->getName() === 'type');

        $this->assertNotNull($parameter, 'پارامترِ `type` از ارتقادهنده حذف شده است.');
        $this->assertFalse($parameter->allowsNull(), 'نوع دوباره nullable شد؛ نبودنش بی‌صدا مسکونی می‌شود.');
        $this->assertFalse($parameter->isDefaultValueAvailable(), 'نوع پیش‌فرض گرفت؛ پیش‌فرضِ خطرناک برگشت.');
    }

    /** ⚠️ مقدارِ نامعتبر هم باید رد شود، نه اینکه به پیش‌فرض بیفتد. */
    public function test_an_invalid_type_is_refused(): void
    {
        $this->plan('both', null, 200000);

        $buyer = User::factory()->create(['role' => UserRole::Owner->value, 'complex_id' => null, 'is_active' => true]);

        $this->actingAs($buyer)
            ->post('/subscription/checkout', [
                'plan' => 'both',
                'complex_name' => 'مجتمعِ جعلی',
                'complex_type' => 'industrial',
            ])
            ->assertSessionHasErrors('complex_type');

        $this->assertNull($buyer->fresh()->complex_id);
        $this->assertSame(0, CheckoutIntent::count());
    }

    /** ⚠️ مسیرِ ادمینِ کل هم همان قاعده را دارد. */
    public function test_the_admin_creation_path_also_refuses_a_missing_type(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/system/complexes', [
                'name' => 'مجتمعِ بی‌نوع',
                'admin_name' => 'مدیر',
                'admin_phone' => '09121112244',
                'admin_password' => 'abcd1234',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->assertDatabaseMissing('complexes', ['name' => 'مجتمعِ بی‌نوع']);
    }

    /**
     * 🔴 عمیق‌ترین لایه: دیتابیس هم نباید جای خالی را پر کند.
     *
     * ─── این تست را خرابکاری خواست ─────────────────────────────────────────
     * ⚠️ سه خرابکاریِ فاز ۱ از تور رد شدند: برگرداندنِ پیش‌فرض در سرویس، در
     * کنترلر، و اختیاری‌کردنِ پارامترِ ارتقادهنده. هیچ‌کدام تستی را قرمز
     * نکردند.
     *
     * علتش یکی بود: ستونِ `complexes.type` پیش‌فرضِ `residential` داشت، پس
     * هر مسیری که نوع را جا می‌انداخت **بی‌صدا** مسکونی می‌شد. اعتبارسنجیِ
     * فرم مسیرِ HTTP را می‌بست، ولی مهاجرت، صف، کنسول و seeder از کنارش رد
     * می‌شدند.
     *
     * پیش‌فرض برداشته شد. حالا نبودِ نوع خطای دیتابیس می‌دهد — بلند و
     * غیرقابلِ‌نادیده‌گرفتن.
     */
    public function test_the_database_refuses_a_complex_without_a_type(): void
    {
        $this->expectException(QueryException::class);

        DB::table('complexes')->insert([
            'name' => 'مجتمعِ بی‌نوع',
            'slug' => 'no-type-'.uniqid(),
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => 'none',
        ]);
    }
}
