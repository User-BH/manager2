<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * کیفِ پول و دفترش (R22).
 *
 * ─── محدودیتی که باید بدانید ───────────────────────────────────────────────
 * مثل R15، SQLiteِ تست `FOR UPDATE` را نادیده می‌گیرد، پس **همزمانیِ واقعی
 * اینجا قابل شبیه‌سازی نیست**. آنچه سنجیده می‌شود لایه‌ی دومِ محافظت است:
 * بازخوانیِ مانده داخلِ تراکنش، که حتی بی‌قفل هم اضافه‌برداشت را می‌گیرد.
 * وجودِ خودِ قفل با خواندنِ سورس تضمین می‌شود.
 */
class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unit;

    private User $resident;

    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $this->resident = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);
        $this->unit->residents()->attach($this->resident->id, [
            'relation' => 'owner',
            'complex_id' => $this->complex->id,
        ]);

        $this->wallet = app(WalletService::class);
    }

    /* ── مانده از دفتر، نه از ستون ───────────────────────────────────────── */

    public function test_a_new_wallet_is_empty(): void
    {
        $this->assertSame(0.0, $this->wallet->balance($this->resident));
    }

    public function test_the_balance_is_the_sum_of_the_ledger(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 500000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $this->wallet->credit($this->resident, $this->unit, 200000, WalletTransaction::SOURCE_TOPUP_GATEWAY);
        $this->wallet->debit($this->resident, $this->unit, 300000, WalletTransaction::SOURCE_ADJUSTMENT);

        $this->assertSame(400000.0, $this->wallet->balance($this->resident));
    }

    /**
     * هیچ ستونِ مانده‌ای وجود ندارد که بتواند با دفتر واگرا شود.
     *
     * این قاعده‌ی مرکزیِ طراحی است: وسوسه‌ی همیشگی یک ستونِ `balance` است که
     * با هر تراکنش کم و زیاد شود، و همان ستون دیر یا زود با دفتر فرق می‌کند
     * بی‌آنکه کسی بفهمد کدام درست است.
     */
    public function test_the_service_never_stores_a_mutable_balance(): void
    {
        $source = File::get(app_path('Services/Wallet/WalletService.php'));

        $this->assertStringContainsString('SUM(amount)', $source);
        $this->assertStringNotContainsString('units.balance', $source);
    }

    public function test_the_snapshot_agrees_with_the_recomputed_balance(): void
    {
        /*
         * `balance_after` فقط عکسِ لحظه‌ای برای صورت‌حساب است. اگر روزی با
         * جمعِ دفتر واگرا شود، صورت‌حساب دروغ می‌گوید — پس سنجیده می‌شود.
         */
        $this->wallet->credit($this->resident, $this->unit, 100000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $this->wallet->credit($this->resident, $this->unit, 50000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $this->wallet->debit($this->resident, $this->unit, 30000, WalletTransaction::SOURCE_ADJUSTMENT);

        $last = WalletTransaction::orderByDesc('id')->first();

        $this->assertEquals($this->wallet->balance($this->resident), (float) $last->balance_after);
    }

    /* ── اضافه‌برداشت ────────────────────────────────────────────────────── */

    public function test_a_wallet_cannot_go_negative(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 100000, WalletTransaction::SOURCE_TOPUP_RECEIPT);

        $this->expectExceptionMessage('موجودی کیف پول کافی نیست');
        $this->wallet->debit($this->resident, $this->unit, 150000, WalletTransaction::SOURCE_ADJUSTMENT);
    }

    public function test_a_refused_debit_writes_no_ledger_row(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 100000, WalletTransaction::SOURCE_TOPUP_RECEIPT);

        try {
            $this->wallet->debit($this->resident, $this->unit, 150000, WalletTransaction::SOURCE_ADJUSTMENT);
        } catch (\Throwable) {
            // انتظار همین بود
        }

        // یک ردیف (همان شارژ) — نه ردیفِ ناقصِ برداشت
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(100000.0, $this->wallet->balance($this->resident));
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        $this->expectExceptionMessage('مبلغ باید بزرگ‌تر از صفر باشد');
        $this->wallet->credit($this->resident, $this->unit, 0, WalletTransaction::SOURCE_ADJUSTMENT);
    }

    public function test_wallet_writes_take_a_row_lock(): void
    {
        /*
         * برداشت الگوی «بخوان، کم کن، بنویس» دارد و بدونِ قفل، دو برداشتِ
         * هم‌زمان هر دو مانده‌ی قدیمی را می‌بینند. SQLite قفل را نادیده
         * می‌گیرد، پس وجودش در کد سنجیده می‌شود.
         */
        $source = File::get(app_path('Services/Wallet/WalletService.php'));

        $this->assertStringContainsString('lockForUpdate', $source);
        $this->assertStringContainsString('attempts: 3', $source);
    }

    /* ── پرداختِ قبض از کیف ──────────────────────────────────────────────── */

    public function test_paying_a_bill_moves_money_from_the_wallet_to_the_bill(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 500000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $bill = $this->makeBill(300000);

        $paid = $this->wallet->payBill($this->resident, $bill);

        $this->assertSame(300000.0, $paid);
        $this->assertSame(200000.0, $this->wallet->balance($this->resident));
        $this->assertSame('300000.00', $bill->fresh()->paid_amount);
        $this->assertSame('paid', $bill->fresh()->status->value);
    }

    public function test_paying_more_than_the_debt_is_capped_at_the_debt(): void
    {
        // پرداختِ بیشتر از بدهی، اعتبارِ سرگردان روی قبض می‌ساخت
        $this->wallet->credit($this->resident, $this->unit, 500000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $bill = $this->makeBill(100000);

        $paid = $this->wallet->payBill($this->resident, $bill, 400000);

        $this->assertSame(100000.0, $paid);
        $this->assertSame(400000.0, $this->wallet->balance($this->resident));
    }

    public function test_a_partial_balance_pays_what_it_can(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 120000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $bill = $this->makeBill(500000);

        $paid = $this->wallet->payBill($this->resident, $bill);

        $this->assertSame(120000.0, $paid);
        $this->assertSame(0.0, $this->wallet->balance($this->resident));
        $this->assertSame('partial', $bill->fresh()->status->value);
    }

    public function test_an_empty_wallet_cannot_pay(): void
    {
        $bill = $this->makeBill(100000);

        $this->expectExceptionMessage('موجودی کیف پول شما صفر است');
        $this->wallet->payBill($this->resident, $bill);
    }

    public function test_a_settled_bill_cannot_be_paid_again(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 500000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $bill = $this->makeBill(100000);

        $this->wallet->payBill($this->resident, $bill);

        // دومی نباید دوباره از کیف کم کند
        $this->expectExceptionMessage('بدهی باز ندارد');
        $this->wallet->payBill($this->resident, $bill->fresh());
    }

    /**
     * کیفِ یک واحد نباید قبضِ واحدِ دیگر را بپردازد.
     *
     * بدونِ این، ساکنی می‌توانست با دست‌کاری شناسه، موجودیِ واحدِ خودش را
     * خرجِ بدهیِ واحدِ دیگری کند.
     */
    public function test_a_wallet_cannot_pay_another_units_bill(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 500000, WalletTransaction::SOURCE_TOPUP_RECEIPT);

        $otherUnit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $otherBill = Bill::create([
            'complex_id' => $this->complex->id, 'unit_id' => $otherUnit->id,
            'period' => '1405-01', 'total_amount' => 100000,
            'status' => 'unpaid', 'due_date' => now()->addDays(5),
        ]);

        $this->expectExceptionMessage('متعلق به واحدِ شما نیست');
        $this->wallet->payBill($this->resident, $otherBill);
    }

    /* ── دسترسی از راه API ──────────────────────────────────────────────── */

    public function test_a_resident_sees_their_own_wallet(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 250000, WalletTransaction::SOURCE_TOPUP_RECEIPT);

        $this->actingAs($this->resident)
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('wallets.0.balance', 250000);
    }

    public function test_a_stranger_cannot_read_another_units_statement(): void
    {
        $stranger = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);

        /*
         * مرحلهٔ ۹: یک کیف برای هر حساب؛ `/wallet/statement` همیشه کیفِ **خودِ**
         * درخواست‌کننده را می‌دهد. همسایه فقط کیفِ خالیِ خودش را می‌بیند — و
         * گردشِ اعضا (بی مانده) مالِ مدیر است.
         */
        $this->wallet->credit($this->resident, $this->unit, 90000, WalletTransaction::SOURCE_TOPUP_RECEIPT);

        $this->actingAs($stranger)
            ->getJson('/api/v1/wallet/statement')
            ->assertOk()
            ->assertJsonPath('balance', 0)
            ->assertJsonCount(0, 'transactions');

        $this->actingAs($stranger)
            ->getJson("/api/v1/wallet/members/{$this->resident->id}")
            ->assertForbidden();
    }

    public function test_paying_through_the_api_updates_both_sides(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 500000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $bill = $this->makeBill(200000);

        $this->actingAs($this->resident)
            ->postJson("/api/v1/wallet/pay/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('paid', 200000)
            ->assertJsonPath('balance', 300000);

        $this->assertSame('200000.00', $bill->fresh()->paid_amount);
    }

    /* ── کارت‌به‌کارت ────────────────────────────────────────────────────── */

    /**
     * ساکن باید بداند پول را کجا بفرستد.
     *
     * پیش از R22، مجتمعی که درگاه نداشت فقط دکمه‌ی «آپلود رسید» را نشان
     * می‌داد — بدونِ آنکه هیچ‌جا شماره‌ی کارتِ مقصد گفته شود.
     */
    public function test_the_pay_page_shows_the_complexes_card(): void
    {
        $this->complex->update(['settings' => [
            'card_number' => '6104-3372-1111-2222',
            'card_holder' => 'هیئت مدیره',
            'card_bank' => 'بانک ملت',
        ]]);

        $bill = $this->makeBill(100000);

        $this->actingAs($this->resident)
            ->getJson("/api/v1/pay/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('card.number', '6104-3372-1111-2222')
            ->assertJsonPath('card.holder', 'هیئت مدیره');
    }

    public function test_a_complex_without_a_card_reports_none(): void
    {
        // نبودِ کارت باید صریح باشد تا رابط بخشِ خالی نشان ندهد
        $bill = $this->makeBill(100000);

        $this->actingAs($this->resident)
            ->getJson("/api/v1/pay/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('card', null);
    }

    public function test_the_pay_page_shows_the_wallet_balance(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 75000, WalletTransaction::SOURCE_TOPUP_RECEIPT);
        $bill = $this->makeBill(100000);

        $this->actingAs($this->resident)
            ->getJson("/api/v1/pay/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('walletBalance', 75000);
    }

    /**
     * 🔴 هر منبعِ کیفِ پول برچسبِ فارسی دارد (مرحلهٔ ۷ · فاز ۷).
     *
     * مازادِ رسید با رشته‌ی خامِ `'payment'` و بازگشت از کاهشِ قبض با
     * `'manual'` ثبت می‌شدند و صورت‌حسابِ ساکن خودِ واژه‌ی انگلیسی را نشان
     * می‌داد. حالا هم برچسب سنجیده می‌شود، هم اینکه هیچ فراخوانیِ `credit`
     * رشته‌ی خام نمی‌دهد — منبعِ تازه باید ثابت و برچسبِ خودش را بگیرد.
     */
    public function test_every_wallet_source_has_a_persian_label(): void
    {
        foreach (WalletTransaction::SOURCES as $source) {
            $label = (new WalletTransaction(['source' => $source]))->sourceLabel();

            $this->assertNotSame($source, $label, "منبعِ «{$source}» برچسب ندارد");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $label);
        }

        $raw = [];
        foreach (['app/Services/Payment/PaymentService.php', 'app/Services/Charge/BillGenerator.php', 'app/Services/Wallet/WalletService.php'] as $file) {
            $code = (string) file_get_contents(base_path($file));
            if (preg_match_all('/->credit\(\s*\$\w+,\s*[^,]+,\s*\'[a-z_]+\'/s', $code, $m)) {
                $raw[$file] = $m[0];
            }
        }

        $this->assertSame([], $raw, 'منبعِ کیفِ پول با رشته‌ی خام، نه ثابت');
    }

    public function test_a_receipt_surplus_shows_a_persian_source_in_the_statement(): void
    {
        $this->wallet->credit($this->resident, $this->unit, 40000, WalletTransaction::SOURCE_PAYMENT_SURPLUS);

        $row = WalletTransaction::where('unit_id', $this->unit->id)->latest('id')->firstOrFail();

        $this->assertSame('مازاد پرداخت', $row->sourceLabel());
        $this->assertSame('payment', $row->source, 'مقدارِ ذخیره‌شده نباید عوض شود — ردیف‌های قدیمی همین را دارند');
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function makeBill(float $total): Bill
    {
        return Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => '1405-01',
            'total_amount' => $total,
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
        ]);
    }
}
