<?php

namespace Tests\Feature;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Feedback;
use App\Models\FeedbackReply;
use App\Models\User;
use App\Notifications\FeedbackReplyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * «بازخورد کاربران» و پاسخ به عضو (مرحلهٔ ۸ · فاز ۶ · بندهای ۱۲ تا ۱۴).
 *
 * عضو بودن فقط سمتِ سرور تشخیص داده می‌شود؛ پاسخ به‌صورتِ اعلانِ شخصی فقط به
 * همان کاربر می‌رسد و هیچ‌کسِ دیگری — با هیچ شناسه‌ای — آن را نمی‌بیند.
 */
class FeedbackReplyTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = '/api/v1/system/feedback';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true, 'phone' => '09120000001']);
        RateLimiter::clear('feedback-reply:'.$this->admin->id);
    }

    private function member(string $phone, string $name = 'ساکنِ عضو'): User
    {
        return User::factory()->create(['phone' => $phone, 'name' => $name, 'role' => UserRole::Owner, 'is_active' => true]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function feedback(array $overrides = []): Feedback
    {
        static $n = 0;
        $n++;

        return Feedback::create([
            'category' => FeedbackCategory::Bug,
            'message' => "پیامِ آزمونِ شماره‌ی {$n}",
            'name' => 'مریم',
            'phone' => '09123334444',
            'status' => FeedbackStatus::New,
            'message_hash' => hash('sha256', (string) $n),
            'sender_hash' => hash('sha256', 'ip'),
            ...$overrides,
        ]);
    }

    private function reply(Feedback $feedback, string $body = 'ممنون؛ مشکل برطرف شد.')
    {
        return $this->actingAs($this->admin)->postJson(self::INDEX."/{$feedback->id}/replies", ['body' => $body]);
    }

    /* ─────────────────────── بندِ ۱۳ و ۱۴: فهرست ─────────────────────── */

    public function test_the_list_says_who_is_a_member_with_date_and_time_apart(): void
    {
        $this->member('09123334444', 'نگین');
        $byPhone = $this->feedback(['phone' => '09123334444']);
        $stranger = $this->feedback(['phone' => '09129998888']);

        $rows = collect($this->actingAs($this->admin)->getJson(self::INDEX.'?status=new')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['name' => 'نگین', 'via' => 'phone'], $rows[$byPhone->id]['member']);
        $this->assertNull($rows[$stranger->id]['member']);
        $this->assertMatchesRegularExpression('/^[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}$/u', $rows[$byPhone->id]['date']);
        $this->assertMatchesRegularExpression('/^[۰-۹]{2}:[۰-۹]{2}$/u', $rows[$byPhone->id]['time']);
        $this->assertSame([], $rows[$byPhone->id]['replies']);
    }

    /** بندِ ۱۳: «همه بی‌محدودیت از پایگاه‌داده» نه — و تعدادِ پرس‌وجو با تعدادِ سطر بالا نمی‌رود */
    public function test_the_list_is_paginated_and_free_of_n_plus_one(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->getJson(self::INDEX)->assertOk();

            return count(DB::getQueryLog());
        };

        $this->feedback(['phone' => '09121110001']);
        $count(); // گرم‌کردن: نشست و احرازِ هویت بارِ اول پرس‌وجوی خودشان را دارند
        $few = $count();

        for ($i = 2; $i <= 25; $i++) {
            $this->member(sprintf('091211100%02d', $i));
            $this->feedback(['phone' => sprintf('091211100%02d', $i)]);
        }
        $many = $count();

        $this->assertSame($few, $many, "پرس‌وجو با سطرها زیاد شد: {$few} ← {$many}");
        $page = $this->actingAs($this->admin)->getJson(self::INDEX)->json();
        $this->assertCount(20, $page['data']);
        $this->assertSame(2, $page['meta']['lastPage']);
    }

    /* ─────────────────────── بندِ ۱۴: پاسخ ─────────────────────── */

    public function test_a_member_gets_the_reply_as_their_own_notification(): void
    {
        $user = $this->member('09123334444');
        $feedback = $this->feedback();

        $this->reply($feedback)->assertCreated()->assertJsonPath('feedback.replies.0.body', 'ممنون؛ مشکل برطرف شد.');

        $reply = FeedbackReply::sole();
        $this->assertSame([$feedback->id, $this->admin->id, $user->id], [$reply->feedback_id, $reply->author_id, $reply->recipient_id]);
        // صف با «تازه» باز می‌شود؛ پاسخ پیام را از جلوی چشمِ ادمین برنمی‌دارد
        $this->assertSame(FeedbackStatus::New, $feedback->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'feedback.replied']);

        $notification = $user->notifications()->sole();
        $this->assertSame(FeedbackReplyNotification::class, $notification->type);
        $this->assertSame('ممنون؛ مشکل برطرف شد.', $notification->data['body']);
        $this->assertNull($notification->read_at);

        // در اعلان‌های خودش، نخوانده؛ با خواندن، خوانده
        $history = $this->actingAs($user)->getJson('/api/v1/notifications/history')->assertOk()->json('items');
        $item = collect($history)->firstWhere('excerpt', 'ممنون؛ مشکل برطرف شد.');
        $this->assertNotNull($item);
        $this->assertFalse($item['isRead']);
        $this->assertSame('/notifications', $item['link']);

        $this->actingAs($user)->postJson("/api/v1/notifications/personal/{$notification->id}/read")->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_non_member_cannot_be_replied_to_in_the_site(): void
    {
        $feedback = $this->feedback(['phone' => '09129998888']);

        $this->reply($feedback)->assertUnprocessable()->assertJsonPath('code', 'feedback.not_a_member');

        $this->assertSame(0, FeedbackReply::count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    /**
     * فرستنده‌ی واردشده خودِ حسابش را دارد: پاسخ به او می‌رسد، نه به صاحبِ
     * شماره‌ای که در فرم نوشته — حتی اگر آن شماره مالِ عضوِ دیگری باشد.
     */
    public function test_the_signed_in_sender_wins_over_the_typed_phone(): void
    {
        $author = $this->member('09125550000', 'نویسنده');
        $phoneOwner = $this->member('09123334444', 'صاحبِ شماره');
        $feedback = $this->feedback(['user_id' => $author->id, 'phone' => '09123334444']);

        $this->reply($feedback)->assertCreated()->assertJsonPath('feedback.member.via', 'account');

        $this->assertSame(1, $author->notifications()->count());
        $this->assertSame(0, $phoneOwner->notifications()->count());
    }

    /** گیرنده از خودِ بازخورد پیدا می‌شود، نه از درخواست */
    public function test_the_recipient_cannot_be_chosen_by_the_request(): void
    {
        $user = $this->member('09123334444');
        $victim = $this->member('09127776666');

        $this->actingAs($this->admin)->postJson(self::INDEX."/{$this->feedback()->id}/replies", [
            'body' => 'پاسخ',
            'recipient_id' => $victim->id,
            'author_id' => $victim->id,
        ])->assertCreated();

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, $victim->notifications()->count());
        // خرابکاریِ فاز ۶: سطرِ پاسخ هم نباید گیرنده‌ی درخواست را بگیرد، نه فقط اعلان
        $this->assertSame([$this->admin->id, $user->id], [FeedbackReply::sole()->author_id, FeedbackReply::sole()->recipient_id]);
    }

    /* ─────────────────── بندِ ۱۲ و ۱۴: امنیت ─────────────────── */

    public function test_only_the_super_admin_sees_the_queue_or_replies(): void
    {
        $feedback = $this->feedback();
        $complex = Complex::factory()->create();

        foreach ([UserRole::ComplexAdmin, UserRole::Owner, UserRole::Tenant] as $role) {
            $user = User::factory()->create(['role' => $role, 'complex_id' => $complex->id, 'is_active' => true]);
            $this->actingAs($user)->getJson(self::INDEX)->assertForbidden();
            $this->actingAs($user)->postJson(self::INDEX."/{$feedback->id}/replies", ['body' => 'پاسخ'])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->postJson(self::INDEX."/{$feedback->id}/replies", ['body' => 'پاسخ'])->assertUnauthorized();
        $this->assertSame(0, FeedbackReply::count());
    }

    /** IDOR: پاسخِ کاربرِ دیگر با هیچ شناسه‌ای دیده یا «خوانده» نمی‌شود */
    public function test_nobody_else_can_see_or_touch_the_reply(): void
    {
        $owner = $this->member('09123334444');
        $other = $this->member('09127776666', 'دیگری');
        $this->reply($this->feedback(), 'متنِ خصوصیِ پاسخ')->assertCreated();
        $id = $owner->notifications()->sole()->id;

        $this->actingAs($other)->postJson("/api/v1/notifications/personal/{$id}/read")->assertNotFound();
        $this->assertNull($owner->notifications()->sole()->read_at);

        foreach (['/api/v1/notifications', '/api/v1/notifications/history'] as $path) {
            $this->assertStringNotContainsString('متنِ خصوصیِ پاسخ', $this->actingAs($other)->getJson($path)->getContent(), $path);
        }
        $this->actingAs($other)->getJson(self::INDEX)->assertForbidden();
    }

    public function test_the_reply_is_validated_and_kept_as_plain_text(): void
    {
        $this->member('09123334444');
        $feedback = $this->feedback();

        $this->reply($feedback, '')->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->reply($feedback, str_repeat('ا', 2001))->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->reply($feedback, "<script>alert(1)</script>\u{202E}سلام")->assertCreated();
        // متن همان‌طور که نوشته شد (بی نویسه‌ی بازنویسیِ جهت)؛ رابط آن را متن رندر می‌کند
        $this->assertSame('<script>alert(1)</script>سلام', FeedbackReply::sole()->body);
    }

    public function test_replies_are_rate_limited(): void
    {
        $this->member('09123334444');
        $feedback = $this->feedback();

        for ($i = 0; $i < 20; $i++) {
            $this->reply($feedback, "پاسخِ شماره‌ی {$i}")->assertCreated();
        }
        $this->reply($feedback, 'یکی بیشتر')->assertStatus(429);
    }
}
