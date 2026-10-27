<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Calendar\ReminderDispatcher;
use App\Support\SessionContext;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تقویم و یادآورها — مرحلهٔ ۱۳ · فاز ۷ (ایرادات ۱۰، بندهای ۷۷ تا ۱۰۲).
 *
 * از سرور: تاریخِ رویداد ≠ لحظه‌ی یادآوری، منطقه‌ی تهران، تکرار (تا تاریخ، چند بار، بی‌پایان)، «فقط همین / همین و
 * بعدی‌ها / کل»، تاریخچه، حذفِ تکی و همه، زمان‌بندِ بی تکرار، سناریوی الزامیِ A/B ِ بندِ ۱۰۲، و IDOR.
 *
 * «امروز» ِ آزمون‌ها ثابت است: ۲۰۲۶-۱۰-۲۷، ۱۰:۰۰ ِ UTC = ۱۳:۳۰ ِ تهران (۵ آبان ۱۴۰۵).
 */
class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00', 'UTC'));

        $this->a = Complex::factory()->create(['name' => 'مجتمع آفتاب']);
        $this->b = Complex::factory()->create(['name' => 'مجتمع نفیس']);
        // یک Account، دو زمینه: مدیرِ الف، مالکِ ب
        $this->sara = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        app(MembershipLedger::class)->start($this->sara, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT, activate: false);
    }

    /* ── ساخت: تاریخِ رویداد و زمانِ یادآوری جدا (بندهای ۷۷ تا ۸۰ و ۱۰۰) ───────────────────── */

    public function test_the_event_date_and_the_reminder_moment_are_kept_apart_in_tehran_time(): void
    {
        // جلسه‌ی هیئت‌مدیره ۳۰ مهر… این‌جا ۲۹ آبان ۱۴۰۵؛ سه روز قبل، ساعتِ ۰۹:۰۰ ِ تهران
        $created = $this->in($this->a)->postJson('/api/v1/reminders', $this->payload([
            'title' => 'جلسه هیئت‌مدیره', 'target_date' => '2026-11-20', 'lead_days' => 3, 'remind_time' => '09:00',
        ]))->assertCreated()->json('reminder');

        $this->assertSame('۲۹ آبان ۱۴۰۵', $created['targetDateLabel']);
        $this->assertSame('۲۶ آبان ۱۴۰۵', $created['remindDateLabel']);
        $this->assertSame('یک‌باره', $created['repeatLabel']);
        $this->assertSame('زمان‌بندی‌شده', $created['statusLabel']);

        $reminder = Reminder::sole();
        // ۰۹:۰۰ ِ تهران = ۰۵:۳۰ ِ UTC؛ روز جابه‌جا نمی‌شود
        $this->assertSame('2026-11-17 05:30:00', $reminder->next_remind_at->toDateTimeString());
        $this->assertSame($this->a->id, $reminder->complex_id);
        $this->assertSame($this->sara->id, $reminder->user_id);
    }

    /** تاریخِ دلخواهِ یادآوری همان «چند روز قبل» می‌شود؛ همان روز هم مجاز */
    public function test_a_custom_reminder_date_and_a_same_day_reminder(): void
    {
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-11-20', 'remind_on' => '2026-11-13', 'lead_days' => null]))
            ->assertCreated()->assertJsonPath('reminder.leadDays', 7);
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-10-27', 'lead_days' => 0, 'remind_time' => '18:00']))
            ->assertCreated()->assertJsonPath('reminder.remindDateLabel', '۵ آبان ۱۴۰۵');
    }

    /** گذشته پذیرفته نمی‌شود: رویدادِ دیروز، یادآوریِ یک‌باره‌ای که لحظه‌اش رفته، یادآوری پس از رویداد */
    public function test_past_events_past_reminder_moments_and_reminders_after_the_event_are_refused(): void
    {
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-10-26']))->assertJsonValidationErrors('target_date');
        // امروز ۰۹:۰۰ ِ تهران گذشته (اکنون ۱۳:۳۰)
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-10-27', 'lead_days' => 0, 'remind_time' => '09:00']))
            ->assertJsonValidationErrors('remind_time');
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-11-20', 'remind_on' => '2026-11-21', 'lead_days' => null]))
            ->assertJsonValidationErrors('remind_on');
        $this->assertSame(0, Reminder::count());
    }

    /* ── تکرار (بندهای ۸۳ و ۸۴؛ تصمیمِ ۲.۳-۸) ─────────────────────────────────────────── */

    public function test_recurrence_every_n_days_until_a_date_a_count_or_forever(): void
    {
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['title' => 'تا تاریخ', 'target_date' => '2026-11-01', 'repeat_every_days' => 2, 'repeat_end' => 'until', 'repeat_until' => '2026-11-07']))->assertCreated();
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['title' => 'سه بار', 'target_date' => '2026-11-01', 'repeat_every_days' => 3, 'repeat_end' => 'count', 'repeat_count' => 3]))->assertCreated();
        $forever = $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['title' => 'بی‌پایان', 'target_date' => '2026-11-01', 'repeat_every_days' => 7, 'repeat_end' => 'none']))
            ->assertCreated()->json('reminder');
        $this->assertSame('هر ۷ روز، بی‌پایان', $forever['repeatLabel']);

        $november = collect($this->in($this->a)->getJson('/api/v1/reminders?from=2026-11-01&to=2026-11-30')->assertOk()->json('data'))
            ->groupBy('title')->map(fn ($rows) => $rows->pluck('date')->all());
        $this->assertSame(['2026-11-01', '2026-11-03', '2026-11-05', '2026-11-07'], $november['تا تاریخ']);
        $this->assertSame(['2026-11-01', '2026-11-04', '2026-11-07'], $november['سه بار']);
        $this->assertSame(['2026-11-01', '2026-11-08', '2026-11-15', '2026-11-22', '2026-11-29'], $november['بی‌پایان']);

        // بی‌پایان واقعاً بی‌پایان: پنج سال بعد هم نمونه دارد (یکشنبه‌های آبانِ ۲۰۳۱)، بی ساختنِ ردیف
        $later = $this->in($this->a)->getJson('/api/v1/reminders?from=2031-11-01&to=2031-11-30')->json('data');
        $this->assertSame(['2031-11-02', '2031-11-09', '2031-11-16', '2031-11-23', '2031-11-30'], array_column($later, 'date'));
        $this->assertSame(3, Reminder::count());

        // بازه‌ی پرسش سقف دارد: سریِ بی‌پایان برای سال‌ها باز نمی‌شود؛ بازه‌ی وارونه هم نه
        $this->in($this->a)->getJson('/api/v1/reminders?from=2026-11-01&to=2027-01-15')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->in($this->a)->getJson('/api/v1/reminders?from=2026-11-30&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->in($this->a)->getJson('/api/v1/reminders?from=2026-11-01&to=2027-01-02')->assertOk();
    }

    /* ── ویرایش و حذفِ سری (بندهای ۸۱، ۸۲ و ۸۵) ──────────────────────────────────────── */

    public function test_editing_only_this_occurrence_or_this_and_the_following_ones(): void
    {
        $id = $this->series();

        // «فقط همین»: نمونه‌ی ۸ نوامبر کنار می‌رود و یادآورِ یک‌باره‌ی تازه، یک روز زودتر، جایش
        $this->in($this->a)->putJson("/api/v1/reminders/{$id}", $this->payload(['title' => 'فقط این بار', 'target_date' => '2026-11-07', 'scope' => 'this', 'occurrence' => '2026-11-08']))
            ->assertOk()->assertJsonPath('reminder.repeatLabel', 'یک‌باره');
        // «همین و بعدی‌ها» از ۱۵ آبان: سری پیش از آن بسته، سریِ تازه با عنوانِ تازه
        $this->in($this->a)->putJson("/api/v1/reminders/{$id}", $this->payload(['title' => 'سریِ تازه', 'target_date' => '2026-11-15', 'repeat_every_days' => 7, 'repeat_end' => 'none', 'scope' => 'following', 'occurrence' => '2026-11-15']))
            ->assertOk();

        $titles = collect($this->in($this->a)->getJson('/api/v1/reminders?from=2026-11-01&to=2026-11-30')->json('data'))
            ->mapWithKeys(fn ($o) => [$o['date'] => $o['title']])->all();
        $this->assertSame([
            '2026-11-01' => 'هفتگی', '2026-11-07' => 'فقط این بار',
            '2026-11-15' => 'سریِ تازه', '2026-11-22' => 'سریِ تازه', '2026-11-29' => 'سریِ تازه',
        ], $titles);
        // نمونه‌ی ادعاشده باید مالِ همین سری باشد
        $this->in($this->a)->putJson("/api/v1/reminders/{$id}", $this->payload(['scope' => 'this', 'occurrence' => '2026-11-02']))
            ->assertJsonValidationErrors('occurrence');
    }

    public function test_deleting_one_occurrence_the_following_ones_or_the_whole_series(): void
    {
        $id = $this->series();

        $this->in($this->a)->deleteJson("/api/v1/reminders/{$id}?scope=this&occurrence=2026-11-08")->assertOk();
        $this->assertSame(['2026-11-01', '2026-11-15', '2026-11-22', '2026-11-29'], $this->dates());

        $this->in($this->a)->deleteJson("/api/v1/reminders/{$id}?scope=following&occurrence=2026-11-22")->assertOk();
        $this->assertSame(['2026-11-01', '2026-11-15'], $this->dates());
        $this->assertSame(1, Reminder::count(), 'سریِ بسته‌شده سابقه است و حذف نشد.');

        $this->in($this->a)->deleteJson("/api/v1/reminders/{$id}")->assertOk();
        $this->assertSame([], $this->dates());
        $this->assertSame(0, Reminder::count());
    }

    /* ── تاریخچه (بندهای ۸۸ تا ۹۱) ──────────────────────────────────────────────────── */

    public function test_the_history_keeps_past_reminders_is_paginated_and_clears_only_this_context(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['title' => "یادآور {$i}", 'target_date' => '2026-11-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]))->assertCreated();
        }
        $this->in($this->b)->postJson('/api/v1/reminders', $this->payload(['title' => 'مالِ ب']))->assertCreated();

        // یادآورِ نخست فرستاده شد و گذشت — در تاریخچه می‌ماند
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));
        app(ReminderDispatcher::class)->dispatchDue();

        $first = $this->in($this->a)->getJson('/api/v1/reminders/history')->assertOk();
        $this->assertSame(['currentPage' => 1, 'lastPage' => 2, 'total' => 16], $first->json('meta'));
        foreach (['title', 'description', 'targetDateLabel', 'remindDateLabel', 'remindTime', 'statusLabel', 'repeatLabel', 'createdAt'] as $field) {
            $this->assertArrayHasKey($field, $first->json('data.0'));
        }
        $done = collect([...$first->json('data'), ...$this->in($this->a)->getJson('/api/v1/reminders/history?page=2')->json('data')])->firstWhere('title', 'یادآور 1');
        $this->assertSame('یادآوری شد', $done['statusLabel']);
        $this->assertNotContains('مالِ ب', collect($first->json('data'))->pluck('title')->all());

        // «حذفِ همه» فقط همین زمینه
        $this->in($this->a)->deleteJson('/api/v1/reminders')->assertOk()->assertJsonPath('deleted', 16);
        $this->assertSame(['مالِ ب'], Reminder::pluck('title')->all());
    }

    /* ── زمان‌بند و اعلان (بندهای ۸۶ و ۱۰۰) ─────────────────────────────────────────── */

    public function test_the_notification_arrives_at_the_reminder_moment_exactly_once(): void
    {
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload([
            'title' => 'تماس با پیمانکار', 'description' => 'قراردادِ نما', 'target_date' => '2026-11-20', 'lead_days' => 3, 'remind_time' => '09:00',
        ]))->assertCreated();
        $dispatcher = app(ReminderDispatcher::class);

        // یک دقیقه پیش از ۰۹:۰۰ ِ تهران هیچ؛ سرِ ساعت یکی؛ اجرای دوباره هیچ
        $this->assertSame(0, $dispatcher->dispatchDue(CarbonImmutable::parse('2026-11-17 05:29:00', 'UTC')));
        $this->assertSame(1, $dispatcher->dispatchDue(CarbonImmutable::parse('2026-11-17 05:30:00', 'UTC')));
        $this->assertSame(0, $dispatcher->dispatchDue(CarbonImmutable::parse('2026-11-17 05:31:00', 'UTC')));

        $notification = $this->sara->fresh()->notifications()->sole();
        $this->assertSame('یادآور: تماس با پیمانکار', $notification->data['title']);
        $this->assertStringContainsString('۲۹ آبان ۱۴۰۵', $notification->data['body']);
        $this->assertSame($this->a->id, $notification->data['complexId']);
        $this->assertSame('یادآوری شد', Reminder::sole()->statusLabel());
    }

    /** دو کارگرِ هم‌زمان: ردیفِ قفل‌شده نشانگرِ جلورفته دارد و دومی هیچ نمی‌فرستد */
    public function test_two_overlapping_runs_send_a_due_reminder_once(): void
    {
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-10-28', 'lead_days' => 0, 'remind_time' => '08:00']))->assertCreated();
        $at = CarbonImmutable::parse('2026-10-28 04:30:00', 'UTC');

        // اجرای دوم «درونِ» اولی شروع می‌شود؛ ردیف را پس از کارِ اولی می‌خواند
        DB::transaction(function () use ($at): void {
            app(ReminderDispatcher::class)->dispatchDue($at);
            app(ReminderDispatcher::class)->dispatchDue($at);
        });
        app(ReminderDispatcher::class)->dispatchDue($at->addMinutes(5));

        $this->assertSame(1, $this->sara->fresh()->notifications()->count());
    }

    /**
     * کارگرِ دومی که فهرستِ سررسیده را **پیش** از کارِ اولی برداشت: سری نشانگرش را به نمونه‌ی فردا برده (نه `null`)،
     * پس فقط سنجشِ دوباره‌ی زیرِ قفل جلوی ارسالِ دوم را می‌گیرد.
     */
    public function test_a_stale_due_id_is_not_sent_twice(): void
    {
        $id = $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-10-28', 'lead_days' => 0, 'remind_time' => '08:00', 'repeat_every_days' => 1, 'repeat_end' => 'none']))
            ->assertCreated()->json('reminder.id');
        $at = CarbonImmutable::parse('2026-10-28 04:30:00', 'UTC');
        $dispatcher = app(ReminderDispatcher::class);

        $this->assertSame(1, $dispatcher->deliver($id, $at));
        $this->assertSame('2026-10-29', Reminder::find($id)->next_occurrence->toDateString());
        $this->assertSame(0, $dispatcher->deliver($id, $at));
        $this->assertSame(1, $this->sara->fresh()->notifications()->count());
    }

    /** سرورِ خاموش: در بازگشت فقط یک یادآور، نه یکی برای هر روزِ ازدست‌رفته؛ سری از اکنون جلو می‌رود */
    public function test_after_downtime_a_daily_series_sends_once_and_moves_on(): void
    {
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-10-28', 'lead_days' => 0, 'repeat_every_days' => 1, 'repeat_end' => 'none', 'remind_time' => '08:00']))->assertCreated();

        $back = CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC');
        $this->assertSame(1, app(ReminderDispatcher::class)->dispatchDue($back));
        $this->assertSame(0, app(ReminderDispatcher::class)->dispatchDue($back));
        $this->assertSame('2026-11-03', Reminder::sole()->next_occurrence->toDateString());
    }

    /** زمینه‌ای که دیگر مالِ صاحبش نیست اعلان نمی‌گیرد */
    public function test_a_reminder_of_an_ended_membership_is_not_delivered(): void
    {
        $this->in($this->b)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2026-10-28', 'lead_days' => 0, 'remind_time' => '08:00']))->assertCreated();
        app(Membership::class)->end($this->sara, null, $this->b->id);

        $this->assertSame(0, app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::parse('2026-10-28 05:00:00', 'UTC')));
        $this->assertNull(Reminder::sole()->next_remind_at, 'سری جلو نرفت و هر دقیقه دوباره سنجیده می‌شد.');
    }

    /* ── بندهای ۸۷، ۹۹ و ۱۰۲: سناریوی الزامیِ A/B ───────────────────────────────────── */

    public function test_a_reminder_of_community_a_never_appears_in_b_and_the_other_way_round(): void
    {
        $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['title' => 'یادآور A', 'target_date' => '2026-11-05']))->assertCreated();
        $this->in($this->b)->postJson('/api/v1/reminders', $this->payload(['title' => 'یادآور B', 'target_date' => '2026-11-05']))->assertCreated();
        $range = '/api/v1/reminders?from=2026-11-01&to=2026-11-30';

        $this->assertSame(['یادآور A'], collect($this->in($this->a)->getJson($range)->json('data'))->pluck('title')->all());
        $this->assertSame(['یادآور B'], collect($this->in($this->b)->getJson($range)->json('data'))->pluck('title')->all());
        $this->assertSame(['یادآور A'], collect($this->in($this->a)->getJson('/api/v1/reminders/history')->json('data'))->pluck('title')->all());

        // و اعلان‌ها: هر کدام فقط در زمینه‌ی خودش
        app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::parse('2026-11-05 06:00:00', 'UTC'));
        $this->assertSame(['یادآور: یادآور A'], collect($this->in($this->a)->getJson('/api/v1/notifications')->json('items'))->pluck('title')->filter(fn ($t) => str_starts_with((string) $t, 'یادآور'))->values()->all());
        $this->assertSame(['یادآور: یادآور B'], collect($this->in($this->b)->getJson('/api/v1/notifications')->json('items'))->pluck('title')->filter(fn ($t) => str_starts_with((string) $t, 'یادآور'))->values()->all());
    }

    /* ── بندِ ۹۸ و ۱۱۲: دسترسی ──────────────────────────────────────────────────────── */

    public function test_another_account_or_the_other_context_gets_a_plain_404(): void
    {
        $id = $this->in($this->a)->postJson('/api/v1/reminders', $this->payload())->json('reminder.id');
        $neighbour = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::Owner, 'is_active' => true]);

        foreach ([[$neighbour, $this->a], [$this->sara, $this->b]] as [$user, $complex]) {
            $this->in($complex, $user)->getJson("/api/v1/reminders/{$id}")->assertNotFound();
            $this->in($complex, $user)->putJson("/api/v1/reminders/{$id}", $this->payload(['title' => 'دستکاری']))->assertNotFound();
            $this->in($complex, $user)->deleteJson("/api/v1/reminders/{$id}")->assertNotFound();
        }
        // بدنه‌ی نامعتبر هم ۴۰۴، نه ۴۲۲ — وگرنه ۴۲۲/۴۰۴ وجودِ شناسه‌ی دیگری را لو می‌دهد (جاروی BOLA ِ ۱۳/۷)
        $this->in($this->a, $neighbour)->putJson("/api/v1/reminders/{$id}", [])->assertNotFound();
        $this->in($this->a, $neighbour)->deleteJson("/api/v1/reminders/{$id}", ['scope' => 'bogus'])->assertNotFound();
        // `complex_id` ِ درخواست نادیده: یادآور در زمینه‌ی سرور ساخته می‌شود
        $this->in($this->b)->postJson('/api/v1/reminders', $this->payload(['complex_id' => $this->a->id]))->assertCreated()
            ->assertJsonPath('reminder.title', 'یادآور');
        $this->assertSame($this->b->id, Reminder::latest('id')->first()->complex_id);
        $this->assertSame('یادآور', Reminder::find($id)->title);
    }

    /** بندِ ۶۹: داشبوردِ اولیه تقویم ندارد؛ سوپر ادمین در زمینه‌ی سکو (بندِ ۶۸) */
    public function test_the_initial_account_has_no_calendar_and_the_super_admin_uses_the_platform_context(): void
    {
        $initial = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true]);
        $this->in(null, $initial)->getJson('/api/v1/reminders?from=2026-11-01&to=2026-11-30')->assertForbidden()->assertJsonPath('code', 'calendar.initial_account');
        $this->in(null, $initial)->postJson('/api/v1/reminders', $this->payload())->assertForbidden();

        $super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
        $this->in(null, $super)->postJson('/api/v1/reminders', $this->payload(['title' => 'سکو']))->assertCreated();
        $this->assertNull(Reminder::where('user_id', $super->id)->sole()->complex_id);
        $this->assertSame(['سکو'], collect($this->in(null, $super)->getJson('/api/v1/reminders/history')->json('data'))->pluck('title')->all());
    }

    /** بندِ ۷۳: کبیسه و مرزِ سال در خروجیِ سرور — ۳۰ اسفندِ ۱۴۰۳ هست، ۱ فروردینِ ۱۴۰۴ فردای آن */
    public function test_leap_years_and_the_year_boundary_are_labelled_correctly(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-03-01 10:00:00', 'UTC'));
        $made = $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['target_date' => '2025-03-20', 'repeat_every_days' => 1, 'repeat_end' => 'count', 'repeat_count' => 2]))
            ->assertCreated()->json('reminder');
        $this->assertSame('۳۰ اسفند ۱۴۰۳', $made['targetDateLabel']);

        $labels = collect($this->in($this->a)->getJson('/api/v1/reminders?from=2025-03-19&to=2025-03-22')->json('data'))->pluck('dateLabel')->all();
        $this->assertSame(['۳۰ اسفند ۱۴۰۳', '۱ فروردین ۱۴۰۴'], $labels);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────────────────── */

    /** @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'یادآور', 'description' => null, 'target_date' => '2026-11-10', 'lead_days' => 1, 'remind_time' => '09:00',
            ...$overrides,
        ];
    }

    /** سریِ هفتگیِ بی‌پایان از ۱۰ آبان ۱۴۰۵ (۱ نوامبر) */
    private function series(): int
    {
        return (int) $this->in($this->a)->postJson('/api/v1/reminders', $this->payload(['title' => 'هفتگی', 'target_date' => '2026-11-01', 'repeat_every_days' => 7, 'repeat_end' => 'none']))
            ->assertCreated()->json('reminder.id');
    }

    /** @return list<string> */
    private function dates(): array
    {
        return collect($this->in($this->a)->getJson('/api/v1/reminders?from=2026-11-01&to=2026-11-30')->json('data'))->pluck('date')->all();
    }

    /** مرورگری در زمینه‌ی یک مجتمع (سنجاقِ `SessionContext`)، یا بی‌زمینه */
    private function in(?Complex $complex, ?User $user = null): static
    {
        $user ??= $this->sara;
        app(TenantContext::class)->set(null);
        $this->flushSession();
        $this->defaultCookies = [];
        $this->withCredentials();
        if ($complex !== null) {
            $this->withCookie(SessionContext::COOKIE, $user->id.':'.$complex->id);
        }

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($user->id));
    }
}
