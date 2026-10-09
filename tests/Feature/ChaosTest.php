<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Sms\SmsManager;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * وقتی چیزی بیرون از ما خراب است (R59).
 *
 * ─── شرطِ پذیرش، در یک جمله ────────────────────────────────────────────────
 * **صفر Data Corruption و صفر Double Transaction.**
 *
 * ─── 🔴 نقصِ اصلی که این کلاس پیدا کرد ─────────────────────────────────────
 * قراردادِ درگاه `verify(): ?string` بود و `null` دو معنای کاملاً متفاوت
 * داشت: «بانک گفت نه» و «به بانک نرسیدیم». کنترلر هر دو را برای همیشه
 * `Failed` می‌نوشت.
 *
 * یعنی یک timeoutِ گذرا می‌توانست پرداختی را که پولش واقعاً کسر شده ناموفق
 * ثبت کند — و به کاربر بگوید «اگر مبلغی کسر شده طی ۷۲ ساعت برمی‌گردد»،
 * تضمینی درباره‌ی چیزی که هرگز نپرسیده بودیم.
 *
 * بدتر از آن در ملت: نتیجه‌ی `bpSettleRequest` **دور ریخته می‌شد**. تسویه‌ی
 * ناموفق همچنان «موفق» گزارش می‌شد؛ قبض تسویه، کیفِ پول شارژ، و پولی که
 * هرگز capture نشده بود در دفترها نشسته بود.
 */
class ChaosTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $resident;

    private Unit $unit;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create(['type' => 'residential',
            'name' => 'مجتمع آشوب',
            'slug' => 'chaos-'.uniqid(),
            'currency' => 'toman',
            'charge_due_day' => 10,
            'payment_gateway' => 'mellat',
            'gateway_config' => ['terminal_id' => '111', 'username' => 'u', 'password' => 'p'],
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id,
            'name' => 'ساکن',
            'phone' => '09121110009',
            'role' => UserRole::Owner,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'complex_id' => $this->complex->id,
            'name' => 'مدیر',
            'phone' => '09121110010',
            'role' => UserRole::ComplexAdmin,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $this->unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => '1',
            'floor' => 1,
            'area' => 100,
            'residents_count' => 2,
            'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied,
            'uses_elevator' => true,
        ]);

        $this->resident->units()->attach($this->unit->id, [
            'complex_id' => $this->complex->id,
            'relation' => 'owner',
            'share_percent' => 100,
            'is_current' => true,
        ]);

        // درگاهِ واقعی فقط با اشتراکِ فعال کار می‌کند (GatewayManager)
        Subscription::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->resident->id,
            'plan' => SubscriptionPlan::Pro,
            'status' => 'active',
            'method' => 'receipt',
            'amount' => 100,
            'months' => 12,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① درگاهِ پرداخت پاسخ نمی‌دهد
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 قطعیِ شبکه نباید پرداخت را «ناموفق» کند.
     *
     * ⚠️ این تست پیش از R59 **قرمز** بود: پرداخت `Failed` می‌شد و کاربر
     * پیامِ «مبلغی کسر نشده» می‌گرفت، برای تراکنشی که وضعیتش را نپرسیده
     * بودیم.
     */
    public function test_a_gateway_timeout_leaves_the_payment_undecided(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        [$bill, $payment] = $this->pendingOnlinePayment();

        $response = $this->get($this->callbackUrl($payment, ['ResCode' => '0', 'SaleReferenceId' => '77']));

        $response->assertRedirect('/my-bills?payment=unknown');

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
            'پرداختی که وضعیتش دانسته نشد، ناموفق علامت خورد.',
        );

        $this->assertSame(
            0.0,
            (float) $bill->fresh()->paid_amount,
            'قبض بابتِ تراکنشی که تأییدش قطعی نیست تسویه شد.',
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.indeterminate',
            'subject_id' => $payment->id,
        ]);
    }

    /**
     * ⚠️ متقارنِ تستِ بالا، و بدونِ آن، آن یکی پوچ است.
     *
     * اگر «هر چیزی» نامشخص شمرده شود، دیگر هیچ پرداختی ناموفق نمی‌شود و
     * صفِ بررسیِ دستی بی‌نهایت بزرگ می‌شود. ردِ صریحِ بانک باید همچنان
     * `Failed` بماند.
     */
    public function test_an_explicit_decline_is_still_a_failure(): void
    {
        // بانک پاسخ می‌دهد و پاسخش «نه» است
        Http::fake(['*' => Http::response('<return>12</return>', 200)]);

        [$bill, $payment] = $this->pendingOnlinePayment();

        $this->get($this->callbackUrl($payment, ['ResCode' => '0', 'SaleReferenceId' => '77']))
            ->assertRedirect('/my-bills?payment=failed');

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(0.0, (float) $bill->fresh()->paid_amount);
    }

    /**
     * 🔴 خطرناک‌ترین حالت: تأیید شد، تسویه نه.
     *
     * ⚠️ پیش از R59 نتیجه‌ی `bpSettleRequest` دور ریخته می‌شد، پس این
     * سناریو **«موفق»** گزارش می‌شد: قبض تسویه می‌شد و کیفِ پول شارژ —
     * بابتِ پولی که در پروتکلِ ملت پس از پنجره‌ی تسویه برگشت می‌خورد و
     * هرگز به حسابِ مجتمع نمی‌نشست.
     */
    public function test_a_verified_but_unsettled_payment_is_never_called_success(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            // اولی bpVerifyRequest است و موفق؛ دومی bpSettleRequest که به آن نمی‌رسیم
            if ($calls === 1) {
                return Http::response('<return>0</return>', 200);
            }

            throw new ConnectionException('cURL error 28: timeout');
        });

        [$bill, $payment] = $this->pendingOnlinePayment();

        $this->get($this->callbackUrl($payment, ['ResCode' => '0', 'SaleReferenceId' => '77']))
            ->assertRedirect('/my-bills?payment=unknown');

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
            'تراکنشی که تسویه‌اش قطعی نیست، موفق ثبت شد.',
        );

        $this->assertSame(
            0.0,
            (float) $bill->fresh()->paid_amount,
            'قبض بابتِ پولی که capture نشده تسویه شد.',
        );

        $this->assertSame(
            0.0,
            app(WalletService::class)->balance($this->resident, $this->unit->complex_id),
            'کیفِ پول بابتِ پولی که capture نشده شارژ شد.',
        );
    }

    /**
     * 🔴 و همان حالت وقتی بانک **پاسخ می‌دهد** ولی تسویه را قبول نمی‌کند.
     *
     * ⚠️ این تست را خرابکاری به من تحمیل کرد.
     *
     * تستِ بالا فقط timeoutِ گامِ تسویه را می‌سنجید — و آن مسیر از `call()`
     * می‌گذرد که به‌هرحال استثنا می‌دهد. پس وقتی **بررسیِ کدِ تسویه** را
     * برداشتم، تستِ بالا همچنان سبز ماند: ادعایی می‌کرد که اثباتش نمی‌کرد.
     *
     * اینجا بانک با HTTP 200 و یک کدِ ناموفق پاسخ می‌دهد — تنها راهی که
     * فقط و فقط همان بررسی را می‌سنجد.
     */
    public function test_a_settle_the_bank_refuses_is_not_a_success(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            // bpVerifyRequest موفق، bpSettleRequest ناموفق — هر دو با پاسخِ سالم
            return Http::response('<return>'.($calls === 1 ? '0' : '17').'</return>', 200);
        });

        [$bill, $payment] = $this->pendingOnlinePayment();

        $this->get($this->callbackUrl($payment, ['ResCode' => '0', 'SaleReferenceId' => '77']))
            ->assertRedirect('/my-bills?payment=unknown');

        $this->assertSame(
            PaymentStatus::Pending,
            $payment->fresh()->status,
            'تسویه‌ای که بانک قبولش نکرد، «موفق» ثبت شد.',
        );

        $this->assertSame(0.0, (float) $bill->fresh()->paid_amount);
        $this->assertSame(0.0, app(WalletService::class)->balance($this->resident, $this->unit->complex_id));
    }

    /**
     * ⚠️ صفر Double Transaction، حتی وقتی درگاه بی‌ثبات است.
     *
     * بانک بازگشت را دوبار می‌فرستد (سرور‌به‌سرور و بازگشتِ مرورگر). اگر
     * تلاشِ اول نامشخص بماند و دومی موفق شود، مبلغ باید **یک بار** روی
     * قبض بنشیند.
     */
    public function test_a_flaky_gateway_never_settles_the_same_payment_twice(): void
    {
        [$bill, $payment] = $this->pendingOnlinePayment(300000);

        /*
         * ⚠️ یک fake با کلید، نه دو تا.
         *
         * `Http::fake()`ِ دوم جایگزینِ اولی نمی‌شود؛ به فهرست **اضافه**
         * می‌شود و اولی همچنان اول امتحان می‌شود. نسخه‌ی اولِ همین تست دو
         * بار صدایش می‌زد و بانک هرگز «برنمی‌گشت» — تست به دلیلِ اشتباه
         * قرمز بود.
         */
        $down = true;

        Http::fake(function () use (&$down) {
            if ($down) {
                throw new ConnectionException('cURL error 28: timeout');
            }

            return Http::response('<return>0</return>', 200);
        });

        // تلاشِ اول: قطعی
        $this->get($this->callbackUrl($payment, ['ResCode' => '0', 'SaleReferenceId' => '77']));

        // بانک برگشته؛ دو بازگشتِ پیاپی (سرور‌به‌سرور و مرورگر)
        $down = false;
        $this->get($this->callbackUrl($payment, ['ResCode' => '0', 'SaleReferenceId' => '77']));
        $this->get($this->callbackUrl($payment, ['ResCode' => '0', 'SaleReferenceId' => '77']));

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);

        $this->assertSame(
            300000.0,
            (float) $bill->fresh()->paid_amount,
            'مبلغ بیش از یک بار روی قبض نشست.',
        );

        $this->assertSame(
            1,
            DB::table('payment_allocations')->where('payment_id', $payment->id)->count(),
            'برای یک پرداخت بیش از یک تخصیص ثبت شد.',
        );
    }

    /**
     * 🔴 کارِ شبانه نباید رفعِ R59 را خنثی کند.
     *
     * ⚠️ `subscriptions:maintain` هر تراکنشِ آنلاینِ سه‌ساعته‌ی `pending` را
     * `failed` می‌کرد با یادداشتِ «نیمه‌تمام رها شد» — جمله‌ای که برای
     * تراکنشِ نامشخص ممکن است دروغ باشد. و چون شبانه و خودکار است،
     * هیچ‌کس نمی‌دید.
     */
    public function test_the_nightly_job_does_not_close_an_undecided_checkout(): void
    {
        $undecided = Subscription::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->resident->id,
            'plan' => SubscriptionPlan::Pro,
            'status' => 'pending',
            'method' => 'online',
            'amount' => 500000,
            'months' => 1,
        ]);

        $abandoned = Subscription::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->resident->id,
            'plan' => SubscriptionPlan::Pro,
            'status' => 'pending',
            'method' => 'online',
            'amount' => 500000,
            'months' => 1,
        ]);

        /*
         * ⚠️ `created_at` را نمی‌شود در `create()` داد: Eloquent مهرِ زمان
         * را خودش بازمی‌نویسد. نسخه‌ی اولِ این تست همین اشتباه را داشت و
         * هیچ‌کدام از دو ردیف «قدیمی» نبودند، پس دستور هیچ‌کدام را
         * نمی‌بست و تست به دلیلِ اشتباه قرمز بود.
         */
        Subscription::whereIn('id', [$undecided->id, $abandoned->id])
            ->update(['created_at' => now()->subHours(6)]);

        AuditLog::create([
            'complex_id' => $this->complex->id,
            'action' => 'subscription.indeterminate',
            'subject_type' => Subscription::class,
            'subject_id' => $undecided->id,
            'description' => 'تست',
            'created_at' => now(),
        ]);

        $this->artisan('subscriptions:maintain')->assertExitCode(0);

        $this->assertSame(
            'pending',
            $undecided->fresh()->status,
            'خریدی که سرنوشتش دانسته نشده بود، «رهاشده» علامت خورد.',
        );

        $this->assertSame(
            'failed',
            $abandoned->fresh()->status,
            'خریدِ واقعاً رهاشده بسته نشد؛ استثنا بیش از حد گسترده است.',
        );
    }

    /** ⚠️ ردیفِ نامشخص باید در گزارشِ اپراتور دیده شود، وگرنه فقط جابه‌جا شده. */
    public function test_undecided_payments_are_reported_to_the_operator(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        [, $payment] = $this->pendingOnlinePayment();
        $this->get($this->callbackUrl($payment, ['ResCode' => '0', 'SaleReferenceId' => '77']));

        Payment::withoutGlobalScopes()->whereKey($payment->id)
            ->update(['created_at' => now()->subHour()]);

        $this->artisan('payments:stale')->assertExitCode(1);
    }

    /** پرداختی که کاربر وسطِ راه رهایش کرده، آژیر نیست. */
    public function test_a_merely_abandoned_payment_is_not_an_alarm(): void
    {
        [, $payment] = $this->pendingOnlinePayment();

        Payment::withoutGlobalScopes()->whereKey($payment->id)
            ->update(['created_at' => now()->subHour()]);

        $this->artisan('payments:stale')->assertExitCode(0);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② سرویسِ پیامک قطع است
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 کاربر نباید بابتِ خرابیِ سرویسِ پیامک جریمه شود.
     *
     * ⚠️ ردیفِ کد پیش از ارسال ساخته می‌شود و «فاصله‌ی ارسالِ مجدد» را
     * مسلح می‌کرد. یعنی پس از یک ارسالِ ناموفق، کاربر شصت ثانیه پیامِ
     * «کمی صبر کنید» می‌گرفت — برای کدی که هرگز نرسیده بود.
     */
    public function test_a_failed_sms_does_not_lock_the_user_out_of_retrying(): void
    {
        $otp = new OtpService($this->failingSms());

        $first = $otp->request('09121110009');

        $this->assertFalse($first['ok']);
        $this->assertDatabaseCount('otp_codes', 0);

        // بدونِ رفعِ R59 اینجا «برای ارسال مجدد کد کمی صبر کنید» می‌آمد
        $second = $otp->request('09121110009');

        $this->assertStringNotContainsString(
            'صبر کنید',
            (string) $second['error'],
            'خرابیِ سرویسِ پیامک، کاربر را پشتِ فاصله‌ی ارسالِ مجدد حبس کرد.',
        );
    }

    /**
     * ⚠️ متقارنِ تستِ بالا.
     *
     * بدونِ این، «همیشه ردیف را پاک کن» هم سبز می‌شد — و آن‌وقت فاصله‌ی
     * ارسالِ مجدد اصلاً وجود نداشت و هرکس می‌توانست پشتِ‌سرِ‌هم پیامک
     * بفرستد.
     */
    public function test_a_successful_sms_still_arms_the_resend_cooldown(): void
    {
        $otp = new OtpService($this->workingSms());

        $this->assertTrue($otp->request('09121110009')['ok']);
        $this->assertDatabaseCount('otp_codes', 1);

        $this->assertStringContainsString(
            'صبر کنید',
            (string) $otp->request('09121110009')['error'],
            'فاصله‌ی ارسالِ مجدد از بین رفته است.',
        );
    }

    /** ⚠️ در قطعیِ پیامک هم کد نباید بیرون برود. */
    public function test_a_failed_sms_never_leaks_the_code(): void
    {
        $result = (new OtpService($this->failingSms()))->request('09121110009');

        $this->assertNull($result['dev_code']);

        $this->assertDoesNotMatchRegularExpression(
            '/\b\d{6}\b/',
            (string) $result['error'],
            'پیامِ خطا چیزی شبیهِ کدِ شش‌رقمی دارد.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ ذخیره‌ساز خراب است
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ فایلِ گم‌شده باید ۴۰۴ بدهد، نه ۵۰۰.
     *
     * تفاوتش این است که ۵۰۰ به Sentry می‌رود و شبانه آژیر می‌کشد، در حالی
     * که مسئله‌ی واقعی یک فایلِ نبوده است — و مهم‌تر، ۵۰۰ رد‌پای استثنا را
     * به کاربر نشان می‌دهد.
     */
    public function test_a_vanished_receipt_is_a_clean_404(): void
    {
        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'user_id' => $this->resident->id,
            'amount' => 1000,
            'method' => PaymentMethod::Receipt,
            'status' => PaymentStatus::Pending,
            'period' => '1405-04',
            'receipt_path' => 'receipts/'.$this->complex->id.'/nist.jpg',
        ]);

        $this->actingAs($this->manager)
            ->get('/api/v1/payments/'.$payment->id.'/receipt')
            ->assertNotFound();
    }

    /** ⚠️ نبودِ فایل نباید فهرستِ قبوض را هم بخواباند. */
    public function test_the_bill_list_survives_a_broken_storage(): void
    {
        $this->makeBill();

        $this->actingAs($this->resident)
            ->getJson('/api/v1/my-bills')
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ دیتابیسِ کُند — و سرویسِ بیرونیِ کُند
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 هیچ تماسِ HTTPی بیرونی نباید داخلِ تراکنشِ دیتابیس باشد.
     *
     * ─── چرا این بدترین الگوی ممکن است ─────────────────────────────────────
     * تراکنشِ باز یعنی قفلِ ردیف. اگر وسطش منتظرِ بانک بمانیم، آن قفل به
     * اندازه‌ی timeoutِ شبکه — تا سی ثانیه — نگه داشته می‌شود.
     *
     * نتیجه‌اش زنجیره‌ای است: بانک کُند می‌شود ← قفل‌ها طولانی می‌شوند ←
     * درخواست‌های دیگر پشتِ قفل صف می‌کشند ← کارگرهای PHP-FPM تمام
     * می‌شوند ← **کلِ سایت** می‌خوابد، نه فقط پرداخت.
     *
     * کامنتِ `GatewayController` همین را می‌گوید و رعایتش کرده. این تست
     * نمی‌گذارد کسی روزی برای «ساده‌تر شدنِ کد» جابه‌جایش کند.
     */
    public function test_no_outbound_http_call_sits_inside_a_database_transaction(): void
    {
        foreach ($this->phpSources() as $file) {
            $code = (string) file_get_contents($file);

            foreach ($this->transactionBodies($code) as $body) {
                $this->assertStringNotContainsString(
                    'Http::',
                    $body,
                    'در «'.str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).'» یک تماسِ HTTP '
                    .'داخلِ DB::transaction است. قفلِ ردیف به اندازه‌ی timeoutِ شبکه باز می‌ماند و '
                    .'کندیِ سرویسِ بیرونی به قطعیِ کلِ سامانه تبدیل می‌شود.',
                );
            }
        }
    }

    /**
     * ⚠️ هر تماسِ بیرونی باید **هر دو** سقف را داشته باشد.
     *
     * `timeout` کلِ درخواست را می‌بندد، ولی `connectTimeout` است که در
     * برابرِ میزبانِ مرده کارساز است: بدونِ آن، سرورِ خاموشِ بانک یعنی سی
     * ثانیه اشغالِ کارگر به‌جای سه ثانیه — و در ساعتِ شلوغی همین تفاوت،
     * تفاوتِ «پرداخت کار نمی‌کند» و «سایت بالا نمی‌آید» است.
     */
    public function test_every_outbound_call_bounds_both_timeouts(): void
    {
        $found = 0;

        foreach ($this->phpSources() as $file) {
            $code = (string) file_get_contents($file);

            if (! str_contains($code, 'Http::')) {
                continue;
            }

            $name = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            foreach ($this->httpChains($code) as $chain) {
                $found++;

                $this->assertStringContainsString('timeout(', $chain, "تماسِ بیرونی در «{$name}» سقفِ زمانی ندارد.");
                $this->assertStringContainsString('connectTimeout(', $chain, "تماسِ بیرونی در «{$name}» سقفِ اتصال ندارد.");
            }
        }

        $this->assertGreaterThanOrEqual(6, $found, 'الگو تماس‌های بیرونی را پیدا نکرد؛ خودِ تست خراب است.');
    }

    /**
     * سقفِ زمانیِ کوئریِ خواندنی روی موتورِ واقعی.
     *
     * ⚠️ SQLite این متغیر را ندارد، پس این ادعا فقط در jobِ MySQLِ CI
     * معنا دارد و اینجا صریح رد می‌شود — نه اینکه سبزِ بی‌معنا بماند.
     */
    public function test_the_read_timeout_is_really_applied_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('فقط روی MySQL معنا دارد؛ در jobِ «قیدهای دیتابیس روی MySQL» اجرا می‌شود.');
        }

        $expected = (int) config('database.read_timeout_ms');
        $this->assertGreaterThan(0, $expected, 'سقفِ کوئری خاموش است.');

        $actual = (int) DB::selectOne('SELECT @@SESSION.max_execution_time AS v')->v;

        $this->assertSame(
            $expected,
            $actual,
            'سقفِ زمانیِ کوئری روی اتصالِ واقعی اعمال نشده است.',
        );
    }

    /**
     * ⚠️ محافظِ عضویت در jobِ MySQL.
     *
     * تستِ سقفِ زمانیِ کوئری روی SQLite رد می‌شود (skip). اگر این کلاس از
     * jobِ MySQL بیرون بیفتد، آن ادعا **هیچ‌جا** سنجیده نمی‌شود و کسی
     * نمی‌فهمد — همان الگویی که در R55 با `SecretHandlingTest` یاد گرفتیم.
     */
    public function test_this_class_also_runs_against_mysql_in_ci(): void
    {
        $workflow = (string) preg_replace(
            '/^\s*#.*$/m',
            '',
            (string) file_get_contents(base_path('.github/workflows/ci.yml')),
        );

        $this->assertStringContainsString(
            'ChaosTest',
            $workflow,
            'jobِ MySQL این کلاس را نمی‌دواند؛ سقفِ زمانیِ کوئری هیچ‌جا سنجیده نمی‌شود.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  کمکی‌ها
    // ─────────────────────────────────────────────────────────────────────

    private function makeBill(float $total = 500000): Bill
    {
        return Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => '1405-04',
            'total_amount' => $total,
            'paid_amount' => 0,
            'status' => BillStatus::Unpaid,
            'issued_at' => now(),
        ]);
    }

    /**
     * قبض و پرداختِ آنلاینِ در انتظار — بدونِ عبور از مرحله‌ی «رفتن به بانک».
     *
     * @return array{0: Bill, 1: Payment}
     */
    private function pendingOnlinePayment(float $total = 500000): array
    {
        $bill = $this->makeBill($total);

        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'bill_id' => $bill->id,
            'user_id' => $this->resident->id,
            'amount' => $total,
            'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Pending,
            'period' => $bill->period,
            'gateway' => 'mellat',
            'ref_id' => 'REF-'.$bill->id,
        ]);

        return [$bill, $payment];
    }

    /** @param  array<string, string>  $callback */
    private function callbackUrl(Payment $payment, array $callback): string
    {
        return '/pay/callback/'.$payment->id.'?'.http_build_query($callback);
    }

    private function failingSms(): SmsManager
    {
        $sms = \Mockery::mock(SmsManager::class);
        $sms->shouldReceive('sendOtp')->andReturn(false);
        $sms->shouldReceive('isLogDriver')->andReturn(false);

        return $sms;
    }

    private function workingSms(): SmsManager
    {
        $sms = \Mockery::mock(SmsManager::class);
        $sms->shouldReceive('sendOtp')->andReturn(true);
        $sms->shouldReceive('isLogDriver')->andReturn(false);

        return $sms;
    }

    /** @return list<string> */
    private function phpSources(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path()),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * زنجیره‌ی هر فراخوانیِ `Http::…;` — با شمارشِ پرانتز، نه regex.
     *
     * ⚠️ نسخه‌ی اول `Http::(.+?);` بود و روی همان اولین کدی که سنجید
     * شکست: هدرِ `'Content-Type' => 'text/xml; charset=utf-8'` یک `;`
     * داخلِ رشته دارد و زنجیره همان‌جا بریده می‌شد. یعنی تست می‌گفت
     * «سقفِ زمانی ندارد» درباره‌ی کدی که داشت.
     *
     * ⚠️ و اگر جهتِ خطا برعکس بود — بریدن جایی که سقف *هست* — تست
     * برای همیشه سبز و پوچ می‌ماند.
     *
     * @return list<string>
     */
    private function httpChains(string $code): array
    {
        $chains = [];
        $offset = 0;

        while (($start = strpos($code, 'Http::', $offset)) !== false) {
            $cursor = $start;
            $depth = 0;
            $quote = null;

            while ($cursor < strlen($code)) {
                $char = $code[$cursor];

                if ($quote !== null) {
                    if ($char === $quote && $code[$cursor - 1] !== chr(92)) {
                        $quote = null;
                    }
                } elseif ($char === "'" || $char === '"') {
                    $quote = $char;
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ';' && $depth === 0) {
                    break;
                }

                $cursor++;
            }

            $chains[] = substr($code, $start, $cursor - $start);
            $offset = $cursor + 1;
        }

        return $chains;
    }

    /**
     * بدنه‌ی هر `DB::transaction(...)` — با شمارشِ آکولاد، نه regex.
     *
     * ⚠️ یک regexِ ساده روی `transaction\(.*?\)` در اولین `)`ِ داخلی
     * می‌ایستد و بدنه را از دست می‌دهد؛ آن‌وقت تست همیشه سبز است و هیچ
     * چیزی نمی‌گیرد. همان تلهٔ SSRFِ R56.
     *
     * @return list<string>
     */
    private function transactionBodies(string $code): array
    {
        $bodies = [];
        $offset = 0;

        while (($start = strpos($code, 'DB::transaction(', $offset)) !== false) {
            $cursor = $start + strlen('DB::transaction(');
            $depth = 1;

            while ($cursor < strlen($code) && $depth > 0) {
                $depth += match ($code[$cursor]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                $cursor++;
            }

            $bodies[] = substr($code, $start, $cursor - $start);
            $offset = $cursor;
        }

        return $bodies;
    }
}
