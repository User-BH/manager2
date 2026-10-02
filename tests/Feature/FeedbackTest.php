<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * فرمِ بازخوردِ صفحه‌ی پشتیبانی (مرحلهٔ ۷ · فاز ۴).
 *
 * ⚠️ قاعده‌ی فایلِ ایرادات: «هیچ داده‌ای صرفاً به دلیلِ اعتماد به Frontend
 * معتبر فرض نشود». هر تستِ اعتبارسنجیِ اینجا مستقیم به API می‌زند، بی‌فرم.
 *
 * CSRF در آزمونِ مرورگر (`critical-flows`) سنجیده می‌شود: لاراول در اجرای
 * PHPUnit عمداً آن را کنار می‌گذارد.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/feedback';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('feedback:127.0.0.1');
    }

    /** @return array<string, mixed> */
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'category' => 'feature',
            'message' => 'کاش گزارشِ ماهانه را هم می‌شد PDF گرفت.',
        ], $overrides);
    }

    // ─── ثبت ────────────────────────────────────────────────────────────

    public function test_a_guest_can_send_feedback_and_it_is_stored(): void
    {
        $this->postJson(self::URL, $this->valid([
            'name' => 'مریم',
            'phone' => '۰۹۱۲۱۲۳۴۵۶۷',
        ]))->assertCreated()->assertJsonPath('message', 'پیامِ شما رسید. ممنون که وقت گذاشتید.');

        $row = Feedback::sole();
        $this->assertSame('feature', $row->category->value);
        $this->assertSame('new', $row->status->value);
        // ارقامِ فارسی یکدست شدند
        $this->assertSame('09121234567', $row->phone);
        $this->assertNull($row->user_id);
    }

    public function test_a_signed_in_sender_is_linked(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->postJson(self::URL, $this->valid())->assertCreated();

        $this->assertSame($user->id, Feedback::sole()->user_id);
    }

    /**
     * 🔴 کاربری که وارد شده ولی هنوز مجتمعی ندارد («حالتِ اولیه») فقط‌خواندنی
     * است. بازخورد و دستیارِ پشتیبانی برای مهمان بازند؛ برای او ۴۰۳ می‌دادند.
     */
    public function test_a_signed_in_user_without_a_complex_can_still_use_the_support_page(): void
    {
        $user = User::factory()->create(['is_active' => true, 'complex_id' => null]);

        $this->actingAs($user)->postJson(self::URL, $this->valid())->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/support/chat', ['message' => 'شارژ چطور حساب می‌شود؟'])
            ->assertOk();
    }

    /**
     * ⚠️ IPِ خام هیچ‌جا ذخیره نمی‌شود؛ فقط HMACش برای تشخیصِ تکرار.
     */
    public function test_the_raw_ip_is_never_stored(): void
    {
        $this->postJson(self::URL, $this->valid())->assertCreated();

        $row = (array) DB::table('feedback')->first();
        // ⚠️ «شامل» و نه «برابر»: خرابکاری نشان داد IPِ پرشده تا ۶۴ نویسه از
        // بررسیِ برابری رد می‌شد
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString('127.0.0.1', (string) $value, "IP در ستونِ {$column}");
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['sender_hash']);
    }

    // ─── اعتبارسنجیِ سرور ───────────────────────────────────────────────

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'بی‌نوع' => [['category' => null], 'category'],
            'نوعِ ناشناخته' => [['category' => 'spam'], 'category'],
            'بی‌متن' => [['message' => ''], 'message'],
            'متنِ خیلی کوتاه' => [['message' => 'سلام'], 'message'],
            'متنِ بیش از ۲۰۰۰' => [['message' => str_repeat('ا', 2001)], 'message'],
            'سه پیوند' => [['message' => 'ببینید http://a.ir و http://b.ir و www.c.ir را'], 'message'],
            'شماره‌ی نامعتبر' => [['phone' => '021123'], 'phone'],
            'نامِ بلند' => [['name' => str_repeat('ن', 81)], 'name'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_the_server_rejects_what_the_form_would_have_stopped(array $overrides, string $field): void
    {
        $this->postJson(self::URL, $this->valid($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, Feedback::count());
    }

    // ─── ضدِ هرزنامه ─────────────────────────────────────────────────────

    /**
     * ربات همان پاسخِ موفق را می‌گیرد — خطا به او می‌گفت کدام فیلد تله است.
     */
    public function test_a_filled_honeypot_looks_accepted_but_stores_nothing(): void
    {
        $this->postJson(self::URL, $this->valid(['website' => 'http://spam.example']))
            ->assertCreated()
            ->assertJsonPath('message', 'پیامِ شما رسید. ممنون که وقت گذاشتید.');

        $this->assertSame(0, Feedback::count());
    }

    public function test_the_same_text_twice_is_stored_once(): void
    {
        $this->postJson(self::URL, $this->valid())->assertCreated();
        // همان متن با فاصله و نشانه‌گذاریِ متفاوت — همان پیام است
        $this->postJson(self::URL, $this->valid(['message' => '  کاش گزارشِ ماهانه را هم می‌شد  PDF گرفت!! ']))
            ->assertCreated();

        $this->assertSame(1, Feedback::count());
    }

    public function test_the_same_text_after_a_day_is_a_new_message(): void
    {
        $this->postJson(self::URL, $this->valid())->assertCreated();
        Feedback::query()->update(['created_at' => now()->subDays(2)]);

        $this->postJson(self::URL, $this->valid())->assertCreated();

        $this->assertSame(2, Feedback::count());
    }

    public function test_the_rate_limit_stops_a_flood(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson(self::URL, $this->valid(['message' => "پیامِ شماره‌ی {$i} برای آزمایشِ سقف"]))->assertCreated();
        }

        $this->postJson(self::URL, $this->valid(['message' => 'پیامِ چهارم در همان دقیقه']))
            ->assertTooManyRequests();

        $this->assertSame(3, Feedback::count());
    }

    // ─── پاک‌سازی ──────────────────────────────────────────────────────

    public function test_invisible_control_and_direction_characters_are_removed_but_half_spaces_stay(): void
    {
        $this->postJson(self::URL, $this->valid([
            // نویسه‌ی کنترلی، بازنویسیِ جهت (RLO)، و نیم‌فاصله‌ی «می‌شود»
            'message' => "متن\u{0007} با \u{202E}وارونه و می\u{200C}شود",
        ]))->assertCreated();

        $this->assertSame("متن با وارونه و می\u{200C}شود", Feedback::sole()->message);
    }

    /**
     * متن متن می‌ماند: سرور آن را تغییر نمی‌دهد و صفِ ادمین آن را escape‌شده
     * رندر می‌کند (`tests/js/feedback.test.tsx`).
     */
    public function test_markup_is_stored_as_plain_text(): void
    {
        $payload = '<img src=x onerror=alert(1)> این متن باید متن بماند';

        $this->postJson(self::URL, $this->valid(['message' => $payload]))->assertCreated();

        $this->assertSame($payload, Feedback::sole()->message);
    }

    // ─── صفِ ادمینِ کل ───────────────────────────────────────────────────

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    public function test_only_the_platform_admin_can_read_the_queue(): void
    {
        Feedback::create([
            'category' => 'bug', 'message' => 'دکمه کار نمی‌کند', 'status' => 'new',
            'message_hash' => str_repeat('a', 64), 'sender_hash' => str_repeat('b', 64),
        ]);
        $complex = Complex::factory()->create();

        $this->getJson('/api/v1/system/feedback')->assertUnauthorized();

        foreach ([UserRole::ComplexAdmin, UserRole::Owner, UserRole::Tenant] as $role) {
            $user = User::factory()->create(['role' => $role, 'complex_id' => $complex->id, 'is_active' => true]);
            $this->actingAs($user)->getJson('/api/v1/system/feedback')->assertForbidden();
        }

        $this->actingAs($this->superAdmin())->getJson('/api/v1/system/feedback')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'دکمه کار نمی‌کند')
            ->assertJsonPath('data.0.categoryLabel', 'گزارشِ مشکل')
            ->assertJsonPath('counts.new', 1)
            // هش‌ها بیرون نمی‌روند
            ->assertJsonMissingPath('data.0.sender_hash');
    }

    public function test_the_admin_files_feedback_and_filters_by_status(): void
    {
        $item = Feedback::create([
            'category' => 'feedback', 'message' => 'ممنون از پنل', 'status' => 'new',
            'message_hash' => str_repeat('a', 64), 'sender_hash' => str_repeat('b', 64),
        ]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->patchJson("/api/v1/system/feedback/{$item->id}", ['status' => 'archived'])
            ->assertOk()->assertJsonPath('feedback.status', 'archived');

        $this->actingAs($admin)->getJson('/api/v1/system/feedback?status=new')->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/v1/system/feedback?status=archived')->assertJsonCount(1, 'data');

        $this->actingAs($admin)->patchJson("/api/v1/system/feedback/{$item->id}", ['status' => 'deleted'])
            ->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/v1/system/feedback?status=nope')->assertUnprocessable();
    }

    public function test_a_complex_admin_cannot_file_feedback(): void
    {
        $item = Feedback::create([
            'category' => 'feedback', 'message' => 'ممنون از پنل', 'status' => 'new',
            'message_hash' => str_repeat('a', 64), 'sender_hash' => str_repeat('b', 64),
        ]);
        $complex = Complex::factory()->create();
        $manager = User::factory()->create(['role' => UserRole::ComplexAdmin, 'complex_id' => $complex->id, 'is_active' => true]);

        $this->actingAs($manager)->patchJson("/api/v1/system/feedback/{$item->id}", ['status' => 'archived'])
            ->assertForbidden();

        $this->assertSame('new', $item->fresh()->status->value);
    }
}
