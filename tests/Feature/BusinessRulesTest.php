<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Http\Controllers\GatewayController;
use App\Models\Bill;
use App\Models\Plan;
use App\Models\Scopes\ComplexScope;
use App\Models\Subscription;
use App\Observers\AuditObserver;
use App\Services\Charge\BillGenerator;
use App\Services\Charge\ChargeCalculator;
use App\Services\Charge\PenaltyCalculator;
use App\Services\Payment\PaymentService;
use App\Services\Poll\PollService;
use App\Services\Subscription\PlanGate;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\Notifications;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;
use Tests\Unit\ChargeCalculatorTest;
use Tests\Unit\PenaltyCalculatorTest;

/**
 * سیاهه‌ی صریحِ قواعدِ کسب‌وکار (R68).
 *
 * ─── 🔴 مسئله‌ای که حل می‌کند ──────────────────────────────────────────────
 * پروژه بیش از هزار تست داشت و **هیچ‌جا نوشته نبود کدام قاعده‌ی کسب‌وکار
 * را کدام تست تضمین می‌کند**. نتیجه‌اش دو خطر بود که هر دو ساکت‌اند:
 *
 * ① قاعده‌ای که هیچ‌کس تستش را ننوشته، از نبودش خبری نمی‌دهد. تنها راهِ
 *    فهمیدنش شمردنِ دستیِ تست‌هاست — که یک بار انجام می‌شود و کهنه می‌شود.
 * ② تستی که حذف یا تغییرِ نام بدهد، قاعده را بی‌محافظ می‌گذارد و مجموعه
 *    همچنان سبز می‌ماند.
 *
 * این سیاهه هر دو را می‌بندد: هر قاعده **نامِ تستِ ضامنش** را دارد، و
 * محافظ‌های پایین می‌سنجند که آن تست واقعاً وجود داشته باشد.
 *
 * ─── ⚠️ چرا این «سندِ تزئینی» نیست ─────────────────────────────────────────
 * چون از کدِ واقعی مشتق می‌شود و با آن قفل شده: تغییرِ نامِ یک تست، یا
 * حذفِ یک کلاسِ سرویس، همین‌جا قرمز می‌شود. سندِ Markdown چنین کاری
 * نمی‌کند.
 *
 * ⚠️ **مرزش را هم صریح بگوییم:** این سیاهه ثابت نمی‌کند قاعده **درست**
 * پیاده شده — آن کارِ خودِ تستِ نام‌برده‌شده است. اینجا فقط تضمین می‌شود
 * که قاعده‌ای بی‌ضامن نماند.
 */
class BusinessRulesTest extends TestCase
{
    /**
     * ده دامنه‌ی خواسته‌شده در برنامه‌ی کار.
     *
     * @var array<int, string>
     */
    private const DOMAINS = [
        'شارژ', 'تخفیف', 'جریمه', 'کیف پول', 'پرداخت',
        'دسترسی', 'اشتراک', 'اعلان', 'نظرسنجی', 'مدیریت واحد',
    ];

    /**
     * قاعده ← کلاسی که اجرایش می‌کند ← تستی که ضامنش است.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function rules(): array
    {
        return [
            // ── شارژ ────────────────────────────────────────────────────
            'شارژِ ثابت به همه‌ی واحدها یکسان تعلق می‌گیرد' => [
                'شارژ', ChargeCalculator::class,
                ChargeCalculatorTest::class, 'test_fixed_charges_every_unit_the_same',
            ],
            'شارژِ نفری با تعدادِ ساکنین تغییر می‌کند' => [
                'شارژ', ChargeCalculator::class,
                ChargeCalculatorTest::class, 'test_per_person_scales_with_residents',
            ],
            'شارژِ متراژی با مساحتِ واحد تغییر می‌کند' => [
                'شارژ', ChargeCalculator::class,
                ChargeCalculatorTest::class, 'test_per_area_scales_with_area',
            ],
            'تقسیمِ استخری هیچ ریالی نمی‌سازد و گم نمی‌کند' => [
                'شارژ', ChargeCalculator::class,
                ChargeCalculatorTest::class, 'test_utility_pool_splits_by_persons_and_sums_to_pool',
            ],

            // ── تخفیف ───────────────────────────────────────────────────
            'تخفیف نمی‌تواند قبض را منفی کند' => [
                'تخفیف', BillGenerator::class,
                BillingFlowTest::class, 'test_a_discount_never_pushes_a_bill_below_zero',
            ],

            // ── جریمه ───────────────────────────────────────────────────
            'مجتمعِ با جریمه‌ی خاموش هرگز جریمه نمی‌گیرد' => [
                'جریمه', PenaltyCalculator::class,
                PenaltyCalculatorTest::class, 'test_a_complex_with_penalties_off_never_charges',
            ],
            'مهلتِ ارفاق از روزهای دیرکرد کم می‌شود' => [
                'جریمه', PenaltyCalculator::class,
                PenaltyCalculatorTest::class, 'test_the_grace_period_is_honoured',
            ],
            'جریمه‌ی درصدِ روزانه سقف ندارد و با روز جمع می‌شود' => [
                'جریمه', PenaltyCalculator::class,
                PenaltyCalculatorTest::class, 'test_a_daily_percentage_compounds_with_the_days',
            ],
            'بدهیِ صفر یا منفی جریمه نمی‌گیرد' => [
                'جریمه', PenaltyCalculator::class,
                PenaltyCalculatorTest::class, 'test_no_penalty_without_an_overdue_amount',
            ],

            // ── کیف پول ─────────────────────────────────────────────────
            'موجودی همیشه از جمعِ دفترِ کل مشتق می‌شود، نه از ستونِ ذخیره‌شده' => [
                'کیف پول', WalletService::class,
                WalletLedgerTest::class, 'test_the_service_never_stores_a_mutable_balance',
            ],
            'کیف پول منفی نمی‌شود' => [
                'کیف پول', WalletService::class,
                WalletLedgerTest::class, 'test_a_wallet_cannot_go_negative',
            ],
            'برداشتِ ردشده هیچ ردیفی در دفتر نمی‌نویسد' => [
                'کیف پول', WalletService::class,
                WalletLedgerTest::class, 'test_a_refused_debit_writes_no_ledger_row',
            ],
            'ردیفِ دفترِ کل پس از ثبت ویرایش نمی‌شود' => [
                'کیف پول', WalletService::class,
                FinancialIntegrityTest::class, 'test_a_ledger_row_can_never_be_edited',
            ],

            // ── پرداخت ──────────────────────────────────────────────────
            'هیچ پولی از سامانه ناپدید نمی‌شود' => [
                'پرداخت', PaymentService::class,
                FinancialIntegrityTest::class, 'test_no_money_ever_disappears_from_the_system',
            ],
            'شروعِ دوباره‌ی پرداختِ آنلاین، تراکنشِ در انتظار را بازاستفاده می‌کند' => [
                'پرداخت', GatewayController::class,
                FinancialIntegrityTest::class, 'test_starting_an_online_payment_twice_reuses_the_pending_one',
            ],
            'بازگشتِ تکراری از بانک مبلغ را دوبار ثبت نمی‌کند' => [
                'پرداخت', GatewayController::class,
                GatewayCallbackTest::class, 'test_a_repeated_callback_does_not_credit_the_amount_twice',
            ],
            'کاربرِ دیگری نمی‌تواند بازگشتِ پرداختِ کسِ دیگر را از آنِ خود کند' => [
                'پرداخت', GatewayController::class,
                GatewayCallbackTest::class, 'test_another_logged_in_user_cannot_claim_someone_elses_callback',
            ],

            // ── دسترسی ──────────────────────────────────────────────────
            'ساکن به رکوردِ ساکنِ دیگر نمی‌رسد' => [
                'دسترسی', ComplexScope::class,
                AuthorizationAttackTest::class, 'test_a_resident_cannot_reach_another_residents_record',
            ],
            'مدیرِ یک مجتمع به دادهٔ مجتمعِ دیگر نمی‌رسد' => [
                'دسترسی', ComplexScope::class,
                TenantIsolationTest::class, 'test_an_admin_cannot_touch_another_complexes_records',
            ],
            'حملهٔ ناموفق هیچ داده‌ای را عوض نمی‌کند' => [
                'دسترسی', ComplexScope::class,
                AuthorizationAttackTest::class, 'test_none_of_those_attempts_changed_anything',
            ],

            // ── اشتراک ──────────────────────────────────────────────────
            'پلنِ رایگان از سقفِ واحد عبور نمی‌کند' => [
                'اشتراک', PlanGate::class,
                SubscriptionFlowTest::class, 'test_free_plan_blocks_creating_more_than_twenty_units',
            ],
            'اشتراکِ منقضی به سقفِ رایگان برمی‌گردد' => [
                'اشتراک', PlanGate::class,
                SubscriptionFlowTest::class, 'test_expired_subscription_falls_back_to_the_free_limit',
            ],
            'درگاهِ بانکیِ واقعی پشتِ پلنِ پولی است' => [
                'اشتراک', PlanGate::class,
                SubscriptionFlowTest::class, 'test_free_plan_blocks_connecting_a_real_bank_gateway',
            ],
            // ۱۲/۲: رسید بازنشسته است؛ دو مسیرِ خرید (تمدیدِ آنلاین و مجتمعِ تازه) ماندند
            'تعرفهٔ پکیج به نوعِ مجتمع گره خورده و در هر مسیرِ خرید اعمال می‌شود' => [
                'اشتراک', Plan::class,
                ComplexTypeTest::class, 'test_a_commercial_complex_cannot_buy_a_residential_tariff_online',
            ],
            '🔴 خرید و تمدید فقط آنلاین؛ ادمینِ کل اشتراکِ پرداخت‌نشده را فعال نمی‌کند (۱۲/۲)' => [
                'اشتراک', Subscription::class,
                SubscriptionActivationTest::class, 'test_the_model_refuses_every_activation_outside_the_verified_path',
            ],

            // ── اعلان ───────────────────────────────────────────────────
            'حذفِ رکورد بدونِ دخالتِ کنترلر در سیاههٔ ممیزی ثبت می‌شود' => [
                'اعلان', AuditObserver::class,
                EventsAndNotificationsTest::class, 'test_deleting_a_record_is_logged_without_the_controller_asking',
            ],
            'جدول‌های پرترافیک در سیاهه ثبت نمی‌شوند' => [
                'اعلان', AuditObserver::class,
                EventsAndNotificationsTest::class, 'test_high_churn_models_are_not_logged',
            ],
            'تسویهٔ پرداخت رویدادِ اعلان را می‌فرستد' => [
                'اعلان', Notifications::class,
                EventsAndNotificationsTest::class, 'test_settling_a_payment_dispatches_the_event',
            ],

            // ── نظرسنجی ─────────────────────────────────────────────────
            'مستأجر در نظرسنجیِ ویژهٔ مالکان رأی نمی‌دهد' => [
                'نظرسنجی', PollService::class,
                PollTest::class, 'test_a_tenant_cannot_vote_in_an_owners_only_poll',
            ],
            'نظرسنجیِ واحدمحور از هر واحد فقط یک رأی می‌گیرد' => [
                'نظرسنجی', PollService::class,
                PollTest::class, 'test_a_unit_bound_poll_takes_only_one_vote_per_unit',
            ],
            'ساکنِ بی‌واحد رأی نمی‌دهد' => [
                'نظرسنجی', PollService::class,
                PollTest::class, 'test_a_resident_with_no_unit_cannot_vote',
            ],

            // ── مدیریت واحد ─────────────────────────────────────────────
            'قبض‌های واحد با تغییرِ ساکن از بین نمی‌روند' => [
                'مدیریت واحد', TenureService::class,
                UnitTenureTest::class, 'test_a_units_bills_survive_a_change_of_resident',
            ],
            'جابه‌جایی به واحدِ دیگر دورهٔ قبلی را با تاریخِ پایان می‌بندد' => [
                'مدیریت واحد', TenureService::class,
                UnitTenureTest::class, 'test_moving_to_another_unit_closes_the_old_period_with_an_end_date',
            ],
            'مجموعِ سهمِ مالکیت از صد درصد بیشتر نمی‌شود' => [
                'مدیریت واحد', TenureService::class,
                UnitTenureTest::class, 'test_shares_beyond_one_hundred_percent_are_refused',
            ],
        ];
    }

    /**
     * 🔴 محافظِ اصلی: تستِ ضامنِ هر قاعده باید واقعاً وجود داشته باشد.
     *
     * ⚠️ بدونِ این، سیاهه ظرفِ چند ماه به فهرستی از نام‌های مرده تبدیل
     * می‌شود که هیچ‌کدام چیزی را تضمین نمی‌کنند — و بدتر از نداشتنش است،
     * چون **ظاهرِ** پوشش می‌سازد.
     */
    #[DataProvider('rules')]
    public function test_every_rule_has_a_living_test(
        string $domain,
        string $implementation,
        string $testClass,
        string $testMethod,
    ): void {
        $this->assertTrue(
            class_exists($implementation),
            "کلاسِ اجراکننده‌ی این قاعده وجود ندارد: {$implementation}",
        );

        $this->assertTrue(class_exists($testClass), "کلاسِ تست وجود ندارد: {$testClass}");

        $this->assertTrue(
            (new ReflectionClass($testClass))->hasMethod($testMethod),
            "تستِ ضامنِ این قاعده پیدا نشد: {$testClass}::{$testMethod}",
        );

        $this->assertContains($domain, self::DOMAINS, "دامنه‌ی ناشناخته: {$domain}");
    }

    /**
     * ⚠️ هر ده دامنه‌ی خواسته‌شده باید دستِ‌کم یک قاعده داشته باشد.
     *
     * دامنه‌ی خالی یعنی همان‌جا که برنامه‌ی کار صریح خواسته بود، هیچ
     * استخراجی انجام نشده — و سیاهه‌ی ناقص، اطمینانِ کاذب می‌دهد.
     */
    public function test_no_requested_domain_is_left_empty(): void
    {
        $covered = array_unique(array_column(self::rules(), 0));
        $missing = array_values(array_diff(self::DOMAINS, $covered));

        $this->assertSame([], $missing, 'این دامنه‌ها هیچ قاعده‌ای ندارند: '.implode('، ', $missing));
    }

    /**
     * 🔴 کلاس‌های بحرانی نباید بی‌قاعده بمانند.
     *
     * ─── چرا فهرستِ صریح و نه «درصدِ پوشش» ─────────────────────────────────
     * ⚠️ درصدِ پوشش، خطِ اجراشده را می‌شمارد نه **قاعده‌ی تضمین‌شده** را.
     * کلاسی می‌تواند صددرصد پوشش داشته باشد و هیچ ادعای معناداری رویش
     * نباشد. این فهرست به‌جایش می‌پرسد: «برای این کلاس، کدام قاعده‌ی
     * کسب‌وکار نوشته و قفل شده؟»
     *
     * هر کلاسی که پول جابه‌جا می‌کند یا مرزِ دسترسی می‌کشد، اینجاست.
     */
    public function test_every_critical_class_has_at_least_one_rule(): void
    {
        $critical = [
            ChargeCalculator::class,
            PenaltyCalculator::class,
            BillGenerator::class,
            WalletService::class,
            PaymentService::class,
            PlanGate::class,
            TenureService::class,
            PollService::class,
            ComplexScope::class,
        ];

        $guarded = array_unique(array_column(self::rules(), 1));
        $missing = array_values(array_diff($critical, $guarded));

        $this->assertSame(
            [],
            $missing,
            "این کلاس‌های بحرانی هیچ قاعده‌ی ثبت‌شده‌ای ندارند:\n  ".implode("\n  ", $missing),
        );
    }

    /**
     * 🔴 حالت‌های کارخانه باید ترکیب‌های **ممکن** بسازند.
     *
     * ─── چرا این تست لازم شد ───────────────────────────────────────────────
     * ⚠️ `docs/TESTING.md` ادعا می‌کند حالت‌ها ستون‌های وابسته را جفت نگه
     * می‌دارند — ولی خرابکاریِ «جفت‌نکردنِ وضعیت با سررسید» از تور رد شد،
     * چون هیچ تستی آن ادعا را نمی‌سنجید.
     *
     * 🔴 قبضی که سررسیدش گذشته ولی «پرداخت‌شده» است، حالتِ ناممکنی است:
     * تستِ جریمه رویش سبز می‌شود بی‌آنکه چیزی را ثابت کند، چون اصلاً
     * بدهی‌ای در کار نیست.
     */
    public function test_factory_states_produce_possible_combinations(): void
    {
        /*
         * ⚠️ `make()` با شناسه‌های صریح.
         *
         * بدونشان، کارخانه‌های تودرتوی `Complex` و `Unit` واقعاً در
         * دیتابیس ساخته می‌شوند — و این کلاس عمداً `RefreshDatabase`
         * ندارد، چون سیاههٔ قواعد به دیتابیس نیازی ندارد و مهاجرت‌زدن
         * برای ۳۷ تست هزینهٔ بی‌دلیل است.
         */
        $ids = ['complex_id' => 1, 'unit_id' => 1];

        $overdue = Bill::factory()->overdue()->make($ids);

        $this->assertTrue($overdue->due_date->isPast(), 'سررسید باید گذشته باشد.');
        $this->assertSame(
            BillStatus::Unpaid->value,
            $overdue->status instanceof BillStatus ? $overdue->status->value : $overdue->status,
            'قبضِ سررسیدگذشته نمی‌تواند پرداخت‌شده باشد، وگرنه تستِ جریمه بی‌معنی است.',
        );

        $paid = Bill::factory()->paid()->make($ids);

        $this->assertSame(
            (float) $paid->total_amount,
            (float) $paid->paid_amount,
            'قبضِ پرداخت‌شده باید مبلغِ پرداختی برابرِ کل داشته باشد.',
        );

        $active = Subscription::factory()->active()->make(['complex_id' => 1, 'user_id' => 1]);

        $this->assertSame('active', $active->status);
        $this->assertTrue($active->ends_at->isFuture(), 'اشتراکِ فعال نمی‌تواند تاریخِ پایانش گذشته باشد.');
    }

    /**
     * ⚠️ سندِ استراتژی باید همان ده دامنه را نام ببرد.
     *
     * سندی که از سیاهه جدا بیفتد، خواننده را به دامنه‌ای می‌فرستد که
     * وجود ندارد — یا بدتر، دامنه‌ای را جا می‌اندازد که هست.
     */
    public function test_the_strategy_document_lists_the_same_domains(): void
    {
        $doc = (string) File::get(base_path('docs/TESTING.md'));

        foreach (self::DOMAINS as $domain) {
            $this->assertStringContainsString(
                $domain,
                $doc,
                "دامنه‌ی «{$domain}» در docs/TESTING.md نیست.",
            );
        }
    }
}
