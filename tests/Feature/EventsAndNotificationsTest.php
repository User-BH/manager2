<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\PaymentReviewed;
use App\Listeners\SendPaymentReviewedNotification;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\OtpCode;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * رویدادها، شنونده‌ها، Observer و اعلان‌ها (R12).
 */
class EventsAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع رویداد', 'slug' => 'ev-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::ComplexAdmin,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);
    }

    /* ── Observer: ردِ حذف ─────────────────────────────────────────────── */

    public function test_deleting_a_record_is_logged_without_the_controller_asking(): void
    {
        $this->actingAs($this->admin);

        $unit = $this->makeUnit('۱۲');
        $unit->delete();

        $log = AuditLog::where('action', 'unit.deleted')->latest('id')->first();

        // هیچ کنترلری اینجا دخیل نیست؛ Observer از چرخه‌ی عمرِ مدل می‌گیردش
        $this->assertNotNull($log, 'حذف واحد باید خودکار در لاگ ثبت شود');
    }

    public function test_the_log_records_which_record_was_deleted_not_just_that_one_was(): void
    {
        $this->actingAs($this->admin);

        $unit = $this->makeUnit('۷');
        $unit->delete();

        $log = AuditLog::where('action', 'unit.deleted')->latest('id')->first();

        /*
         * پس از حذف، رکورد دیگر قابلِ بازیابی نیست. اگر لاگ فقط شناسه داشت،
         * ادمین می‌دید «واحدی حذف شد» بی‌آنکه بفهمد کدام.
         */
        $this->assertNotEmpty($log->properties);
        $this->assertSame('۷', $log->properties['unit_number']);
    }

    public function test_high_churn_models_are_not_logged(): void
    {
        $this->actingAs($this->admin);

        $before = AuditLog::count();

        // این‌ها مدام ساخته و پاک می‌شوند؛ لاگ‌کردنشان رویدادهای مهم را دفن می‌کرد
        OtpCode::create([
            'phone' => '09120000000',
            'code_hash' => hash('sha256', '123456'),
            'expires_at' => now()->addMinutes(2),
        ])->delete();

        $this->assertSame($before, AuditLog::count());
    }

    /* ── رویداد و شنونده ───────────────────────────────────────────────── */

    public function test_settling_a_payment_dispatches_the_event(): void
    {
        Event::fake([PaymentReviewed::class]);

        $payment = $this->makePayment();
        app(PaymentService::class)->settle($payment, $this->admin, 'تایید');

        Event::assertDispatched(
            PaymentReviewed::class,
            fn (PaymentReviewed $event) => $event->approved === true && $event->payment->is($payment),
        );
    }

    public function test_rejecting_a_payment_dispatches_the_event_as_not_approved(): void
    {
        Event::fake([PaymentReviewed::class]);

        $payment = $this->makePayment();
        app(PaymentService::class)->reject($payment, $this->admin, 'ناخوانا');

        Event::assertDispatched(
            PaymentReviewed::class,
            fn (PaymentReviewed $event) => $event->approved === false,
        );
    }

    public function test_the_listener_notifies_the_resident_who_sent_the_receipt(): void
    {
        $payment = $this->makePayment();

        (new SendPaymentReviewedNotification)->handle(
            new PaymentReviewed($payment, approved: true, note: null),
        );

        $resident = $payment->user;
        $this->assertSame(1, $resident->notifications()->count());
        $this->assertSame('payment.approved', $resident->notifications()->first()->data['type']);
    }

    public function test_a_rejected_receipt_tells_the_resident_the_reason(): void
    {
        $payment = $this->makePayment();

        (new SendPaymentReviewedNotification)->handle(
            new PaymentReviewed($payment, approved: false, note: 'تصویر ناخوانا بود'),
        );

        $data = $payment->user->notifications()->first()->data;

        // بدون دلیل، کاربر نمی‌داند چه چیزی را باید اصلاح کند
        $this->assertStringContainsString('تصویر ناخوانا بود', $data['body']);
    }

    public function test_a_payment_without_a_user_does_not_crash_the_listener(): void
    {
        $payment = $this->makePayment();
        $payment->update(['user_id' => null]);

        // پرداختِ سیستمی یا کاربرِ حذف‌شده گیرنده‌ای ندارد
        (new SendPaymentReviewedNotification)->handle(
            new PaymentReviewed($payment->fresh(), approved: true),
        );

        $this->assertTrue(true);
    }

    /* ── زنگوله: یک فهرست از دو منبع ───────────────────────────────────── */

    public function test_the_bell_merges_announcements_and_personal_notifications(): void
    {
        $payment = $this->makePayment();
        $resident = $payment->user;

        (new SendPaymentReviewedNotification)->handle(new PaymentReviewed($payment, approved: true));

        $response = $this->actingAs($resident)->getJson('/api/v1/notifications?limit=5')->assertOk();

        // یک شمارنده و یک فهرست — نه دو فهرستِ موازی
        $this->assertSame(1, $response->json('unreadCount'));
        $this->assertSame('personal', $response->json('items.0.kind'));
    }

    public function test_marking_all_read_clears_both_sources(): void
    {
        $payment = $this->makePayment();
        $resident = $payment->user;

        (new SendPaymentReviewedNotification)->handle(new PaymentReviewed($payment, approved: true));

        $this->actingAs($resident)->postJson('/api/v1/notifications/read-all')->assertOk();

        /*
         * اگر «همه را خواندم» فقط یک منبع را پاک می‌کرد، شمارنده روی عددی گیر
         * می‌کرد که کاربر راهی برای صفرکردنش نداشت.
         */
        $this->assertSame(
            0,
            $this->actingAs($resident)->getJson('/api/v1/notifications')->json('unreadCount'),
        );
    }

    public function test_a_user_cannot_mark_someone_elses_notification_read(): void
    {
        $payment = $this->makePayment();
        (new SendPaymentReviewedNotification)->handle(new PaymentReviewed($payment, approved: true));

        $notificationId = $payment->user->notifications()->first()->id;
        $stranger = User::factory()->create(['is_active' => true]);

        $this->actingAs($stranger)
            ->postJson("/api/v1/notifications/personal/{$notificationId}/read")
            ->assertNotFound();
    }

    private function makeUnit(string $number): Unit
    {
        return Unit::create([
            'complex_id' => $this->complex->id,
            'unit_number' => $number,
            'floor' => 1,
            'area' => 80,
        ]);
    }

    private function makePayment(): Payment
    {
        $resident = User::factory()->create([
            'role' => UserRole::Owner,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);

        $unit = $this->makeUnit('۱');

        $bill = Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $unit->id,
            'period' => '1405-01',
            'total' => 500000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
        ]);

        return Payment::create([
            'complex_id' => $this->complex->id,
            'bill_id' => $bill->id,
            'unit_id' => $unit->id,
            'user_id' => $resident->id,
            'amount' => 500000,
            'method' => 'receipt',
            'status' => PaymentStatus::Pending,
        ]);
    }
}
