<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\ExpenseCategory;
use App\Enums\NotificationChannelKey;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Expense;
use App\Models\SmsCampaign;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use App\Support\NotificationPreferences;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * سهمیه‌ی ماهانه‌ی پیامکِ یادآوریِ شارژ (R27).
 *
 * ─── چرا این تست‌ها سخت‌گیرند ───────────────────────────────────────────────
 * این تنها پیامکی است که سامانه جز کدِ ورود می‌فرستد، و هر ارسالِ اشتباه
 * هم پول است و هم اعتبارِ مدیر نزدِ ساکنین. پس هر سه قیدِ خواسته‌شده جدا
 * سنجیده می‌شوند: **ماهی یک بار**، **فقط پس از ثبتِ هزینه‌ها**، و **فقط به
 * بدهکاران**.
 */
class SmsCampaignTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private string $period;

    /** شماره‌های یکتا در طولِ کلاس؛ `users.phone` قیدِ یکتا دارد. */
    private static int $phoneSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();
        $this->manager = $this->makeUser(UserRole::ComplexAdmin);
        $this->period = Jalali::currentPeriod();
    }

    /**
     * ⚠️ شماره صریح داده می‌شود: `UserFactory` شماره نمی‌سازد و کاربرِ
     * بی‌شماره عمداً از فهرستِ گیرندگان حذف می‌شود، پس بدونِ این خط همه‌ی
     * تست‌ها سبزِ توخالی می‌شدند (هیچ گیرنده‌ای، پس هیچ ارسالی).
     */
    private function makeUser(UserRole $role = UserRole::Owner): User
    {
        return User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => $role,
            'is_active' => true,
            'phone' => '0912'.str_pad((string) ++self::$phoneSeq, 7, '0', STR_PAD_LEFT),
        ]);
    }

    /** واحدی با یک ساکنِ جاری و یک قبضِ دوره‌ی جاری. */
    private function debtor(float $total = 500000, float $paid = 0, string $number = '1'): User
    {
        $unit = Unit::factory()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => $number,
        ]);

        $resident = $this->makeUser();
        $unit->residents()->attach($resident->id, [
            'relation' => ResidentRelation::Owner->value,
            'complex_id' => $this->complex->id,
            'is_current' => true,
        ]);

        Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $unit->id,
            'period' => $this->period,
            'base_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'status' => $paid >= $total ? BillStatus::Paid : BillStatus::Unpaid,
            'due_date' => now()->addDays(10),
        ]);

        return $resident;
    }

    private function recordExpense(): void
    {
        Expense::create([
            'complex_id' => $this->complex->id,
            'title' => 'قبض آب',
            'amount' => 1200000,
            'category' => ExpenseCategory::cases()[0]->value,
            'period' => $this->period,
            'spend_date' => now(),
        ]);
    }

    private function campaignStatus(): TestResponse
    {
        return $this->actingAs($this->manager)->getJson('/api/v1/sms-campaign');
    }

    private function send(): TestResponse
    {
        return $this->actingAs($this->manager)->postJson('/api/v1/sms-campaign');
    }

    // ── قید ۱: پس از ثبتِ هزینه‌ها ─────────────────────────────────────────

    public function test_without_expenses_the_manager_cannot_send(): void
    {
        $this->debtor();

        $this->campaignStatus()->assertOk()->assertJson(['canSend' => false]);
        $this->assertStringContainsString('هزینه‌های', $this->campaignStatus()->json('blockReason'));

        $this->send()->assertStatus(422);
        $this->assertSame(0, SmsCampaign::count());
    }

    public function test_with_expenses_but_no_bills_the_manager_cannot_send(): void
    {
        $this->recordExpense();

        $this->assertStringContainsString('قبض‌های', $this->campaignStatus()->json('blockReason'));
        $this->send()->assertStatus(422);
    }

    public function test_once_expenses_and_bills_exist_the_manager_can_send(): void
    {
        $this->debtor();
        $this->recordExpense();

        $this->campaignStatus()->assertJson(['canSend' => true, 'recipientCount' => 1]);
        $this->send()->assertOk();

        $campaign = SmsCampaign::firstOrFail();
        $this->assertSame(1, $campaign->recipients);
        $this->assertSame($this->period, $campaign->period);
    }

    // ── قید ۲: ماهی یک بار ─────────────────────────────────────────────────

    public function test_the_quota_is_one_per_month(): void
    {
        $this->debtor();
        $this->recordExpense();

        $this->send()->assertOk();
        $this->send()->assertStatus(422);

        $this->assertSame(1, SmsCampaign::count());
        $this->assertTrue($this->campaignStatus()->json('quotaUsed'));
    }

    /**
     * ⚠️ این تست پس از یک خرابکاریِ عمدی اضافه شد.
     *
     * `test_the_quota_is_one_per_month` با برداشتنِ قیدِ سهمیه از
     * `blockReason()` **همچنان سبز می‌ماند**، چون قیدِ یکتای دیتابیس جلوی
     * ارسالِ دوم را می‌گرفت. یعنی درستی حفظ می‌شد ولی رابط دکمه را فعال
     * نشان می‌داد و مدیر با کلیک خطا می‌گرفت. اینجا خودِ گزارشِ وضعیت
     * سنجیده می‌شود.
     */
    public function test_after_sending_the_status_reports_the_quota_as_the_blocker(): void
    {
        $this->debtor();
        $this->recordExpense();
        $this->send()->assertOk();

        $status = $this->campaignStatus();

        $this->assertFalse($status->json('canSend'));
        $this->assertStringContainsString('سهمیه', $status->json('blockReason'));
    }

    public function test_a_new_month_gives_a_fresh_quota(): void
    {
        $this->debtor();
        $this->recordExpense();
        $this->send()->assertOk();

        // کارزارِ ماهِ گذشته سهمیه‌ی این ماه را نمی‌سوزاند
        SmsCampaign::firstOrFail()->update(['period' => '1400-01']);

        $this->assertTrue($this->campaignStatus()->json('canSend'));
    }

    public function test_the_database_refuses_a_second_campaign_for_the_same_period(): void
    {
        $this->debtor();
        $this->recordExpense();
        $this->send()->assertOk();

        // قیدِ یکتا در دیتابیس است، نه فقط در کد
        $this->expectException(UniqueConstraintViolationException::class);

        SmsCampaign::create([
            'complex_id' => $this->complex->id,
            'period' => $this->period,
            'recipients' => 1,
            'template' => 'x',
        ]);
    }

    // ── قید ۳: فقط بدهکاران ────────────────────────────────────────────────

    public function test_a_settled_unit_gets_no_message(): void
    {
        $this->debtor(500000, 500000, '1');   // تسویه‌شده
        $this->debtor(500000, 0, '2');        // بدهکار
        $this->recordExpense();

        $this->assertSame(1, $this->campaignStatus()->json('recipientCount'));
    }

    public function test_a_partially_paid_unit_is_still_reminded(): void
    {
        $this->debtor(500000, 200000);
        $this->recordExpense();

        $this->assertSame(1, $this->campaignStatus()->json('recipientCount'));
        $this->assertEqualsWithDelta(300000.0, $this->campaignStatus()->json('totalDebt'), 0.01);
    }

    /**
     * ⚠️ این تست هم پس از خرابکاریِ عمدی اضافه شد.
     *
     * فیلترِ `status != paid` در کوئری، حالتِ «تسویه‌شده» را می‌گرفت، پس
     * بررسیِ `debt <= 0` هیچ تستی نداشت. ولی وضعیتِ قبض می‌تواند **کهنه**
     * باشد (پرداخت ثبت شده و وضعیت هنوز به‌روز نشده)؛ در آن حالت تنها
     * همین بررسی جلوی پیامکِ اشتباه به کسی را می‌گیرد که پولش را داده.
     */
    public function test_a_fully_paid_bill_with_a_stale_status_is_still_skipped(): void
    {
        $this->debtor(500000, 500000, '1');
        Bill::query()->update(['status' => BillStatus::Unpaid->value]);
        $this->recordExpense();

        $this->assertSame(0, $this->campaignStatus()->json('recipientCount'));
    }

    public function test_a_resident_who_turned_the_sms_off_is_skipped(): void
    {
        $resident = $this->debtor();
        $this->recordExpense();

        app(NotificationPreferences::class)
            ->set($resident, NotificationChannelKey::SmsReminder, false);

        $this->assertSame(0, $this->campaignStatus()->json('recipientCount'));
        $this->send()->assertStatus(422);
    }

    public function test_an_inactive_resident_is_skipped(): void
    {
        $resident = $this->debtor();
        $this->recordExpense();
        $resident->update(['is_active' => false]);

        $this->assertSame(0, $this->campaignStatus()->json('recipientCount'));
    }

    public function test_a_bill_from_another_period_does_not_create_recipients(): void
    {
        $this->debtor();
        $this->recordExpense();
        Bill::query()->update(['period' => '1400-01']);

        // قبضِ دوره‌ی دیگر نه گیرنده می‌سازد و نه شرطِ صدور را برآورده می‌کند
        $this->assertSame(0, $this->campaignStatus()->json('recipientCount'));
        $this->assertStringContainsString('قبض‌های', $this->campaignStatus()->json('blockReason'));
    }

    // ── دسترسی و جداسازی ───────────────────────────────────────────────────

    public function test_a_resident_cannot_reach_the_campaign(): void
    {
        $resident = $this->makeUser();

        $this->actingAs($resident)->getJson('/api/v1/sms-campaign')->assertForbidden();
        $this->actingAs($resident)->postJson('/api/v1/sms-campaign')->assertForbidden();
    }

    public function test_a_manager_only_reminds_their_own_complex(): void
    {
        $this->debtor();
        $this->recordExpense();

        // مجتمعِ دیگری با بدهکارِ خودش
        $other = Complex::factory()->create();
        $otherUnit = Unit::factory()->create(['complex_id' => $other->id]);
        $otherResident = User::factory()->create([
            'complex_id' => $other->id,
            'is_active' => true,
            'phone' => '09990000001',
        ]);
        $otherUnit->residents()->attach($otherResident->id, [
            'relation' => ResidentRelation::Owner->value,
            'complex_id' => $other->id,
            'is_current' => true,
        ]);
        Bill::create([
            'complex_id' => $other->id,
            'unit_id' => $otherUnit->id,
            'period' => $this->period,
            'base_amount' => 900000,
            'total_amount' => 900000,
            'due_date' => now()->addDays(10),
        ]);

        $this->assertSame(1, $this->campaignStatus()->json('recipientCount'));
    }

    // ── متنِ پیام و گزارش ──────────────────────────────────────────────────

    public function test_the_message_carries_the_complex_unit_and_amount(): void
    {
        $this->debtor(750000, 0, '۱۲');
        $this->recordExpense();

        $preview = $this->campaignStatus()->json('preview');

        $this->assertStringContainsString($this->complex->name, $preview);
        $this->assertStringContainsString('واحد', $preview);
        $this->assertStringContainsString(Jalali::money(750000), $preview);
    }

    public function test_the_sent_template_is_kept_for_the_record(): void
    {
        $this->debtor();
        $this->recordExpense();
        $this->send()->assertOk();

        $history = $this->campaignStatus()->json('history');

        $this->assertCount(1, $history);
        $this->assertNotEmpty($history[0]['template']);
        $this->assertSame($this->manager->name, $history[0]['sentBy']);
    }

    public function test_one_phone_is_never_messaged_twice(): void
    {
        $resident = $this->debtor(500000, 0, '1');

        // همان شخص، ساکنِ واحد دوم هم هست
        $second = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '2']);
        $second->residents()->attach($resident->id, [
            'relation' => ResidentRelation::Owner->value,
            'complex_id' => $this->complex->id,
            'is_current' => true,
        ]);
        Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $second->id,
            'period' => $this->period,
            'base_amount' => 400000,
            'total_amount' => 400000,
            'due_date' => now()->addDays(10),
        ]);

        $this->recordExpense();

        $this->assertSame(1, $this->campaignStatus()->json('recipientCount'));
    }
}
