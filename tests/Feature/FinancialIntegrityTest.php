<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * یکپارچگیِ هسته‌ی مالی (R15).
 *
 * ─── محدودیتی که باید بدانید ───────────────────────────────────────────────
 * SQLiteِ تست `SELECT ... FOR UPDATE` را نادیده می‌گیرد، پس **همزمانیِ واقعی
 * را نمی‌شود اینجا شبیه‌سازی کرد**. آنچه این تست‌ها می‌سنجند، لایه‌ی دومِ
 * محافظت است: بازخوانیِ وضعیت داخلِ تراکنش، که حتی بی‌قفل هم تسویه‌ی دوباره
 * را می‌گیرد. خودِ قفل با تستِ معماری (وجودش در کد) تضمین می‌شود.
 */
class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unit;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $this->resident = User::factory()->create([
            'role' => UserRole::Owner,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);
    }

    /* ── تسویه‌ی دوباره ─────────────────────────────────────────────────── */

    /**
     * تاییدِ دوباره‌ی یک رسید نباید دوبار روی بدهی بنشیند.
     *
     * سناریوی واقعی: مدیر روی «تایید» دوبار کلیک می‌کند، یا درخواست
     * تکرار می‌شود. پیش از R15 هر دو اجرا تخصیص می‌دادند و قبض دوبار
     * پرداخت‌شده حساب می‌شد.
     */
    public function test_settling_the_same_payment_twice_does_not_double_credit(): void
    {
        $bill = $this->makeBill(500000);
        $payment = $this->makePayment($bill, 500000);

        $service = app(PaymentService::class);
        $service->settle($payment);
        $service->settle($payment);

        $this->assertSame('500000.00', $bill->fresh()->paid_amount);
        $this->assertSame(1, PaymentAllocation::where('payment_id', $payment->id)->count());
    }

    public function test_a_second_settle_does_not_send_a_second_notification(): void
    {
        $bill = $this->makeBill(500000);
        $payment = $this->makePayment($bill, 500000);

        $service = app(PaymentService::class);
        $service->settle($payment);
        $service->settle($payment);

        // اعلانِ «تایید شد» فقط یک بار — وگرنه کاربر گیج می‌شود
        $this->assertSame(1, $this->resident->notifications()->count());
    }

    /* ── دفترِ تخصیص ────────────────────────────────────────────────────── */

    public function test_every_allocation_is_recorded_in_the_ledger(): void
    {
        $bill = $this->makeBill(300000);
        $payment = $this->makePayment($bill, 300000);

        app(PaymentService::class)->settle($payment);

        $allocation = PaymentAllocation::where('payment_id', $payment->id)->first();

        $this->assertNotNull($allocation, 'هر تخصیص باید ردیفِ دفتر داشته باشد');
        $this->assertSame($bill->id, $allocation->bill_id);
        $this->assertSame('300000.00', $allocation->amount);
    }

    /**
     * پرداختی که بین چند قبض پخش می‌شود، باید ردِ هر تکه را نگه دارد.
     *
     * این همان چیزی بود که پیش از R15 اصلاً ثبت نمی‌شد: `paid_amount` بالا
     * می‌رفت ولی معلوم نبود کدام پرداخت چقدرش را پوشانده.
     */
    public function test_a_payment_split_across_bills_records_each_piece(): void
    {
        $older = $this->makeBill(200000, '1405-01', now()->subDays(20));
        $newer = $this->makeBill(300000, '1405-02', now()->subDays(5));

        // پرداختِ بدونِ قبضِ مشخص: باید از قدیمی‌ترین شروع کند
        $payment = $this->makePayment(null, 400000);
        app(PaymentService::class)->settle($payment);

        $allocations = PaymentAllocation::where('payment_id', $payment->id)
            ->pluck('amount', 'bill_id');

        $this->assertSame('200000.00', $allocations[$older->id]);
        $this->assertSame('200000.00', $allocations[$newer->id]);
    }

    /**
     * قیدِ اصلیِ دفتر: جمعِ تخصیص‌های هر قبض باید با `paid_amount` بخواند.
     *
     * اگر این دو از هم واگرا شوند، یعنی یا پولی ثبت شده که ردی ندارد، یا ردی
     * هست که پولی پشتش نیست — هر دو یعنی حساب‌ها قابل اتکا نیستند.
     */
    public function test_ledger_totals_reconcile_with_the_bill(): void
    {
        $bill = $this->makeBill(500000);

        app(PaymentService::class)->settle($this->makePayment($bill, 200000));
        app(PaymentService::class)->settle($this->makePayment($bill, 300000));

        $ledgerTotal = PaymentAllocation::where('bill_id', $bill->id)->sum('amount');

        $this->assertEquals($bill->fresh()->paid_amount, $ledgerTotal);
    }

    /* ── جلوگیری از رسیدِ تکراری ────────────────────────────────────────── */

    public function test_the_pending_receipt_guard_runs_inside_a_transaction(): void
    {
        /*
         * همزمانیِ واقعی در SQLite قابل شبیه‌سازی نیست، پس به‌جای زمان‌بندی،
         * وجودِ محافظت در کد سنجیده می‌شود: بررسی و ساخت باید داخلِ یک
         * تراکنش با قفل باشند، نه پشتِ سرِ هم و بی‌محافظ.
         */
        $source = File::get(app_path('Http/Controllers/Api/PaymentController.php'));

        $transactionStart = strpos($source, 'DB::transaction');
        $guardPosition = strpos($source, "where('status', PaymentStatus::Pending)");

        $this->assertNotFalse($transactionStart, 'ساختِ رسید باید در تراکنش باشد');
        $this->assertNotFalse($guardPosition);
        $this->assertLessThan(
            $guardPosition,
            $transactionStart,
            'بررسیِ رسیدِ در انتظار باید **داخلِ** تراکنش باشد، نه پیش از آن',
        );
    }

    public function test_financial_writes_take_row_locks(): void
    {
        $service = File::get(app_path('Services/Payment/PaymentService.php'));

        /*
         * `paid_amount` خوانده، در PHP جمع، و دوباره نوشته می‌شود. بدونِ قفل،
         * دو تاییدِ هم‌زمان روی یک واحد یکی را بی‌صدا پاک می‌کنند.
         */
        $this->assertStringContainsString(
            'lockForUpdate',
            $service,
            'تخصیصِ پرداخت باید ردیف‌ها را قفل کند',
        );
    }

    public function test_financial_transactions_retry_on_deadlock(): void
    {
        $service = File::get(app_path('Services/Payment/PaymentService.php'));

        // قفل‌گرفتن یعنی احتمالِ بن‌بست؛ بدونِ تلاشِ دوباره، کاربر خطا می‌بیند
        $this->assertStringContainsString('attempts: 3', $service);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function makeBill(float $total, string $period = '1405-01', $due = null): Bill
    {
        return Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => $period,
            'total_amount' => $total,
            'status' => 'unpaid',
            'due_date' => $due ?? now()->addDays(10),
        ]);
    }

    private function makePayment(?Bill $bill, float $amount): Payment
    {
        return Payment::create([
            'complex_id' => $this->complex->id,
            'bill_id' => $bill?->id,
            'unit_id' => $this->unit->id,
            'user_id' => $this->resident->id,
            'amount' => $amount,
            'method' => 'receipt',
            'status' => PaymentStatus::Pending,
        ]);
    }
}
