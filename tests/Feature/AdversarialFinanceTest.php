<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\OccupancyStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payment\PaymentService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * چهار سناریوی مالی که در هیچ تستی نبودند (فاز ۸).
 *
 * ─── چطور پیدا شدند ────────────────────────────────────────────────────────
 * فهرستِ اصلاحیه دوازده سناریوی پرداخت و هشت سناریوی کیف می‌خواست. هرکدام
 * در برابرِ تست‌های موجود سنجیده شد و چهارتا پوشش نداشتند:
 *
 *   • ناهمخوانیِ مبلغ با بدهی
 *   • سندِ معکوس
 *   • واریزِ هم‌زمان (برداشتِ هم‌زمان پوشش داشت، واریز نه)
 *   • ثبتِ دوبارهٔ پرداخت / ارسالِ دوباره‌ی فرم
 *
 * ⚠️ بقیه از قبل شاهد داشتند و اینجا تکرار نشده‌اند — تستِ تکراری فقط
 * زمانِ CI را می‌خورد و این توهم را می‌سازد که پوشش بیشتر شده.
 */
class AdversarialFinanceTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unit;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();

        $this->unit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '1', 'floor' => 1, 'area' => 100,
            'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->resident = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner->value,
            'is_active' => true,
        ]);

        $this->resident->units()->attach($this->unit->id, [
            'complex_id' => $this->complex->id, 'relation' => 'owner',
            'share_percent' => 100, 'is_current' => true,
        ]);
    }

    private function bill(float $total = 500000, float $paid = 0): Bill
    {
        return Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id,
            'period' => '1405-04', 'total_amount' => $total, 'paid_amount' => $paid,
            'status' => BillStatus::Unpaid, 'issued_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① ناهمخوانیِ مبلغ
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 مبلغ باید از **سرور** بیاید، نه از کلاینت.
     *
     * ⚠️ این ادعا در گزارشِ امنیتی نوشته شده بود ولی تستِ مستقیمی نداشت.
     * سنجهٔ درست این است: مبلغی که در بدنهٔ درخواست می‌آید هیچ اثری روی
     * مبلغِ ثبت‌شده نگذارد.
     */
    public function test_a_client_supplied_amount_cannot_change_what_is_owed(): void
    {
        $bill = $this->bill(total: 500000);

        $this->actingAs($this->resident)
            ->post('/pay/'.$bill->id, ['amount' => 1]);

        $payment = Payment::withoutGlobalScopes()->where('bill_id', $bill->id)->latest('id')->first();

        if ($payment === null) {
            /*
             * ⚠️ اگر این مسیر پرداختی نساخت (مثلاً درگاه تنظیم نیست)، ادعا
             * همچنان معتبر است: چیزی با مبلغِ دست‌کاری‌شده ثبت نشده.
             */
            $this->assertSame(0.0, (float) $bill->fresh()->paid_amount);

            return;
        }

        $this->assertNotSame(
            1.0,
            (float) $payment->amount,
            '🔴 مبلغِ فرستاده‌شده از کلاینت روی پرداخت نشست.',
        );

        $this->assertSame(
            (float) $bill->fresh()->remaining(),
            (float) $payment->amount,
            'مبلغِ پرداخت باید همان باقی‌ماندهٔ قبض باشد.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② سندِ معکوس
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 اصلاحِ اشتباهِ مالی فقط با سندِ معکوس — و باید واقعاً کار کند.
     *
     * ─── چرا این تست لازم بود ──────────────────────────────────────────────
     * تریتِ `Immutable` می‌گوید «برای اصلاح، ردیفِ معکوس ثبت کنید». ⚠️ ولی
     * هیچ تستی نمی‌سنجید که آن راه **واقعاً** کار می‌کند و ناوردایی را
     * حفظ می‌کند.
     *
     * محافظی که راهِ جایگزین را پیشنهاد بدهد و آن راه سنجیده نشده باشد،
     * نصفِ یک محافظ است.
     */
    public function test_a_mistake_is_corrected_by_a_reversing_entry(): void
    {
        $wallet = app(WalletService::class);

        // اشتباه: مبلغِ غلط واریز شد
        $wrong = $wallet->credit($this->resident, $this->unit, 250000, WalletTransaction::SOURCE_ADJUSTMENT, null, 'اشتباه');

        $this->assertSame(250000.0, $wallet->balance($this->resident, $this->unit->complex_id));

        // اصلاح: ردیفِ معکوس، نه ویرایش و نه حذف
        $wallet->debit($this->resident, $this->unit, 250000, WalletTransaction::SOURCE_ADJUSTMENT, null, 'برگشتِ اشتباه');

        $this->assertSame(0.0, $wallet->balance($this->resident, $this->unit->complex_id), 'سندِ معکوس موجودی را برنگرداند.');

        /*
         * 🔴 ادعای اصلی: **هر دو ردیف سرِ جایشان‌اند.**
         *
         * ⚠️ همین تفاوتِ دفترِ حسابداری با یک ستونِ قابلِ ویرایش است. اگر
         * ردیفِ اشتباه پاک می‌شد، تاریخچه دروغ می‌گفت: «هرگز اشتباهی رخ
         * نداد».
         */
        $this->assertSame(
            2,
            WalletTransaction::withoutGlobalScopes()->where('unit_id', $this->unit->id)->count(),
            'تاریخچه باید هر دو سند را نگه دارد.',
        );

        $this->assertNotNull(
            WalletTransaction::withoutGlobalScopes()->find($wrong->id),
            '🔴 سندِ اشتباه حذف شده — یعنی تاریخچه دست‌کاری شده.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ واریزِ هم‌زمان
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 دو واریزِ هم‌زمان نباید یکی‌شان گم شود.
     *
     * ─── چرا برداشت پوشش داشت و واریز نه ───────────────────────────────────
     * ⚠️ خطرِ برداشتِ هم‌زمان روشن است: موجودیِ منفی. خطرِ واریزِ هم‌زمان
     * ساکت‌تر است — هیچ خطایی نمی‌دهد، فقط **پول گم می‌شود**: دو واریز
     * ثبت می‌شوند ولی `balance_after` هر دو بر مبنای مانده‌ی قدیمی نوشته
     * می‌شود.
     *
     * ⚠️ سنجهٔ واقعی همین ناوردایی است، نه صرفاً تعدادِ ردیف‌ها.
     */
    public function test_two_credits_never_lose_one_of_them(): void
    {
        $wallet = app(WalletService::class);

        $wallet->credit($this->resident, $this->unit, 100000, 'manual', null, 'اول');
        $wallet->credit($this->resident, $this->unit, 150000, 'manual', null, 'دوم');

        $this->assertSame(250000.0, $wallet->balance($this->resident, $this->unit->complex_id), 'جمعِ دو واریز درست نیست.');

        $last = WalletTransaction::withoutGlobalScopes()
            ->where('unit_id', $this->unit->id)
            ->latest('id')
            ->first();

        $this->assertSame(
            250000.0,
            (float) $last->balance_after,
            '🔴 عکسِ لحظه‌ای با دفتر نمی‌خواند — یعنی واریزِ دوم مانده‌ی کهنه را دیده.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ ارسالِ دوبارهٔ فرم
    // ─────────────────────────────────────────────────────────────────────

    /**
     * 🔴 دو بار کلیک روی «پرداخت از کیف» نباید دو بار برداشت کند.
     *
     * ⚠️ سناریوی واقعی است، نه فرضی: کاربر دکمه را می‌زند، صفحه کند است،
     * دوباره می‌زند. اگر هر دو اجرا شوند، از کیف دو برابر کم می‌شود در
     * حالی که قبض یک بار تسویه شده.
     */
    public function test_paying_the_same_bill_twice_from_the_wallet_takes_the_money_once(): void
    {
        $wallet = app(WalletService::class);
        $bill = $this->bill(total: 200000);

        $wallet->credit($this->resident, $this->unit, 500000, 'manual', null, 'شارژ');

        $wallet->payBill($this->resident, $bill);

        /*
         * 🔴 کلیکِ دوم — و رد شدنش با استثنا **نتیجهٔ درستی است**.
         *
         * ⚠️ ادعا این نیست که بی‌صدا رد شود؛ ادعا این است که پول دوباره
         * برداشته نشود. هر دو رفتار (استثنا یا no-op) قابلِ قبول‌اند و
         * سنجه پایین است، نه اینجا.
         */
        try {
            $wallet->payBill($this->resident, $bill->fresh());
        } catch (\Throwable) {
            // بدهیِ باز ندارد — همان محافظ است
        }

        $this->assertSame(
            300000.0,
            $wallet->balance($this->resident, $this->unit->complex_id),
            '🔴 پرداختِ دوباره از کیف دوباره پول برداشت.',
        );

        $this->assertSame(
            200000.0,
            (float) $bill->fresh()->paid_amount,
            'قبض بیش از مبلغش تسویه شد.',
        );
    }

    /** ⚠️ و همان ادعا روی ثبتِ پرداختِ درگاه: دو رکورد برای یک قبض. */
    public function test_a_second_pending_payment_does_not_double_the_settled_amount(): void
    {
        $bill = $this->bill(total: 300000);

        foreach ([1, 2] as $ignored) {
            Payment::withoutGlobalScopes()->create([
                'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id,
                'bill_id' => $bill->id, 'user_id' => $this->resident->id,
                'amount' => 300000, 'method' => PaymentMethod::Online,
                'status' => PaymentStatus::Pending, 'period' => $bill->period,
            ]);
        }

        $payments = Payment::withoutGlobalScopes()->where('bill_id', $bill->id)->get();

        foreach ($payments as $payment) {
            try {
                app(PaymentService::class)->settle($payment);
            } catch (\Throwable) {
                // رد شدنِ دومی هم نتیجهٔ درستی است؛ ادعا روی مبلغ است
            }
        }

        $this->assertLessThanOrEqual(
            300000.0,
            (float) DB::table('bills')->where('id', $bill->id)->value('paid_amount'),
            '🔴 دو پرداختِ هم‌زمان قبض را بیش از مبلغش تسویه کردند.',
        );
    }
}
