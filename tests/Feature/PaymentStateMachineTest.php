<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * وضعیتِ پرداخت فقط از مسیرهای مجاز عبور می‌کند (فاز ۱).
 *
 * ─── 🔴 چه چیزی باز بود ────────────────────────────────────────────────────
 * `PaymentService::settle()` پیش از تسویه وضعیت را زیرِ قفل دوباره می‌خواند
 * — ولی فقط یک حالت را رد می‌کرد:
 *
 *     if (! $fresh || $fresh->status === PaymentStatus::Success) return false;
 *
 * ⚠️ یعنی پرداختی که **ناموفق** یا **ردشده** بود، با یک فراخوانیِ دوباره
 * موفق می‌شد. و `reject()` هیچ محافظی نداشت: پرداختِ **تسویه‌شده** را
 * می‌شد رد کرد، بی‌آنکه پولی که روی قبض نشسته برگردد.
 *
 * هر دو دقیقاً همان گذارهایی‌اند که مشخصات ممنوعشان کرده:
 * `failed → paid` و `paid → cancelled`.
 *
 * ─── چرا محافظ روی مدل نشست، نه در سرویس ───────────────────────────────────
 * ⚠️ شرطِ داخلِ سرویس فقط همان مسیر را می‌بندد. کنترلر، کارِ صف، دستورِ
 * کنسول و `tinker` هرکدام مسیرِ خودشان را دارند. قاعده جایی گذاشته شد که
 * **همه‌ی مسیرها از آن می‌گذرند**: خودِ مدل.
 */
class PaymentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        $complex = Complex::factory()->create();
        $unit = Unit::factory()->for($complex)->create();
        $this->bill = Bill::factory()->for($complex)->for($unit)->create(['total_amount' => 500000, 'paid_amount' => 0]);
    }

    private function payment(PaymentStatus $status): Payment
    {
        return Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->bill->complex_id,
            'unit_id' => $this->bill->unit_id,
            'bill_id' => $this->bill->id,
            'amount' => 100000,
            'method' => PaymentMethod::Online,
            'status' => $status,
            'period' => $this->bill->period,
        ]);
    }

    /**
     * گذارهایی که **باید** ممنوع باشند.
     *
     * ⚠️ همه از یک جنس‌اند: خروج از یک حالتِ **پایانی**. پول یا جابه‌جا شده
     * یا قطعاً نشده؛ هر دو حالت نتیجه‌ای دارند که کسی رویش حساب کرده.
     *
     * @return array<string, array{0: PaymentStatus, 1: PaymentStatus}>
     */
    public static function forbiddenTransitions(): array
    {
        return [
            'ناموفق → موفق' => [PaymentStatus::Failed, PaymentStatus::Success],
            'ردشده → موفق' => [PaymentStatus::Rejected, PaymentStatus::Success],
            'موفق → ردشده' => [PaymentStatus::Success, PaymentStatus::Rejected],
            'موفق → ناموفق' => [PaymentStatus::Success, PaymentStatus::Failed],
            'موفق → در انتظار' => [PaymentStatus::Success, PaymentStatus::Pending],
            'ناموفق → در انتظار' => [PaymentStatus::Failed, PaymentStatus::Pending],
            'ردشده → در انتظار' => [PaymentStatus::Rejected, PaymentStatus::Pending],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_a_forbidden_transition_is_refused(PaymentStatus $from, PaymentStatus $to): void
    {
        $payment = $this->payment($from);

        try {
            $payment->update(['status' => $to]);
            $this->fail("گذارِ {$from->value} → {$to->value} پذیرفته شد.");
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }

        // ⚠️ ادعا روی دیتابیس است، نه روی استثنا
        $this->assertSame(
            $from->value,
            DB::table('payments')->where('id', $payment->id)->value('status'),
            "وضعیت با وجودِ ممنوع بودن به {$to->value} رفت.",
        );
    }

    /**
     * @return array<string, array{0: PaymentStatus, 1: PaymentStatus}>
     */
    public static function allowedTransitions(): array
    {
        return [
            'در انتظار → موفق' => [PaymentStatus::Pending, PaymentStatus::Success],
            'در انتظار → ناموفق' => [PaymentStatus::Pending, PaymentStatus::Failed],
            'در انتظار → ردشده' => [PaymentStatus::Pending, PaymentStatus::Rejected],
        ];
    }

    /**
     * ⚠️ محافظی که همه‌چیز را ببندد، همان‌قدر خراب است.
     *
     * بدونِ این تست، بستنِ کاملِ گذارها هم سبز می‌ماند و کسی تا اولین
     * پرداختِ واقعی متوجه نمی‌شد.
     */
    #[DataProvider('allowedTransitions')]
    public function test_an_allowed_transition_still_works(PaymentStatus $from, PaymentStatus $to): void
    {
        $payment = $this->payment($from);
        $payment->update(['status' => $to]);

        $this->assertSame(
            $to->value,
            DB::table('payments')->where('id', $payment->id)->value('status'),
        );
    }

    /**
     * 🔴 همان شکافِ واقعیِ سرویس — از مسیرِ خودِ سرویس.
     *
     * ⚠️ تستِ بالا مدل را مستقیم می‌زند. این یکی می‌سنجد که مسیرِ عمومیِ
     * برنامه هم بسته شده باشد: `settle()` روی پرداختِ ردشده.
     */
    public function test_settling_a_rejected_payment_does_not_move_money(): void
    {
        $payment = $this->payment(PaymentStatus::Rejected);
        $before = (float) $this->bill->fresh()->paid_amount;

        try {
            app(PaymentService::class)->settle($payment);
        } catch (\Throwable) {
            // رد شدن با استثنا هم پذیرفته است؛ ادعا روی داده است
        }

        $this->assertSame(
            PaymentStatus::Rejected->value,
            DB::table('payments')->where('id', $payment->id)->value('status'),
        );

        $this->assertSame(
            $before,
            (float) $this->bill->fresh()->paid_amount,
            '🔴 پرداختِ ردشده روی بدهی نشست.',
        );
    }

    /** ⚠️ و برعکسش: پرداختِ تسویه‌شده نباید ردشدنی باشد. */
    public function test_rejecting_a_settled_payment_is_refused(): void
    {
        $payment = $this->payment(PaymentStatus::Success);

        try {
            app(PaymentService::class)->reject($payment);
        } catch (\Throwable) {
        }

        $this->assertSame(
            PaymentStatus::Success->value,
            DB::table('payments')->where('id', $payment->id)->value('status'),
            '🔴 پرداختِ تسویه‌شده رد شد، بی‌آنکه پولش برگردد.',
        );
    }

    /**
     * ⚠️ مسیرِ درگاه عمداً از `update` انبوه استفاده می‌کند و محافظش را
     * **در خودِ SQL** دارد (`where('status', Pending)`).
     *
     * 🔴 این تست همان را می‌سنجد، چون به‌روزرسانیِ انبوه رویدادِ مدل شلیک
     * نمی‌کند و محافظِ مدل آنجا کار نمی‌کند.
     */
    public function test_the_gateway_bulk_update_cannot_flip_a_settled_payment(): void
    {
        $payment = $this->payment(PaymentStatus::Success);

        Payment::withoutGlobalScopes()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::Pending)
            ->update(['status' => PaymentStatus::Failed]);

        $this->assertSame(
            PaymentStatus::Success->value,
            DB::table('payments')->where('id', $payment->id)->value('status'),
        );
    }
}
