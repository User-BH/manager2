<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * یادآوریِ دوره‌ایِ قبضِ سررسیدشده (R22).
 *
 * خواسته: «اگر واحد اعلان را دید و فراموش کرد، چند روز بعد دوباره یادآوری
 * بگیرد» — یعنی تکرار، ولی نه آن‌قدر که تبدیل به مزاحمت شود.
 */
class BillReminderTest extends TestCase
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
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);
        $this->unit->residents()->attach($this->resident->id, [
            'relation' => 'owner',
            'complex_id' => $this->complex->id,
        ]);
    }

    public function test_an_overdue_bill_reminds_its_residents(): void
    {
        $this->overdueBill();

        $this->artisan('bills:remind')->assertSuccessful();

        $this->assertSame(1, $this->resident->notifications()->count());
    }

    public function test_a_bill_that_is_not_due_yet_is_left_alone(): void
    {
        $this->makeBill(now()->addDays(5));

        $this->artisan('bills:remind')->assertSuccessful();

        $this->assertSame(0, $this->resident->notifications()->count());
    }

    public function test_a_paid_bill_is_left_alone(): void
    {
        $bill = $this->overdueBill();
        $bill->update(['paid_amount' => $bill->total_amount, 'status' => 'paid']);

        $this->artisan('bills:remind')->assertSuccessful();

        $this->assertSame(0, $this->resident->notifications()->count());
    }

    /* ── تکرار، ولی نه مزاحمت ───────────────────────────────────────────── */

    /**
     * اجرای دوباره در همان روز نباید اعلانِ تازه بسازد.
     *
     * بدونِ فاصله‌ی حداقلی، هر بار که زمان‌بند اجرا شود یک اعلانِ دیگر ساخته
     * می‌شد؛ اگر روزی چند بار اجرا می‌شد ساکن روزی چند پیام می‌گرفت و
     * یادآوری را کلاً خاموش می‌کرد.
     */
    public function test_running_again_immediately_sends_nothing(): void
    {
        $this->overdueBill();

        $this->artisan('bills:remind')->assertSuccessful();
        $this->artisan('bills:remind')->assertSuccessful();

        $this->assertSame(1, $this->resident->notifications()->count());
    }

    public function test_after_the_interval_it_reminds_again(): void
    {
        // همان خواسته‌ی «چند روز بعد دوباره یادآوری بگیرد»
        $this->overdueBill();

        $this->artisan('bills:remind')->assertSuccessful();

        $this->travel(4)->days();
        $this->artisan('bills:remind')->assertSuccessful();

        $this->assertSame(2, $this->resident->notifications()->count());
    }

    public function test_reminders_stop_after_the_cap(): void
    {
        /*
         * قبضی که هرگز پرداخت نشود نباید تا ابد اعلان بسازد. کسی که چهار بار
         * یادآوری گرفته با پنجمی پرداخت نمی‌کند؛ فقط یاد می‌گیرد اعلان‌ها را
         * نادیده بگیرد.
         */
        $this->overdueBill();

        foreach (range(1, 8) as $ignored) {
            $this->artisan('bills:remind')->assertSuccessful();
            $this->travel(4)->days();
        }

        $this->assertSame(4, $this->resident->notifications()->count());
    }

    /* ── حالت‌های لبه ───────────────────────────────────────────────────── */

    public function test_a_unit_with_no_residents_is_skipped_without_error(): void
    {
        // واحدِ خالی وضعیتِ کاملاً عادی است، نه خطا
        $empty = Unit::factory()->create(['complex_id' => $this->complex->id]);
        Bill::create([
            'complex_id' => $this->complex->id, 'unit_id' => $empty->id,
            'period' => '1405-01', 'total_amount' => 100000,
            'status' => 'unpaid', 'due_date' => now()->subDays(5),
        ]);

        $this->artisan('bills:remind')->assertSuccessful();
    }

    public function test_dry_run_reports_without_sending(): void
    {
        $bill = $this->overdueBill();

        $this->artisan('bills:remind --dry-run')->assertSuccessful();

        $this->assertSame(0, $this->resident->notifications()->count());
        // و شمارنده هم دست‌نخورده می‌ماند
        $this->assertSame(0, $bill->fresh()->reminders_sent);
    }

    /**
     * تعدادِ روزهای گذشته باید مثبت باشد.
     *
     * این را اجرای آزمایشی روی داده‌ی واقعی پیدا کرد، نه تست‌ها: پیام
     * «۳۲- روز از سررسیدش گذشته» می‌داد، چون `diffInDays` در Carbon 3
     * علامت‌دار است و ترتیبِ آرگومان‌ها برعکس بود. تست‌های قبلی فقط تعدادِ
     * اعلان را می‌شمردند و هرگز متنش را نمی‌خواندند.
     */
    public function test_the_message_reports_a_positive_number_of_days(): void
    {
        $this->makeBill(now()->subDays(12));

        $this->artisan('bills:remind')->assertSuccessful();

        $body = $this->resident->notifications()->first()->data['body'];

        $this->assertStringContainsString('12 روز', $body);
        // «۱۲- روز» نه؛ خطِ تیره‌ی خودِ دوره (1405-01) نباید با آن اشتباه شود
        $this->assertDoesNotMatchRegularExpression('/-\d+ روز/', $body);
    }

    public function test_a_partial_bill_is_still_reminded(): void
    {
        // بدهیِ باقی‌مانده هم بدهی است
        $bill = $this->overdueBill();
        $bill->update(['paid_amount' => 40000, 'status' => 'partial']);

        $this->artisan('bills:remind')->assertSuccessful();

        $this->assertSame(1, $this->resident->notifications()->count());
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function overdueBill(): Bill
    {
        return $this->makeBill(now()->subDays(5));
    }

    private function makeBill(CarbonInterface $due): Bill
    {
        return Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => '1405-01',
            'total_amount' => 100000,
            'status' => 'unpaid',
            'due_date' => $due,
        ]);
    }
}
