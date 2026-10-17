<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\ResidentRelation;
use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\BillDueReminderNotification;
use App\Notifications\FeedbackReplyNotification;
use App\Notifications\JoinRequestNotification;
use App\Notifications\NewDeviceLoginNotification;
use App\Notifications\PaymentReviewedNotification;
use App\Notifications\PhoneChangedNotification;
use App\Notifications\ServiceRequestNotification;
use App\Notifications\SystemHealthNotification;
use App\Services\Account\MembershipLedger;
use App\Services\Units\TenureService;
use App\Support\SessionContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * جداییِ مجتمع‌ها برای حسابِ چندمجتمعی — مرحلهٔ ۱۲ · فاز ۴ (بندهای ۱۹ و ۲۰ ِ سندِ هسته).
 *
 * جاروی مسیرها (`BolaSweepTest`) از ۱۲/۴ دو مهاجمِ چندمجتمعی دارد؛ این‌جا تنها شکافی که ۱۲/۴ پیدا کرد:
 * **اعلانِ شخصی** مالِ حساب بود نه مجتمع. سارا مدیرِ الف و مالکِ واحدی در ب است؛ در داشبوردِ واحدِ ب درخواستِ عضویتِ
 * الف (نامِ متقاضی) و در داشبوردِ مدیرِ الف قبضِ ب را می‌دید. حالا اعلانِ مجتمعی فقط در زمینه‌ی همان مجتمع دیده، شمرده و
 * خوانده می‌شود و اعلانِ حسابی (دستگاهِ تازه) همه‌جا.
 */
class CommunityIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    private User $sara;

    private ComplexInvitation $joinA;

    private Bill $billB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'مجتمع الف']);
        $this->b = Complex::factory()->create(['name' => 'مجتمع ب']);
        $this->sara = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09127770201']);
        app(MembershipLedger::class)->start($this->sara, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT, activate: false);
        $unitB = Unit::factory()->create(['complex_id' => $this->b->id]);
        app(TenureService::class)->open($unitB, $this->sara, ResidentRelation::Owner, 100, now()->subYear());

        $applicant = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'phone' => '09127770202']);
        $this->joinA = ComplexInvitation::create([
            'complex_id' => $this->a->id, 'user_id' => $applicant->id, 'phone' => $applicant->phone, 'role' => UserRole::Owner,
            'direction' => ComplexInvitation::REQUEST, 'invited_by' => $applicant->id, 'status' => ComplexInvitation::PENDING,
        ]);
        $this->billB = Bill::withoutGlobalScopes()->create([
            'complex_id' => $this->b->id, 'unit_id' => $unitB->id, 'period' => '1405-07', 'base_amount' => 500000,
            'total_amount' => 500000, 'paid_amount' => 0, 'status' => BillStatus::Unpaid, 'due_date' => now()->subDays(5),
        ]);

        $this->sara->notify(new JoinRequestNotification($this->joinA, 'متقاضیِ الف'));
        $this->sara->notify(new BillDueReminderNotification($this->billB, 5));
        $this->sara->notify(new NewDeviceLoginNotification('Firefox', '10.0.0.1'));
    }

    /** 🔴 هر مجتمع اعلانِ خودش را دارد؛ اعلانِ حسابی در هر دو. */
    public function test_each_communitys_notifications_stay_in_its_own_context(): void
    {
        $join = 'درخواستِ تازه‌ی عضویت';
        $bill = 'قبض پرداخت‌نشده دارید';
        $device = 'ورود از دستگاهی تازه';

        foreach ([[$this->a, [$join, $device], $bill], [$this->b, [$bill, $device], $join]] as [$complex, $seen, $hidden]) {
            foreach (['/api/v1/notifications?limit=10', '/api/v1/notifications/history'] as $url) {
                $response = $this->in($complex)->getJson($url)->assertOk()->assertJsonPath('unreadCount', 2);
                $titles = collect($response->json('items'))->where('kind', 'personal')->pluck('title')->all();

                sort($seen);
                sort($titles);
                $this->assertSame($seen, $titles, "{$url} در زمینه‌ی {$complex->name}");
                $this->assertStringNotContainsString($hidden, $this->text($response->json()));
            }
        }
    }

    /**
     * هر کلاسِ اعلان یک تصمیمِ صریح دارد: مجتمعی (با `complexId` ِ همان رکوردِ مبدأ) یا حسابی (بی آن). کلاسِ تازه بی
     * تصمیم این آزمون را قرمز می‌کند — اعلانِ مجتمعیِ بی `complexId` همان نشتِ پیش از ۱۲/۴ است.
     */
    public function test_every_notification_class_is_either_community_or_account_scoped(): void
    {
        $payment = Payment::factory()->create(['complex_id' => $this->b->id, 'unit_id' => $this->billB->unit_id, 'bill_id' => $this->billB->id, 'user_id' => $this->sara->id]);
        $request = ServiceRequest::withoutGlobalScopes()->create([
            'complex_id' => $this->a->id, 'unit_id' => Unit::factory()->create(['complex_id' => $this->a->id])->id, 'user_id' => $this->sara->id,
            'category' => ServiceRequestCategory::Facilities, 'priority' => ServiceRequestPriority::Normal,
            'status' => ServiceRequestStatus::New, 'title' => 'ت', 'description' => 'ش',
        ]);

        $community = [
            BillDueReminderNotification::class => [new BillDueReminderNotification($this->billB, 3), $this->b->id],
            JoinRequestNotification::class => [new JoinRequestNotification($this->joinA, 'م'), $this->a->id],
            PaymentReviewedNotification::class => [new PaymentReviewedNotification($payment, true), $this->b->id],
            ServiceRequestNotification::class => [new ServiceRequestNotification($request, 'ت', 'ش'), $this->a->id],
        ];
        $account = [
            NewDeviceLoginNotification::class => new NewDeviceLoginNotification('Firefox', null),
            PhoneChangedNotification::class => new PhoneChangedNotification('09127770299'),
            SystemHealthNotification::class => new SystemHealthNotification('ok', []),
            // پاسخِ بازخورد: سکو به حساب پاسخ می‌دهد، نه مجتمع — بی نمونه‌سازی چون مدلِ بازخورد لازم دارد
            FeedbackReplyNotification::class => null,
        ];

        foreach ($community as $class => [$notification, $complexId]) {
            $this->assertSame($complexId, $notification->toArray($this->sara)['complexId'] ?? null, $class);
        }
        foreach (array_filter($account) as $class => $notification) {
            $this->assertArrayNotHasKey('complexId', $notification->toArray($this->sara), $class);
        }

        $declared = collect(glob(app_path('Notifications/*.php')))
            ->map(fn (string $file) => 'App\\Notifications\\'.basename($file, '.php'))->sort()->values()->all();
        $decided = collect([...array_keys($community), ...array_keys($account)])->sort()->values()->all();
        $this->assertSame($declared, $decided, 'کلاسِ اعلانِ تازه بی تصمیمِ «مجتمعی یا حسابی».');
    }

    /** خواندن هم در همان زمینه: اعلانِ الف در ب نه خوانده می‌شود نه با «همه را خواندم» بلعیده. */
    public function test_reading_follows_the_context(): void
    {
        $joinId = $this->sara->notifications()->get()->first(fn ($n) => ($n->data['joinRequestId'] ?? null) === $this->joinA->id)->id;

        $this->in($this->b)->postJson("/api/v1/notifications/personal/{$joinId}/read")->assertNotFound();
        $this->in($this->b)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('marked', 2)->assertJsonPath('unreadCount', 0);

        $this->assertNull($this->sara->notifications()->whereKey($joinId)->value('read_at'), 'اعلانِ الف در زمینه‌ی ب خوانده شد.');
        $this->in($this->a)->getJson('/api/v1/notifications/history')->assertJsonPath('unreadCount', 1);
        $this->in($this->a)->postJson("/api/v1/notifications/personal/{$joinId}/read")->assertOk()->assertJsonPath('unreadCount', 0);
    }

    /** پس از پایانِ مدیریت در الف، اعلانِ الف دیگر در هیچ زمینه‌ای نیست؛ اعلانِ حسابی می‌ماند. */
    public function test_a_former_manager_no_longer_sees_that_communitys_notifications(): void
    {
        app(MembershipLedger::class)->end($this->sara, ComplexMembership::END_REMOVED, null, $this->a->id);

        $body = $this->text($this->in($this->b)->getJson('/api/v1/notifications/history')->assertOk()->json());
        $this->assertStringNotContainsString('درخواستِ تازه‌ی عضویت', $body);
        $this->assertStringContainsString('ورود از دستگاهی تازه', $body);
        $this->assertSame(3, $this->sara->notifications()->count(), 'پایانِ عضویت تاریخچه‌ی اعلان را پاک نمی‌کند.');
    }

    /** ردیف‌های پیش از ۱۲/۴ مجتمعشان را از رکوردِ مبدأ می‌گیرند؛ اعلانِ حسابی و مبدأِ ناپیدا دست نمی‌خورند. */
    public function test_the_backfill_gives_old_notifications_their_community(): void
    {
        DB::table('notifications')->delete();
        $rows = [
            'join' => ['type' => 'join_request.received', 'title' => 'درخواستِ تازه‌ی عضویت', 'joinRequestId' => $this->joinA->id],
            'bill' => ['type' => 'bill.due_reminder', 'title' => 'قبض', 'billId' => $this->billB->id],
            'device' => ['type' => 'security.new_device', 'title' => 'دستگاه'],
            'orphan' => ['type' => 'service_request', 'title' => 'ناپیدا', 'serviceRequestId' => 999999],
        ];
        foreach ($rows as $key => $data) {
            DB::table('notifications')->insert([
                'id' => $key.'-'.Str::uuid(), 'type' => 'legacy', 'notifiable_type' => User::class, 'notifiable_id' => $this->sara->id,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_10_21_000001_scope_notifications_to_their_community.php');
        $migration->up();

        $after = DB::table('notifications')->get()->mapWithKeys(fn ($r) => [Str::before($r->id, '-') => json_decode($r->data, true)]);
        $this->assertSame($this->a->id, $after['join']['complexId']);
        $this->assertSame($this->b->id, $after['bill']['complexId']);
        $this->assertArrayNotHasKey('complexId', $after['device']);
        $this->assertArrayNotHasKey('complexId', $after['orphan']);
        $this->assertSame('درخواستِ تازه‌ی عضویت', $after['join']['title'], 'متنِ فارسی دست نخورد.');

        $migration->down();
        $this->assertTrue(DB::table('notifications')->get()->every(fn ($r) => ! array_key_exists('complexId', json_decode($r->data, true))));
    }

    /**
     * متنِ پاسخ **بی‌گریز**: `getContent()` فارسی را `\uXXXX` می‌دهد و «شامل نیست» روی آن همیشه درست است —
     * دورِ اولِ همین آزمون همین را نشان داد.
     */
    private function text(mixed $json): string
    {
        return (string) json_encode($json, JSON_UNESCAPED_UNICODE);
    }

    /** همان سنجاقی که ورود یا تعویض در این مرورگر می‌گذارد (`SessionContext`). */
    private function in(Complex $complex): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();
        $this->defaultCookies = [];
        $this->withCredentials();
        $this->withCookie(SessionContext::COOKIE, $this->sara->id.':'.$complex->id);

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($this->sara->id));
    }
}
