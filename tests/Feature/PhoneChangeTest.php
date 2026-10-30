<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Complex;
use App\Models\Message;
use App\Models\OtpCode;
use App\Models\PhoneChangeRequest;
use App\Models\TrustedDevice;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Auth\OtpService;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Units\TenureService;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * تغییرِ شماره‌ی حساب توسطِ خودِ کاربر (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * موفق، ناموفق، مهاجم (مدیر، فیلدهای جعلی، شماره‌ی حسابِ دیگر)، و رگرسیونِ ورود،
 * بازیابیِ رمز و کیف. هم‌زمانیِ واقعی: `PhoneChangeConcurrencyTest`.
 */
class PhoneChangeTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = '09125550001';

    private const NEW = '09125550099';

    private Complex $complex;

    private Unit $unit;

    private User $manager;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع شماره', 'messenger_enabled' => true]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
            'password' => Hash::make('secret123'),
        ]);
        $this->resident = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true,
            'phone' => self::OLD, 'password' => Hash::make('secret123'), 'can_message' => true,
        ]);
        app(TenureService::class)->open($this->unit, $this->resident, ResidentRelation::Tenant, 100, now()->subMonth());
    }

    /* ── موفق ──────────────────────────────────────────────────────────── */

    public function test_the_owner_changes_their_phone_with_a_fresh_code(): void
    {
        TrustedDevice::create([
            'user_id' => $this->resident->id, 'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(10),
        ]);

        $code = $this->start()->assertOk()->json('devCode');
        $this->assertSame(self::OLD, $this->resident->fresh()->phone, 'شماره پیش از تأیید عوض شد.');

        $this->as($this->resident)->postJson('/api/v1/profile/phone/verify', ['code' => $code])
            ->assertOk()->assertJsonPath('phone', self::NEW);

        $this->assertSame(self::NEW, $this->resident->fresh()->phone);
        $this->assertSame(0, TrustedDevice::where('user_id', $this->resident->id)->count(), 'اعتمادِ دستگاه‌ها لغو نشد.');
        $this->assertSame(0, PhoneChangeRequest::count());
        $audit = AuditLog::where('action', 'account.phone_changed')->sole();
        $this->assertSame([self::OLD, self::NEW], [$audit->properties['old_phone'], $audit->properties['new_phone']]);
        $this->assertSame(1, $this->resident->fresh()->notifications()->count());
    }

    /** ورود و کدِ ورود پس از تغییر با شماره‌ی تازه کار می‌کنند؛ شماره‌ی قدیم دیگر نه. */
    public function test_login_and_recovery_follow_the_new_phone(): void
    {
        $this->changeTo(self::NEW);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        app(OtpService::class)->clear(self::NEW);

        // شماره‌ی قدیم: همان پاسخِ یکسان، ولی کدی فرستاده نمی‌شود و هیچ کدی وارد نمی‌کند (ورود بی رمز، ۲۰۲۶-۱۰-۳۰)
        $this->assertNull($this->postJson('/api/v1/login', ['phone' => self::OLD])->assertOk()->json('dev_code'));
        $this->postJson('/api/v1/login/verify', ['code' => '000000'])->assertStatus(422);
        $this->assertGuest();

        $this->flushSession();
        $code = $this->postJson('/api/v1/login', ['phone' => self::NEW])
            ->assertOk()->assertJsonPath('otpRequired', true)->assertJsonPath('phone', self::NEW)->json('dev_code');
        $this->assertNotNull($code, 'کدِ ورود به شماره‌ی تازه نرفت.');
        $this->assertSame(1, OtpCode::where('phone', self::NEW)->count());
        $this->postJson('/api/v1/login/verify', ['code' => $code])->assertOk()->assertJsonPath('user.id', $this->resident->id);
    }

    /** کیف، دفتر، عضویت، دوره و نویسنده‌ی پیام‌ها به حساب بسته‌اند، نه به شماره. */
    public function test_changing_the_phone_keeps_wallet_memberships_and_authorship(): void
    {
        app(WalletService::class)->credit($this->resident, $this->unit, 120000, WalletTransaction::SOURCE_ADJUSTMENT);
        $thread = app(ConversationDirectory::class)->unitThread($this->complex, $this->unit);
        $message = Message::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'conversation_id' => $thread->id, 'user_id' => $this->resident->id,
            'body' => 'پیامِ پیش از تغییرِ شماره', 'client_id' => (string) Str::uuid(), 'author_name' => $this->resident->name,
        ]);
        $ledger = WalletTransaction::withoutGlobalScopes()->orderBy('id')->get(['id', 'user_id', 'amount'])->toArray();

        $this->changeTo(self::NEW);

        $resident = $this->resident->fresh();
        $this->assertSame(120000.0, app(WalletService::class)->balance($resident));
        $this->assertSame($ledger, WalletTransaction::withoutGlobalScopes()->orderBy('id')->get(['id', 'user_id', 'amount'])->toArray());
        $this->assertSame([$this->complex->id, UserRole::Tenant], [$resident->complex_id, $resident->role]);
        $this->assertSame(1, UnitTenure::where('user_id', $resident->id)->whereNull('end_date')->count());
        $this->assertSame($resident->id, Message::withoutGlobalScopes()->find($message->id)->user_id);
    }

    /* ── ناموفق ─────────────────────────────────────────────────────────── */

    public function test_a_wrong_or_expired_code_changes_nothing(): void
    {
        $code = $this->start()->json('devCode');
        $wrong = $code === '111111' ? '222222' : '111111';

        $this->as($this->resident)->postJson('/api/v1/profile/phone/verify', ['code' => $wrong])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame(self::OLD, $this->resident->fresh()->phone);

        $this->travel(OtpService::TTL_SECONDS + 1)->seconds();
        $this->as($this->resident)->postJson('/api/v1/profile/phone/verify', ['code' => $code])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame(self::OLD, $this->resident->fresh()->phone);
    }

    /** پس از پنج کدِ نادرست، کدِ درست هم دیگر نمی‌گذرد (سقفِ تلاش در تراکنش گم نمی‌شود). */
    public function test_the_attempt_cap_survives_failed_confirmations(): void
    {
        $code = $this->start()->json('devCode');
        $wrong = $code === '111111' ? '222222' : '111111';

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->as($this->resident)->withoutMiddleware(ThrottleRequests::class)
                ->postJson('/api/v1/profile/phone/verify', ['code' => $wrong])->assertStatus(422);
        }

        $this->as($this->resident)->withoutMiddleware(ThrottleRequests::class)
            ->postJson('/api/v1/profile/phone/verify', ['code' => $code])->assertStatus(422);
        $this->assertSame(self::OLD, $this->resident->fresh()->phone);
    }

    public function test_an_expired_change_request_cannot_be_confirmed(): void
    {
        $code = $this->start()->json('devCode');
        PhoneChangeRequest::query()->update(['expires_at' => now()->subMinute()]);

        $this->as($this->resident)->postJson('/api/v1/profile/phone/verify', ['code' => $code])->assertStatus(422);
        $this->assertSame(self::OLD, $this->resident->fresh()->phone);
    }

    /**
     * شماره‌ی حسابِ دیگر: پاسخِ گامِ اول همان پاسخِ شماره‌ی آزاد است (شمارشِ حساب)
     * ولی کدی به آن نمی‌رود؛ و حتی با کدی که به‌نحوی معتبر باشد، شماره نمی‌رسد و
     * هیچ ادغامی رخ نمی‌دهد.
     */
    public function test_another_accounts_phone_is_never_taken_or_merged(): void
    {
        $other = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => self::NEW]);

        $taken = $this->start()->assertOk();
        $this->assertNull($taken->json('devCode'), 'برای شماره‌ی دارای حساب کد فرستاده شد.');
        $free = $this->start('09125550077')->assertOk();
        $this->assertSame($free->json('message'), $taken->json('message'), 'پاسخ می‌گوید شماره حساب دارد.');

        // حتی اگر کسی کدِ معتبرِ آن شماره را داشته باشد
        $this->start();
        OtpCode::where('phone', self::NEW)->delete();
        OtpCode::create(['phone' => self::NEW, 'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinute()]);
        $this->as($this->resident)->postJson('/api/v1/profile/phone/verify', ['code' => '123456'])
            ->assertStatus(422)->assertJsonPath('code', 'phone_change.unavailable');

        $this->assertSame(self::OLD, $this->resident->fresh()->phone);
        $this->assertSame(self::NEW, $other->fresh()->phone);
        $this->assertSame(2, User::withoutGlobalScopes()->whereIn('id', [$this->resident->id, $other->id])->count());
    }

    public function test_the_current_phone_or_a_bad_number_is_refused(): void
    {
        $this->start(self::OLD)->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->start('12345')->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    /** گامِ اول بی تأییدِ دوباره‌ی رمز اجرا نمی‌شود. */
    public function test_starting_needs_a_recent_password_confirmation(): void
    {
        $this->as($this->resident)->postJson('/api/v1/profile/phone', ['phone' => self::NEW])
            ->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');
        $this->assertSame(0, PhoneChangeRequest::count());
    }

    /* ── مهاجم ──────────────────────────────────────────────────────────── */

    /** مدیر شماره و رمزِ ساکن را نه از فرمِ ساکن عوض می‌کند، نه از مسیرِ پروفایل. */
    public function test_a_manager_cannot_change_a_residents_phone_or_password(): void
    {
        foreach ([['phone' => self::NEW], ['password' => 'takeover123']] as $field) {
            $this->as($this->manager)->putJson("/api/v1/residents/{$this->resident->id}", [
                'role' => 'tenant', 'unit_id' => $this->unit->id, ...$field,
            ])->assertStatus(422)->assertJsonValidationErrors(array_keys($field));
        }

        // مسیرِ پروفایل فقط حسابِ خودِ درخواست‌کننده است؛ شناسه‌ی دیگری پذیرفته نمی‌شود
        $this->as($this->manager)->withConfirmedPassword()->postJson('/api/v1/profile/phone', [
            'phone' => self::NEW, 'user_id' => $this->resident->id,
        ])->assertStatus(422)->assertJsonValidationErrors('user_id');

        $resident = $this->resident->fresh();
        $this->assertSame(self::OLD, $resident->phone);
        $this->assertTrue(Hash::check('secret123', $resident->password));
        $this->assertSame(0, PhoneChangeRequest::where('user_id', $this->resident->id)->count());
    }

    /** فیلدهای ممنوع با درخواستِ دستی: پروفایل نه شماره را می‌گیرد نه رمز. */
    public function test_forbidden_fields_sent_by_hand_are_refused(): void
    {
        $this->as($this->resident)->putJson('/api/v1/profile', [
            'first_name' => 'علی', 'last_name' => 'رضایی', 'phone' => self::NEW, 'password' => 'takeover123',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone', 'password']);

        $this->as($this->resident)->postJson('/api/v1/profile/phone/verify', ['code' => '123456', 'phone' => self::NEW])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertSame(self::OLD, $this->resident->fresh()->phone);
        $this->assertTrue(Hash::check('secret123', $this->resident->fresh()->password));
    }

    /** کدِ تأیید و هیچ رازی وارد لاگ نمی‌شود. */
    public function test_the_code_never_reaches_the_log(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $code = (string) $this->start()->json('devCode');
        $this->as($this->resident)->postJson('/api/v1/profile/phone/verify', ['code' => $code])->assertOk();

        /*
         * ⚠️ درایورِ پیامکِ `log` در محیطِ توسعه خودِ «گوشیِ گیرنده» است: پیامک را
         * در لاگ می‌نویسد. همان یک خطِ تحویل جدا می‌شود؛ هیچ خطِ دیگری نباید کد را داشته باشد.
         */
        $delivery = array_filter($logged, fn (string $line) => str_starts_with($line, '[SMS:log]'));
        $this->assertCount(1, $delivery, 'کد بیش از یک بار «فرستاده» شد.');
        $stored = implode("\n", array_diff_key($logged, $delivery)).json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString($code, $stored);
        $this->assertStringNotContainsString('secret123', $stored);
    }

    /** یک حساب نمی‌تواند پشتِ‌سرِهم به شماره‌های مختلف پیامک بفرستد. */
    public function test_one_account_cannot_spray_codes_to_many_numbers(): void
    {
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->start('0912555'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT))->status();
        }

        $this->assertSame(429, end($statuses), 'سقفِ روزانه‌ی هر حساب اعمال نشد: '.implode(',', $statuses));
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function start(string $phone = self::NEW): TestResponse
    {
        return $this->as($this->resident)->withConfirmedPassword()->postJson('/api/v1/profile/phone', ['phone' => $phone]);
    }

    private function changeTo(string $phone): void
    {
        $code = $this->start($phone)->assertOk()->json('devCode');
        $this->as($this->resident)->postJson('/api/v1/profile/phone/verify', ['code' => $code])->assertOk();
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user->fresh());
    }
}
