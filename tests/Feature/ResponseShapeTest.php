<?php

namespace Tests\Feature;

use App\Enums\AnnouncementAudience;
use App\Enums\BillStatus;
use App\Enums\ChargeRuleType;
use App\Enums\ExpenseCategory;
use App\Enums\MessageAudience;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use App\Enums\ServiceRequestStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\ChargeRule;
use App\Models\Complex;
use App\Models\Discount;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Message;
use App\Models\MessagePoll;
use App\Models\Payment;
use App\Models\PollOption;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شکلِ پاسخِ مسیرهای حیاتی عوض نمی‌شود (فاز ۶).
 *
 * ─── 🔴 شکافی که اندازه‌گیری نشان داد ──────────────────────────────────────
 * قراردادِ OpenAPI ۱۲۶ مسیر را مستند می‌کند و بدنهٔ درخواست‌ها را هم دارد.
 * ولی شمارشِ فاز ۶ چیزِ دیگری گفت:
 *
 *     پاسخِ ۲۰۰ با شِما:    ۰
 *     پاسخِ ۲۰۰ بدونِ شِما: ۱۵۶
 *
 * ⚠️ یعنی قرارداد می‌گوید **چه مسیری هست**، نه **چه چیزی برمی‌گردد**. و
 * `ApiContractTest` هم سازگاری را در سطحِ مسیر می‌سنجد.
 *
 * 🔴 نتیجه‌اش این بود: تغییرِ نامِ یک فیلد در پاسخ — یا برداشتنش — هیچ تستی
 * را قرمز نمی‌کرد. فرانتِ تبِ بازِ کاربر `undefined` می‌گرفت و صفحه بی‌صدا
 * خالی می‌شد.
 *
 * ─── چرا اسنپ‌شات و نه شِمای کامل برای هر ۱۲۶ مسیر ─────────────────────────
 * ⚠️ نوشتنِ شِمای دستی برای همهٔ مسیرها کارِ هفته‌هاست و اولین چیزی است که
 * کهنه می‌شود. اصلاحیه هم می‌گوید «برای APIهای **Critical**».
 *
 * پس شکل از خودِ پاسخِ واقعی استخراج می‌شود و با فایلِ ثبت‌شده مقایسه
 * می‌گردد.
 *
 * ─── قاعدهٔ مقایسه: افزودن مجاز، برداشتن ممنوع ─────────────────────────────
 * 🔴 این عمدی است و همان تعریفِ سازگاریِ رو به عقب است:
 *
 *   • فیلدِ تازه   → مجاز. کلاینتِ قدیمی نادیده‌اش می‌گیرد.
 *   • فیلدِ حذف‌شده → **ممنوع**. کلاینتِ قدیمی `undefined` می‌گیرد.
 *   • نوعِ عوض‌شده → **ممنوع**. بدتر از حذف است، چون خطا هم نمی‌دهد.
 */
class ResponseShapeTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT = 'tests/fixtures/api-response-shapes.json';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $complex = Complex::create([
            'type' => 'residential',
            'name' => 'مجتمع قرارداد',
            'slug' => 'shape-'.uniqid(),
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => 'none',
            'messenger_enabled' => true,
        ]);

        $this->admin = User::factory()->create([
            'complex_id' => $complex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        $unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
            // ⚠️ `dashboard.debtors` از همین ستون می‌خواند، نه از جمعِ قبض‌ها
            'balance' => 400000,
        ]);

        /*
         * ⚠️ کاربر به واحد وصل می‌شود، وگرنه `/my-bills` فهرستِ خالی
         * برمی‌گرداند و شکلِ خودِ قبض اصلاً ثبت نمی‌شود — یعنی محافظ
         * دربارهٔ مهم‌ترین پاسخ ساکت می‌ماند.
         */
        $this->admin->units()->attach($unit->id, [
            'complex_id' => $complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);

        $bill = Bill::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, // ⚠️ دورهٔ جاری، وگرنه فهرست خالی برمی‌گردد و شکلِ قبض ثبت نمی‌شود
            'period' => Jalali::currentPeriod(),
            'total_amount' => 500000, 'paid_amount' => 0,
            'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);

        Payment::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'bill_id' => $bill->id,
            'user_id' => $this->admin->id, 'amount' => 1000,
            'method' => PaymentMethod::Receipt, 'status' => PaymentStatus::Pending,
        ]);

        /*
         * کیفِ شخصی (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶): بی ردیف، هم جیب‌های خودِ
         * کاربر (`wallets`) و هم جیب‌های اعضا برای مدیر (`members`) خالی ثبت می‌شدند.
         */
        app(WalletService::class)->credit($this->admin, $unit, 25000, WalletTransaction::SOURCE_ADJUSTMENT);

        /*
         * ─── دادهٔ چهار مسیرِ تازه (مرحلهٔ ۵، فاز ۱ — S4-29) ────────────────
         *
         * 🔴 بدونِ این ردیف‌ها، هر چهار مسیر `data: array:empty` برمی‌گردانند
         * و اسنپ‌شات **شکلِ ردیف را اصلاً ثبت نمی‌کند**. آن‌وقت پوشش روی
         * کاغذ بیشتر می‌شود و در عمل هیچ فیلدی محافظت نمی‌شود.
         *
         * ⚠️ همین تله یک بار در فاز ۶ با خرابکاری پیدا شد؛ حالا
         * `test_no_recorded_shape_is_vacuous` نمی‌گذارد تکرار شود.
         */
        Announcement::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'title' => 'اطلاعیهٔ قرارداد',
            'body' => 'متنِ آزمایشی برای ثبتِ شکلِ پاسخ.',
            'audience' => AnnouncementAudience::All, 'is_active' => true,
            'is_pinned' => false, 'published_at' => now(), 'created_by' => $this->admin->id,
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'user_id' => $this->admin->id,
            'body' => 'پیامِ آزمایشی برای ثبتِ شکلِ پاسخ.',
            'audience' => MessageAudience::All,
            'author_name' => $this->admin->name, 'author_role' => UserRole::ComplexAdmin->value,
        ]);

        ChargeRule::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'name' => 'شارژِ ثابتِ قرارداد',
            'type' => ChargeRuleType::Fixed, 'category' => ExpenseCategory::cases()[0],
            'config' => ['amount' => 100000], 'is_active' => true, 'sort_order' => 1,
        ]);

        Discount::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id,
            'period' => Jalali::currentPeriod(), 'amount' => 50000,
            'reason' => 'تخفیفِ آزمایشی', 'created_by' => $this->admin->id,
        ]);

        /*
         * ─── و ردیف‌هایی که محافظِ پوچی‌سنج لو داد ─────────────────────────
         *
         * 🔴 این‌ها به مسیرهای **قدیمیِ** سیاهه مربوط‌اند، نه مسیرهای تازه.
         * یعنی `residents.data`، `finance.expenses` و `payments.recent` از
         * فاز ۶ تا امروز **خالی ثبت می‌شدند** و شکلِ ردیفشان هیچ‌وقت
         * محافظت نشده بود.
         *
         * ⚠️ نمونهٔ دقیقِ همان چیزی که این فاز برای بستنش است: پوششی که
         * روی کاغذ بود و در عمل نبود.
         */
        $resident = User::factory()->create([
            'complex_id' => $complex->id,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);

        $resident->units()->attach($unit->id, [
            'complex_id' => $complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);

        // پرداختِ موفق — `payments.recent` فقط تسویه‌شده‌ها را نشان می‌دهد
        Payment::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id, 'bill_id' => $bill->id,
            'user_id' => $this->admin->id, 'amount' => 25000,
            'method' => PaymentMethod::Receipt, 'status' => PaymentStatus::Success,
            'paid_at' => now(),
        ]);

        Expense::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'title' => 'هزینهٔ آزمایشی',
            'amount' => 300000, 'category' => ExpenseCategory::cases()[0],
            'period' => Jalali::currentPeriod(), 'spend_date' => now(),
            'created_by' => $this->admin->id,
        ]);

        Income::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'title' => 'درآمدِ آزمایشی',
            'amount' => 120000, 'source' => 'اجارهٔ بام',
            'period' => Jalali::currentPeriod(), 'received_date' => now(),
            'created_by' => $this->admin->id,
        ]);

        ServiceRequest::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id,
            'user_id' => $resident->id, 'category' => ServiceRequestCategory::Facilities,
            'priority' => ServiceRequestPriority::Normal, 'status' => ServiceRequestStatus::New,
            'title' => 'درخواستِ آزمایشی', 'description' => 'شرحِ آزمایشی.',
        ]);

        Subscription::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'user_id' => $this->admin->id,
            'plan' => SubscriptionPlan::Pro, 'status' => 'active', 'method' => 'receipt',
            'amount' => SubscriptionPlan::Pro->price(), 'months' => 1,
            'starts_at' => now(), 'ends_at' => now()->addMonth(),
        ]);

        /*
         * قبضِ معوقِ دورهٔ گذشته — تا `dashboard.debtors` شکلِ ردیفش را ثبت کند.
         *
         * ⚠️ بدهکارها مالی‌اند و مستقیم روی داشبوردِ مدیر رندر می‌شوند؛
         * حذفِ بی‌صدای یک فیلد از این ردیف یعنی مدیر عددِ اشتباه ببیند.
         */
        Bill::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id,
            'period' => Jalali::shiftPeriod(Jalali::currentPeriod(), -1),
            'total_amount' => 400000, 'paid_amount' => 0,
            'status' => BillStatus::Unpaid, 'issued_at' => now()->subMonth(),
        ]);

        // قبضِ **به‌موقع پرداخت‌شده** — تنها راهی که `dashboard.goodPayers` ردیف بسازد
        Bill::withoutGlobalScopes()->create([
            'complex_id' => $complex->id, 'unit_id' => $unit->id,
            'period' => Jalali::shiftPeriod(Jalali::currentPeriod(), -2),
            'total_amount' => 200000, 'paid_amount' => 200000,
            'status' => BillStatus::Paid, 'issued_at' => now()->subMonths(2),
            'due_date' => now()->subMonths(2)->addDays(10),
            'paid_at' => now()->subMonths(2)->addDays(3),
        ]);

        // مدیرِ دوم — `profile.people` برای مدیر یعنی «هم‌تیمی‌های مدیریت»
        User::factory()->create([
            'complex_id' => $complex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        // نظرسنجیِ باز — `dashboard.openPolls`
        $poll = MessagePoll::create([
            'message_id' => $message->id,
            'question' => 'پرسشِ آزمایشی؟',
            'closes_at' => now()->addWeek(),
        ]);

        foreach (['گزینهٔ یک', 'گزینهٔ دو'] as $order => $label) {
            PollOption::create([
                'message_poll_id' => $poll->id,
                'label' => $label,
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * مسیرهایی که شکستِ بی‌صدای شکلشان بیشترین هزینه را دارد.
     *
     * ⚠️ همه خواندنی‌اند و همه چیزی برمی‌گردانند که فرانت مستقیم رندر
     * می‌کند.
     *
     * @return list<string>
     */
    protected function criticalEndpoints(): array
    {
        return [
            '/api/v1/me',
            '/api/v1/dashboard',
            '/api/v1/units',
            '/api/v1/bills',
            // 🔴 قبض‌های خودِ ساکن — از BillResource می‌آید و مستقیم رندر می‌شود
            '/api/v1/my-bills',
            '/api/v1/payments',
            '/api/v1/finance',
            '/api/v1/notifications',
            '/api/v1/residents',

            /* ── افزودهٔ مرحلهٔ ۵، فاز ۱ (S4-29) ─────────────────────────── */
            // 🔴 مالی — شکستنِ بی‌صدای شکلش یعنی موجودی اشتباه نشان داده شود
            '/api/v1/wallet',
            '/api/v1/service-requests',
            '/api/v1/announcements',
            '/api/v1/messenger',
            // فرمِ تنظیماتِ مجتمع مستقیم از این ساخته می‌شود
            '/api/v1/settings',
            '/api/v1/profile',
            '/api/v1/subscription',
            '/api/v1/charge-rules',
            '/api/v1/discounts',
            '/api/v1/managers',
            '/api/v1/notifications/settings',
            '/api/v1/features',
        ];
    }

    /**
     * کلیدهایی که **حق دارند** خالی باشند، با دلیل.
     *
     * ─── ⚠️ چرا سیاههٔ صریح و نه قاعدهٔ کلی ─────────────────────────────────
     * قاعدهٔ «هیچ کلیدی خالی نباشد» تورِ پرسروصدایی می‌سازد: `hiddenIds`
     * وقتی چیزی پنهان نشده واقعاً خالی است و باید باشد.
     *
     * 🔴 ولی قاعدهٔ «خالی بودن اشکالی ندارد» هم بی‌معناست، چون همان چیزی
     * است که پوششِ صوری می‌سازد. پس هر خالیِ مجاز **نوشته** می‌شود — همان
     * الگوی معافیتِ تصویر در `PerformanceBaselineTest`.
     *
     * @var array<string, string>
     */
    private const EMPTY_ALLOWED = [
        /*
         * ⚠️ تنها موردِ مجاز — و عمداً فقط همین یکی.
         *
         * `hiddenIds` فهرستِ شناسه است، نه فهرستِ شیء: شکلی ندارد که بشکند.
         * ضمناً وقتی مدیر هیچ پیامی پنهان نکرده باشد **باید** خالی باشد و
         * پر کردنش یعنی ساختنِ حالتی که کاربرِ عادی نمی‌بیند.
         */
        '/api/v1/messenger → hiddenIds' => 'فهرستِ شناسه است نه شیء؛ خالی‌بودنش حالتِ عادی است.',
    ];

    /**
     * شکلِ یک مقدار: کلیدها و نوعشان، بازگشتی.
     *
     * ⚠️ **مقدارها اصلاً وارد نمی‌شوند** — فقط ساختار. وگرنه اسنپ‌شات با
     * هر تغییرِ دادهٔ آزمایشی می‌شکست و کسی جدی‌اش نمی‌گرفت.
     *
     * ⚠️ از آرایهٔ فهرستی فقط عنصرِ اول نمونه‌برداری می‌شود؛ همهٔ عناصر یک
     * شکل دارند و ثبتِ همه فقط فایل را بزرگ می‌کند.
     */
    protected function shapeOf(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($value === []) {
                return 'array:empty';
            }

            if (array_is_list($value)) {
                return [$this->shapeOf($value[0])];
            }

            $shape = [];

            foreach ($value as $key => $item) {
                $shape[$key] = $this->shapeOf($item);
            }

            ksort($shape);

            return $shape;
        }

        return get_debug_type($value);
    }

    /**
     * 🔴 هیچ فیلدی از پاسخ‌های حیاتی بی‌صدا حذف یا نوع‌عوض نمی‌شود.
     */
    /**
     * 🔴 هیچ شکلِ ثبت‌شده‌ای پوچ نیست.
     *
     * ─── چه چیزی را می‌گیرد ────────────────────────────────────────────────
     * افزودنِ یک مسیر به سیاهه وقتی آن مسیر `data: array:empty` برمی‌گرداند،
     * **پوشش را روی کاغذ زیاد می‌کند و در عمل هیچ**: شکلِ ردیف اصلاً ثبت
     * نمی‌شود، پس حذفِ فیلد از ردیف هم قرمز نمی‌کند.
     *
     * ⚠️ این دقیقاً همان چیزی است که در فاز ۶ با خرابکاری پیدا شد. آن‌بار
     * دستی اصلاح شد؛ حالا محافظ دارد.
     */
    public function test_no_recorded_shape_is_vacuous(): void
    {
        $vacuous = [];

        foreach ($this->criticalEndpoints() as $endpoint) {
            $response = $this->actingAs($this->admin)->getJson($endpoint);

            $this->assertSame(200, $response->status(), "مسیرِ «{$endpoint}» ۲۰۰ نداد.");

            $shape = $this->shapeOf($response->json());

            if (! is_array($shape)) {
                $vacuous[] = "{$endpoint}: پاسخ اصلاً ساختار ندارد";

                continue;
            }

            foreach ($shape as $key => $value) {
                if ($value !== 'array:empty') {
                    continue;
                }

                if (isset(self::EMPTY_ALLOWED["{$endpoint} → {$key}"])) {
                    continue;
                }

                $vacuous[] = "{$endpoint} → {$key}";
            }
        }

        $this->assertSame(
            [],
            $vacuous,
            "این کلیدها خالی ثبت می‌شوند، پس شکلِ ردیفشان محافظت نمی‌شود:\n  "
            .implode("\n  ", $vacuous)
            ."\n⚠️ یا در `setUp()` داده بسازید، یا با دلیل به `EMPTY_ALLOWED` اضافه کنید.",
        );
    }

    public function test_no_critical_response_loses_a_field(): void
    {
        $path = base_path(self::SNAPSHOT);

        /*
         * ⚠️ حالتِ بازتولید — که پیامِ خطای پایین سال‌ها وعده‌اش را می‌داد
         * ولی پیاده‌سازی‌اش وجود نداشت (پیدا شده در فاز ۱ مرحلهٔ ۵).
         *
         * 🔴 عمداً تست را **ناتمام** اعلام می‌کند، نه سبز: تولیدِ اسنپ‌شات
         * راستی‌آزمایی نیست و نباید شبیهش به نظر برسد.
         */
        if (getenv('SHAPE_SNAPSHOT') === '1') {
            $fresh = [];

            foreach ($this->criticalEndpoints() as $endpoint) {
                $fresh[$endpoint] = $this->shapeOf(
                    $this->actingAs($this->admin)->getJson($endpoint)->json(),
                );
            }

            file_put_contents(
                $path,
                json_encode($fresh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            );

            $this->markTestIncomplete('اسنپ‌شات بازتولید شد. آن را **بخوانید** و بعد کامیت کنید.');
        }

        $this->assertFileExists(
            $path,
            'اسنپ‌شاتِ شکلِ پاسخ‌ها وجود ندارد. با `SHAPE_SNAPSHOT=1` تست را اجرا کنید، '
            .'خروجی را **بازبینی** کنید، و بعد کامیتش کنید.',
        );

        /** @var array<string, mixed> $expected */
        $expected = json_decode((string) file_get_contents($path), true) ?: [];

        $this->assertNotEmpty($expected, 'اسنپ‌شات خالی است؛ این محافظ پوچ می‌شود.');

        $broken = [];

        foreach ($this->criticalEndpoints() as $endpoint) {
            $response = $this->actingAs($this->admin)->getJson($endpoint);

            $this->assertSame(200, $response->status(), "مسیرِ «{$endpoint}» ۲۰۰ نداد.");

            $actual = $this->shapeOf($response->json());

            foreach ($this->missingFrom($expected[$endpoint] ?? [], $actual) as $problem) {
                $broken[] = "{$endpoint} → {$problem}";
            }
        }

        $this->assertSame(
            [],
            $broken,
            "شکلِ پاسخ عوض شده و کلاینتِ قدیمی می‌شکند:\n  ".implode("\n  ", $broken)
            ."\n⚠️ افزودنِ فیلد مجاز است؛ حذف و تغییرِ نوع نه.",
        );
    }

    /**
     * فیلدهایی که در اسنپ‌شات بودند و حالا نیستند یا نوعشان عوض شده.
     *
     * @return list<string>
     */
    private function missingFrom(mixed $expected, mixed $actual, string $trail = ''): array
    {
        if (is_array($expected) && array_is_list($expected)) {
            if (! is_array($actual) || ! array_is_list($actual)) {
                return ["{$trail}: فهرست بود، حالا نیست"];
            }

            // ⚠️ فهرستِ خالی در اجرای امروز چیزی را رد نمی‌کند
            return $actual === [] || $expected === []
                ? []
                : $this->missingFrom($expected[0], $actual[0], $trail.'[]');
        }

        if (is_array($expected)) {
            if (! is_array($actual)) {
                return ["{$trail}: شیء بود، حالا «".get_debug_type($actual).'»'];
            }

            $problems = [];

            foreach ($expected as $key => $sub) {
                $where = $trail === '' ? (string) $key : "{$trail}.{$key}";

                if (! array_key_exists($key, $actual)) {
                    $problems[] = "{$where}: حذف شده";

                    continue;
                }

                $problems = [...$problems, ...$this->missingFrom($sub, $actual[$key], $where)];
            }

            return $problems;
        }

        /*
         * ⚠️ `null` در هر دو جهت پذیرفته است.
         *
         * فیلدِ nullable در دادهٔ آزمایشی گاهی پر است و گاهی خالی؛ سخت‌گیری
         * رویش تست را بی‌ثبات می‌کند بی‌آنکه چیزی اثبات کند.
         */
        if ($expected === 'null' || $actual === 'null') {
            return [];
        }

        if ($expected === $actual) {
            return [];
        }

        /*
         * ⚠️ توصیف و نه درج‌سازیِ مستقیم.
         *
         * اگر یک طرف آرایه باشد و طرفِ دیگر رشته، درجِ مستقیم خطای
         * «Array to string conversion» می‌دهد — یعنی محافظ به‌جای گزارشِ
         * تفاوت، خودش می‌ترکد.
         */
        return ["{$trail}: «".$this->describe($expected).'» بود، حالا «'.$this->describe($actual).'»'];
    }

    /** توصیفِ کوتاهِ یک شکل، برای پیامِ خطا. */
    private function describe(mixed $shape): string
    {
        return is_array($shape) ? (array_is_list($shape) ? 'فهرست' : 'شیء') : (string) $shape;
    }

    /**
     * ⚠️ محافظِ ضدِ پوچی — خودِ مقایسه‌گر باید حذف را ببیند.
     *
     * 🔴 بدونِ این، مقایسه‌گری که همیشه آرایهٔ خالی برگرداند هم سبز می‌ماند
     * و کلِ این فایل تزئین می‌شد.
     */
    public function test_the_comparison_actually_detects_a_break(): void
    {
        $before = ['id' => 'int', 'name' => 'string', 'unit' => ['area' => 'int']];

        $this->assertSame([], $this->missingFrom($before, $before), 'شکلِ یکسان نباید مشکل بدهد.');

        $this->assertNotSame([], $this->missingFrom($before, ['id' => 'int', 'name' => 'string']));
        $this->assertNotSame([], $this->missingFrom($before, ['id' => 'string', 'name' => 'string', 'unit' => ['area' => 'int']]));
        $this->assertNotSame([], $this->missingFrom($before, ['id' => 'int', 'name' => 'string', 'unit' => ['floor' => 'int']]));

        // ⚠️ و افزودن نباید مشکل باشد
        $this->assertSame(
            [],
            $this->missingFrom($before, [...$before, 'extra' => 'string']),
            'افزودنِ فیلد نباید شکست باشد؛ کلاینتِ قدیمی نادیده‌اش می‌گیرد.',
        );
    }
}
