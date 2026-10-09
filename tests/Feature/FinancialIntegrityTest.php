<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\Scopes\ComplexScope;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payment\PaymentService;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * یکپارچگیِ مالی: حذفِ نرم، دفترِ تغییرناپذیر و Idempotency (R51).
 *
 * ─── سه چیزی که اندازه‌گیری شد و هر سه واقعی بودند ───────────────────────────
 * ① **پولی که گم می‌شد.** دو پرداختِ آنلاین روی یک قبضِ ۱۰۰٬۰۰۰ تومانی:
 *    بانک ۲۰۰٬۰۰۰ می‌گرفت، قبض ۱۰۰٬۰۰۰ ثبت می‌کرد، و کیفِ پول **صفر**
 *    می‌ماند. صد هزار تومان از حسابِ ساکن می‌رفت و در هیچ دفتری نبود.
 * ② **حذفِ دائمیِ دادهٔ مالی.** دو مسیرِ API هزینه و درآمد را برای همیشه
 *    می‌بردند.
 * ③ **دفترِ قابلِ ویرایش.** قاعده‌ی «فقط افزودنی» فقط یک قرارداد بود.
 */
class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unit;

    private User $manager;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚠️ `payment_gateway = 'fake'` اجباری است.
         *
         * بدونِ آن، تماس با درگاه شکست می‌خورد و پرداخت `failed` می‌شود؛
         * آن‌وقت کلیکِ دوم پرداختِ `pending`ای برای استفاده‌ی دوباره
         * پیدا نمی‌کند و **به‌درستی** ردیفِ تازه می‌سازد. تستی که درگاه را
         * جعل نکند، مسیرِ شکست را می‌سنجد نه مسیرِ Idempotency.
         */
        $this->complex = Complex::factory()->create(['payment_gateway' => 'fake']);

        $this->unit = Unit::factory()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => '۱',
        ]);

        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::ComplexAdmin->value,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        /*
         * ⚠️ پرداخت‌کننده باید **مالکِ واحد** باشد، نه مدیرِ مجتمع.
         *
         * `BillPolicy::pay()` عضویت در `unit_user` را می‌سنجد. نسخه‌ی اولِ
         * این تست با مدیر درخواست می‌فرستاد و ۴۰۳ می‌گرفت — یعنی هرگز به
         * کدِ موردِ آزمون نمی‌رسید و «صفر پرداخت» را به‌غلط موفقیت
         * می‌فهمید.
         */
        $this->resident = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner->value,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $this->resident->units()->attach($this->unit->id, [
            'complex_id' => $this->complex->id,
            'relation' => 'owner',
            'share_percent' => 100,
            'is_current' => true,
        ]);
    }

    private function bill(float $total = 100000): Bill
    {
        return Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => Jalali::currentPeriod(),
            'base_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => 0,
            'due_date' => now()->addDays(10),
        ]);
    }

    private function onlinePayment(Bill $bill, float $amount): Payment
    {
        return Payment::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'bill_id' => $bill->id,
            'user_id' => $this->manager->id,
            'amount' => $amount,
            'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Pending,
            'period' => $bill->period,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ① پولی که گم می‌شد
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مهم‌ترین تستِ این فایل: هیچ ریالی نباید از سامانه ناپدید شود.
     *
     * ─── سنجشِ پیش از اصلاح ─────────────────────────────────────────────────
     * قبضِ ۱۰۰٬۰۰۰ · دو پرداختِ موفقِ ۱۰۰٬۰۰۰
     *
     *     ثبت‌شده روی قبض : ۱۰۰٬۰۰۰  ✅
     *     موجودیِ کیف پول : ۰        ⚠️
     *     جمعِ پرداختِ موفق: ۲۰۰٬۰۰۰
     *
     * یعنی صد هزار تومان از حسابِ ساکن کم شده بود و هیچ‌کجا ثبت نمی‌شد.
     * `allocate()` مازاد را دور می‌ریخت و `recalculateBalance()` — که
     * کامنتِ قدیمی آن را «اعتبار» می‌نامید — فقط بدهیِ قبض‌ها را
     * بازمی‌شمارد و هیچ ربطی به کیف ندارد.
     */
    public function test_no_money_ever_disappears_from_the_system(): void
    {
        $bill = $this->bill(100000);

        $payments = app(PaymentService::class);
        $wallet = app(WalletService::class);

        $payments->settle($this->onlinePayment($bill, 100000));
        $payments->settle($this->onlinePayment($bill, 100000));

        $paidOnBill = (float) $bill->fresh()->paid_amount;
        // مازاد به کیفِ **پرداخت‌کننده** می‌رود (کیف مالِ حساب است؛ این‌جا پرداخت‌ها را مدیر زده)
        $inWallet = $wallet->balance($this->manager, $this->unit->complex_id);

        $chargedAtBank = (float) Payment::withoutGlobalScopes()
            ->where('bill_id', $bill->id)
            ->where('status', PaymentStatus::Success)
            ->sum('amount');

        $this->assertEqualsWithDelta(200000, $chargedAtBank, 0.01, 'صحنه درست ساخته نشد.');
        $this->assertEqualsWithDelta(100000, $paidOnBill, 0.01, 'قبض بیش از مبلغش پرداخت‌شده ثبت شد.');

        // ⚠️ ناوردایی: هر ریالی که گرفته شده، یا روی قبض است یا در کیف
        $this->assertEqualsWithDelta(
            $chargedAtBank,
            $paidOnBill + $inWallet,
            0.01,
            "پول گم شد: بانک {$chargedAtBank} گرفت، ولی قبض {$paidOnBill} و کیف {$inWallet} است.",
        );
    }

    /** مازاد باید ردیفِ دفتر داشته باشد، نه فقط عدد. */
    public function test_the_surplus_is_recorded_in_the_ledger(): void
    {
        $bill = $this->bill(50000);

        app(PaymentService::class)->settle($this->onlinePayment($bill, 80000));

        $row = WalletTransaction::withoutGlobalScopes()
            ->where('unit_id', $this->unit->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'مازاد هیچ ردیفی در دفتر نساخت.');
        $this->assertSame(WalletTransaction::CREDIT, $row->direction);
        $this->assertEqualsWithDelta(30000, (float) $row->amount, 0.01);

        // و باید بشود فهمید از کجا آمده
        $this->assertStringContainsString('مازاد', (string) $row->note);
    }

    /** پرداختی که دقیقاً اندازهٔ بدهی است نباید ردیفِ مازاد بسازد. */
    public function test_an_exact_payment_creates_no_surplus_row(): void
    {
        $bill = $this->bill(100000);

        app(PaymentService::class)->settle($this->onlinePayment($bill, 100000));

        $this->assertSame(
            0,
            WalletTransaction::withoutGlobalScopes()->where('unit_id', $this->unit->id)->count(),
            'برای پرداختِ دقیق، ردیفِ مازادِ بی‌مورد ساخته شد.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ② Idempotency در آغازِ پرداخت
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ دو بار زدنِ «پرداخت آنلاین» نباید دو تراکنشِ بانکی بسازد.
     *
     * ─── چرا قفلِ R50 اینجا محافظت نمی‌کرد ───────────────────────────────────
     * قفلِ ردیفی دو **درخواست روی یک ردیف** را پشتِ سرِ هم می‌کند. ولی
     * اینجا هر کلیک ردیفِ `Payment` **تازه‌ای** می‌ساخت؛ دو ردیفِ متفاوت،
     * دو قفلِ متفاوت، هیچ برخوردی. مسئله همزمانی نبود — نبودِ
     * Idempotency بود.
     */
    public function test_starting_an_online_payment_twice_reuses_the_pending_one(): void
    {
        $bill = $this->bill(100000);

        /*
         * ⚠️ مسیر `POST /pay/{bill}/online` است، نه `/start`. اسمِ متد
         * `start()` است ولی نامِ مسیر فرق دارد — حدس‌زدنِ آدرس از روی
         * نامِ متد، تستی می‌سازد که چیزِ دیگری را می‌سنجد.
         *
         * درگاهِ واقعی پیکربندی نشده، پس هر دو درخواست در مرحله‌ی تماس با
         * بانک شکست می‌خورند و پرداخت `failed` می‌شود. آنچه اینجا اهمیت
         * دارد این نیست که پرداخت موفق شود، بلکه این است که **دو ردیف
         * ساخته نشود**.
         */
        $this->actingAs($this->resident)->post('/pay/'.$bill->id.'/online');
        $this->actingAs($this->resident)->post('/pay/'.$bill->id.'/online');

        $rows = Payment::withoutGlobalScopes()
            ->where('bill_id', $bill->id)
            ->where('method', PaymentMethod::Online)
            ->get();

        $total = $rows->count();

        $this->assertSame(
            1,
            $total,
            "دو کلیک {$total} ردیفِ پرداخت ساخت؛ هر کدام یک تراکنشِ بانکیِ مستقل است.",
        );

        // و همان یک ردیف باید هنوز در انتظار باشد، نه شکست‌خورده
        $this->assertSame(PaymentStatus::Pending, $rows->first()->status);

    }

    // ─────────────────────────────────────────────────────────────────────
    //  ③ دفترِ تغییرناپذیر
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ ردیفِ دفتر پس از ثبت نه ویرایش می‌شود نه حذف.
     *
     * پیش از این فقط یک **قرارداد** بود که `WalletLedgerTest` با خواندنِ
     * سورس نگهش می‌داشت. آن تست جلوی کدِ تازه را می‌گیرد ولی جلوی یک
     * `$row->update(...)` در tinker یا یک دستورِ موقت را نه.
     */
    public function test_a_ledger_row_can_never_be_edited(): void
    {
        app(WalletService::class)->credit($this->resident, $this->unit, 50000, 'manual', null, 'شارژ');

        $row = WalletTransaction::withoutGlobalScopes()->firstOrFail();

        $this->expectException(DomainException::class);

        $row->update(['amount' => 999999]);
    }

    public function test_a_ledger_row_can_never_be_deleted(): void
    {
        app(WalletService::class)->credit($this->resident, $this->unit, 50000, 'manual', null, 'شارژ');

        $row = WalletTransaction::withoutGlobalScopes()->firstOrFail();

        $this->expectException(DomainException::class);

        $row->delete();
    }

    /**
     * ⚠️ اصلاح با ردیفِ معکوس ممکن است — وگرنه قاعده‌ی بالا سامانه را
     * قفل می‌کرد.
     *
     * این همان کاری است که حسابداری قرن‌هاست می‌کند: سندِ اشتباه می‌ماند و
     * سندِ برگشتی کنارش می‌نشیند.
     */
    public function test_a_mistake_is_fixed_with_a_reversing_entry(): void
    {
        $wallet = app(WalletService::class);

        $wallet->credit($this->resident, $this->unit, 50000, 'manual', null, 'شارژِ اشتباه');
        $wallet->debit($this->resident, $this->unit, 50000, 'manual', null, 'برگشتِ شارژِ اشتباه');

        $this->assertEqualsWithDelta(0.0, $wallet->balance($this->resident, $this->unit->complex_id), 0.01);

        // و هر دو ردیف سرِ جایشان هستند — تاریخچه دست‌نخورده
        $this->assertSame(2, WalletTransaction::withoutGlobalScopes()->count());
    }

    /** قبض و پرداخت هم نباید حذف شوند، ولی باید قابلِ ویرایش بمانند. */
    public function test_bills_and_payments_cannot_be_deleted_but_can_change_status(): void
    {
        $bill = $this->bill();
        $payment = $this->onlinePayment($bill, 50000);

        // ویرایش باید کار کند
        $payment->update(['status' => PaymentStatus::Failed]);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);

        $this->expectException(DomainException::class);

        $bill->delete();
    }

    public function test_a_payment_cannot_be_deleted(): void
    {
        $payment = $this->onlinePayment($this->bill(), 50000);

        $this->expectException(DomainException::class);

        $payment->delete();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ④ حذفِ نرمِ هزینه و درآمد
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_finance_tables_have_a_soft_delete_column(): void
    {
        $this->assertTrue(Schema::hasColumn('expenses', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('incomes', 'deleted_at'));
    }

    /**
     * ⚠️ حذفِ هزینه باید برگشت‌پذیر باشد.
     *
     * ─── نقصی که اندازه‌گیری شد ─────────────────────────────────────────────
     * `DELETE /api/v1/finance/expenses/{expense}` رکورد را برای همیشه
     * می‌برد. سیاههٔ ممیزی می‌گفت **چه کسی** حذف کرده، ولی خودِ مبلغ و شرح
     * رفته بود — و برگرداندنش یعنی بازیابیِ کلِ بکاپِ شبانه، که کارِ آن
     * روزِ بقیه را هم برمی‌گرداند.
     */
    public function test_deleting_an_expense_is_reversible(): void
    {
        /*
         * ⚠️ `withoutGlobalScope(ComplexScope::class)` و نه
         * `withoutGlobalScopes()`.
         *
         * نسخه‌ی اولِ این تست دومی را داشت و شکست خورد — چون آن متد **همه‌ی**
         * اسکوپ‌های سراسری را برمی‌دارد، از جمله `SoftDeletingScope`. یعنی
         * ردیفِ حذف‌شده را هم برمی‌گرداند و تست هرگز نمی‌توانست تفاوتِ
         * «حذف شد» و «نشد» را ببیند.
         */
        $expense = Expense::create([
            'complex_id' => $this->complex->id,
            'title' => 'تعمیر آسانسور',
            'amount' => 4500000,
            'category' => 'owner',
            'period' => Jalali::currentPeriod(),
            'spend_date' => now(),
        ]);

        $expense->delete();

        // از دیدِ برنامه رفته…
        $this->assertNull(Expense::withoutGlobalScope(ComplexScope::class)->find($expense->id));

        // …ولی داده هنوز هست و برمی‌گردد
        $trashed = Expense::withoutGlobalScope(ComplexScope::class)->withTrashed()->find($expense->id);

        $this->assertNotNull($trashed, 'هزینه برای همیشه حذف شد.');
        $this->assertEqualsWithDelta(4500000, (float) $trashed->amount, 0.01);

        $trashed->restore();

        $this->assertNotNull(Expense::withoutGlobalScope(ComplexScope::class)->find($expense->id));
    }

    public function test_deleting_an_income_is_reversible(): void
    {
        $income = Income::create([
            'complex_id' => $this->complex->id,
            'title' => 'اجاره‌ی بام',
            'amount' => 2000000,
            'period' => Jalali::currentPeriod(),
            'received_date' => now(),
        ]);

        $income->delete();

        $this->assertNull(Income::withoutGlobalScope(ComplexScope::class)->find($income->id));
        $this->assertNotNull(Income::withoutGlobalScope(ComplexScope::class)->withTrashed()->find($income->id));
    }

    /**
     * ⚠️ هزینه‌ی حذف‌شده نباید در گزارش‌های مالی بیاید.
     *
     * حذفِ نرم وقتی درست است که برنامه واقعاً ردیف را نبیند؛ وگرنه فقط
     * یک ستونِ بی‌اثر اضافه کرده‌ایم.
     */
    public function test_a_soft_deleted_expense_leaves_the_reports(): void
    {
        $keep = Expense::create([
            'complex_id' => $this->complex->id, 'title' => 'می‌ماند', 'amount' => 1000,
            'category' => 'owner', 'period' => Jalali::currentPeriod(), 'spend_date' => now(),
        ]);

        $drop = Expense::create([
            'complex_id' => $this->complex->id, 'title' => 'می‌رود', 'amount' => 9000,
            'category' => 'owner', 'period' => Jalali::currentPeriod(), 'spend_date' => now(),
        ]);

        $drop->delete();

        $total = (float) Expense::withoutGlobalScope(ComplexScope::class)
            ->where('complex_id', $this->complex->id)
            ->sum('amount');

        $this->assertEqualsWithDelta(
            1000,
            $total,
            0.01,
            'هزینه‌ی حذف‌شده هنوز در جمعِ گزارش می‌آید.',
        );

        $this->assertNotNull($keep->fresh());
    }
}
