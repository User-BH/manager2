<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\ErrorEvent;
use App\Models\TrustedDevice;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Auth\OtpService;
use App\Services\ErrorRecorder;
use App\Support\SystemSettings;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * دعوتِ امن — مرحلهٔ ۹ · فاز ۶ (F5 و F7 ِ Audit ِ ۹/۲).
 *
 * تصمیمِ کارفرما: توکنِ رمزنگاری‌شده، ذخیره‌ی هش، انقضا، ابطال، یک‌بارمصرف،
 * بسته به شماره‌ی هدف، کدِ OTP ِ تازه در پذیرش؛ **دعوت به‌تنهایی دسترسی
 * نمی‌دهد**. هر تست یکی از این‌ها را از سمتِ مهاجم می‌سنجد.
 */
class SecureInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = '09127770001';

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع امن']);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '7']);
    }

    /* ── توکن و ذخیره ─────────────────────────────────────────────────── */

    public function test_the_token_is_long_random_and_only_its_hash_is_stored(): void
    {
        [$first, $link] = $this->inviteViaApi();
        [, $second] = $this->inviteViaApi('09127770002');

        $token = $this->tokenOf($link);
        // ۳۲ بایت تصادفی ← ۴۳ نویسه‌ی base64url
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertNotSame($token, $this->tokenOf($second));

        $row = (array) DB::table('complex_invitations')->where('id', $first)->first();
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "توکنِ خام در ستونِ {$column} ذخیره شد.");
        }
    }

    public function test_the_token_never_leaves_the_server_again(): void
    {
        [, $link] = $this->inviteViaApi();
        $token = $this->tokenOf($link);
        $recipient = $this->registrant();

        $responses = [
            $this->as($this->manager)->getJson('/api/v1/sent-invitations'),
            $this->as($recipient)->getJson('/api/v1/invitations'),
            $this->postJson('/api/v1/invitations/preview', ['token' => $token]),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertStringNotContainsString($token, $response->getContent());
            $this->assertStringNotContainsString(hash('sha256', $token), $response->getContent());
        }
    }

    /**
     * توکن نه در لاگ، نه در حسابرسی، نه در گزارشِ خطا (تصمیمِ کارفرما) — حتی
     * وقتی گزارشِ خطای مرورگر نشانیِ کاملِ پیوند را با خودش بیاورد.
     */
    public function test_the_token_never_reaches_logs_audit_or_error_reports(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });
        config(['observability.error_log.enabled' => true]);

        [$id, $link] = $this->inviteViaApi();
        $token = $this->tokenOf($link);
        $recipient = $this->registrant();
        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertOk();
        $this->acceptWithCode($recipient, $id)->assertOk();
        ErrorRecorder::fromClient(['type' => 'Error', 'message' => 'آزمون', 'stack' => '', 'url' => $link]);

        $this->assertSame(1, ErrorEvent::count(), 'گزارشِ خطا ثبت نشد؛ آزمون بی‌اثر است.');
        $stored = json_encode([
            DB::table('audit_logs')->get(),
            DB::table('error_events')->get(),
            DB::table('notifications')->get(),
        ], JSON_UNESCAPED_UNICODE).implode("\n", $logged);

        $this->assertStringNotContainsString($token, $stored);
        $this->assertStringNotContainsString(hash('sha256', $token), $stored);
    }

    /* ── پیوند: فقط پیش‌نمایش ─────────────────────────────────────────── */

    public function test_the_link_previews_without_granting_anything(): void
    {
        [, $link] = $this->inviteViaApi();

        $this->postJson('/api/v1/invitations/preview', ['token' => $this->tokenOf($link)])
            ->assertOk()
            ->assertJsonPath('invitation.complexName', 'مجتمع امن')
            ->assertJsonPath('invitation.unitLabel', 'واحد 7')
            // فقط دو رقمِ آخر؛ شماره‌ی کامل به دارنده‌ی پیوند داده نمی‌شود
            ->assertJsonPath('invitation.phoneHint', '09•••••••01')
            ->assertJsonMissingPath('invitation.phone');

        $this->assertDatabaseMissing('users', ['phone' => self::TARGET]);
        $this->assertSame(0, UnitTenure::count());
    }

    public function test_a_wrong_revoked_or_expired_token_all_look_the_same(): void
    {
        [$id, $link] = $this->inviteViaApi();
        $token = $this->tokenOf($link);

        $this->postJson('/api/v1/invitations/preview', ['token' => Str::random(43)])->assertNotFound();
        $this->postJson('/api/v1/invitations/preview', ['token' => str_repeat('a', 500)])->assertNotFound();

        $this->travel(ComplexInvitation::TTL_DAYS + 1)->days();
        $this->flushSession();
        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertNotFound();
        $this->travelBack();

        $this->as($this->manager)->deleteJson("/api/v1/sent-invitations/{$id}")->assertOk();
        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertNotFound();
    }

    /* ── بسته به شماره ─────────────────────────────────────────────────── */

    public function test_only_the_owner_of_the_target_phone_sees_or_answers_it(): void
    {
        [$id] = $this->inviteViaApi();
        $stranger = $this->registrant('09127779999');

        $this->as($stranger)->getJson('/api/v1/invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->as($stranger)->postJson("/api/v1/invitations/{$id}/code")->assertNotFound();
        $this->as($stranger)->postJson("/api/v1/invitations/{$id}/accept", ['code' => '123456'])->assertNotFound();
        $this->as($stranger)->postJson("/api/v1/invitations/{$id}/decline")->assertNotFound();

        $this->assertNull($stranger->fresh()->complex_id);
        $this->assertSame(ComplexInvitation::PENDING, ComplexInvitation::find($id)->status);
    }

    /* ── OTP ِ تازه ────────────────────────────────────────────────────── */

    public function test_accepting_needs_a_fresh_code_sent_to_that_phone(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();

        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept")
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept", ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertNull($recipient->fresh()->complex_id);

        $code = $this->as($recipient)->postJson("/api/v1/invitations/{$id}/code")->assertOk()->json('devCode');
        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept", ['code' => $code])->assertOk();

        $this->assertSame($this->complex->id, $recipient->fresh()->complex_id);
    }

    /** دستگاهِ مورداعتماد (ورود بی کد) این گام را دور نمی‌زند — تصمیمِ کارفرما. */
    public function test_a_trusted_device_does_not_skip_the_code(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();
        TrustedDevice::create([
            'user_id' => $recipient->id,
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(10),
        ]);

        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept")
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertNull($recipient->fresh()->complex_id);
    }

    /** کدِ منقضی (پس از `OtpService::TTL_SECONDS`) پذیرفته نیست. */
    public function test_an_expired_code_is_refused(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();
        $code = $this->as($recipient)->postJson("/api/v1/invitations/{$id}/code")->assertOk()->json('devCode');

        $this->travel(OtpService::TTL_SECONDS + 1)->seconds();

        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept", ['code' => $code])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertNull($recipient->fresh()->complex_id);
    }

    /** کدِ مصرف‌شده دوباره کار نمی‌کند. */
    public function test_a_used_code_cannot_be_replayed(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();

        $code = $this->as($recipient)->postJson("/api/v1/invitations/{$id}/code")->json('devCode');
        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept", ['code' => $code])->assertOk();

        $this->assertFalse(app(OtpService::class)->verify(self::TARGET, (string) $code));
    }

    /**
     * 🔴 ۱۲/۳ (بندِ ۱۵): کلیدِ `otp_disabled` فقط گامِ دومِ ورود را برمی‌دارد، نه کدِ پذیرشِ دعوت. تا پیش از این
     * پذیرش با آن کلید بی کد بود؛ ثبت‌نام از پیوندِ دعوت هیچ‌وقت نبود (`DirectInvitationRoutingTest`).
     */
    public function test_the_platform_otp_switch_does_not_let_acceptance_skip_its_code(): void
    {
        SystemSettings::set('otp_disabled', true);
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();

        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept")->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept", ['code' => '000000'])->assertUnprocessable();
        $this->assertSame(ComplexInvitation::PENDING, ComplexInvitation::find($id)->status);

        $code = $this->as($recipient)->postJson("/api/v1/invitations/{$id}/code")->assertOk()->assertJsonPath('otpRequired', true)->json('devCode');
        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept", ['code' => $code])->assertOk();
    }

    /**
     * 🔴 سقفِ نرخِ کد به شماره‌ی خودِ کاربر بسته است، نه به «بی‌شماره + IP».
     *
     * مسیرِ کدِ پذیرش شماره در بدنه ندارد؛ کلیدِ نخست `|IP` بود و چهارمین
     * کاربرِ پشتِ همان IP ۴۲۹ می‌گرفت — آزمونِ مرورگر همین را نشان داد.
     */
    public function test_code_requests_of_different_people_behind_one_ip_do_not_collide(): void
    {
        foreach (['09127770011', '09127770012', '09127770013', '09127770014'] as $phone) {
            [$invitation] = app(Membership::class)->invite($this->complex, $phone, UserRole::Tenant, $this->unit, $this->manager);
            $this->as($this->registrant($phone))->postJson("/api/v1/invitations/{$invitation->id}/code")->assertOk();
            app(OtpService::class)->clear($phone);
        }
    }

    /* ── یک‌بارمصرف، انقضا، ابطال ──────────────────────────────────────── */

    public function test_an_invitation_is_single_use(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();

        $this->acceptWithCode($recipient, $id)->assertOk();
        $this->as($recipient->fresh())->postJson("/api/v1/invitations/{$id}/accept", ['code' => '123456'])->assertNotFound();

        $this->assertSame(1, UnitTenure::where('user_id', $recipient->id)->count());
        $this->assertSame(ComplexInvitation::ACCEPTED, ComplexInvitation::find($id)->status);
        $this->assertSame($recipient->id, ComplexInvitation::find($id)->user_id);
        $this->assertNull(ComplexInvitation::find($id)->live_key);
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();
        $code = $this->as($recipient)->postJson("/api/v1/invitations/{$id}/code")->json('devCode');

        $this->travel(ComplexInvitation::TTL_DAYS)->days();
        $this->travel(1)->minutes();

        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept", ['code' => $code])->assertNotFound();
        $this->as($recipient)->getJson('/api/v1/invitations')->assertJsonCount(0, 'data');
        // مدیر می‌بیند که منقضی است و باید دوباره بفرستد
        $this->as($this->manager)->getJson('/api/v1/sent-invitations')->assertJsonPath('data.0.expired', true);
        $this->assertNull($recipient->fresh()->complex_id);
    }

    public function test_a_revoked_invitation_cannot_be_accepted(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();
        $code = $this->as($recipient)->postJson("/api/v1/invitations/{$id}/code")->json('devCode');

        $this->as($this->manager)->deleteJson("/api/v1/sent-invitations/{$id}")->assertOk();
        $this->as($this->manager)->deleteJson("/api/v1/sent-invitations/{$id}")->assertStatus(422);

        $this->as($recipient)->postJson("/api/v1/invitations/{$id}/accept", ['code' => $code])->assertNotFound();
        $this->assertNull($recipient->fresh()->complex_id);
        $this->assertSame(ComplexInvitation::REVOKED, ComplexInvitation::find($id)->status);
    }

    public function test_another_complexes_manager_or_a_resident_cannot_list_or_revoke(): void
    {
        [$id] = $this->inviteViaApi();
        $otherComplex = Complex::factory()->create();
        $intruder = User::factory()->create([
            'complex_id' => $otherComplex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);

        $this->as($intruder)->getJson('/api/v1/sent-invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->as($intruder)->deleteJson("/api/v1/sent-invitations/{$id}")->assertNotFound();

        $resident = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true,
        ]);
        $this->as($resident)->getJson('/api/v1/sent-invitations')->assertForbidden();
        $this->as($resident)->deleteJson("/api/v1/sent-invitations/{$id}")->assertForbidden();

        $this->assertSame(ComplexInvitation::PENDING, ComplexInvitation::find($id)->status);
    }

    /** «دوباره بفرست» توکنِ قبلی را باطل می‌کند و همیشه فقط یک دعوتِ زنده هست. */
    public function test_reissuing_kills_the_previous_link(): void
    {
        [$oldId, $oldLink] = $this->inviteViaApi();
        [$newId, $newLink] = $this->inviteViaApi();

        $this->postJson('/api/v1/invitations/preview', ['token' => $this->tokenOf($oldLink)])->assertNotFound();
        $this->postJson('/api/v1/invitations/preview', ['token' => $this->tokenOf($newLink)])->assertOk();
        $this->assertSame(ComplexInvitation::REVOKED, ComplexInvitation::find($oldId)->status);
        $this->assertSame(1, ComplexInvitation::query()->live()->where('phone', self::TARGET)->count());
        $this->assertNotSame($oldId, $newId);
    }

    /** پایگاه‌داده خودش دعوتِ زنده‌ی دوم را رد می‌کند — حتی اگر کدی قفل را فراموش کند. */
    public function test_the_database_refuses_a_second_live_invitation(): void
    {
        $this->inviteViaApi();

        $this->expectException(UniqueConstraintViolationException::class);
        ComplexInvitation::issue([
            'complex_id' => $this->complex->id, 'phone' => self::TARGET, 'role' => UserRole::Owner,
            'unit_id' => $this->unit->id,
        ]);
    }

    /* ── قاعده‌های دعوت ───────────────────────────────────────────────── */

    public function test_existing_members_and_platform_accounts_are_not_invitable(): void
    {
        $member = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true]);
        $admin = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);

        foreach ([$member->phone, $admin->phone] as $phone) {
            $this->as($this->manager)->postJson('/api/v1/residents', [
                'phone' => $phone, 'role' => 'owner', 'unit_id' => $this->unit->id,
            ])->assertStatus(422)->assertJsonValidationErrors('phone');
        }

        $this->assertSame(0, ComplexInvitation::count());
    }

    /** F6 در دعوت هم: ساکن بی‌واحد دعوت نمی‌شود؛ واحدِ مجتمعِ دیگر هم نه. */
    public function test_a_resident_invitation_needs_a_unit_of_this_complex(): void
    {
        $foreign = Unit::factory()->create(['complex_id' => Complex::factory()->create()->id]);

        $this->as($this->manager)->postJson('/api/v1/residents', ['phone' => self::TARGET, 'role' => 'owner'])
            ->assertStatus(422)->assertJsonValidationErrors('unit_id');
        $this->as($this->manager)->postJson('/api/v1/residents', [
            'phone' => self::TARGET, 'role' => 'owner', 'unit_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('unit_id');

        $this->assertSame(0, ComplexInvitation::count());
    }

    /** واحدی که پس از دعوت حذف شد: پذیرش عضوِ بی‌واحد نمی‌سازد. */
    public function test_a_unit_deleted_after_the_invitation_blocks_acceptance(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();
        $this->unit->delete();

        $this->acceptWithCode($recipient, $id)->assertStatus(422);
        $this->assertNull($recipient->fresh()->complex_id);
    }

    /**
     * دعوت هیچ‌وقت کسی را از مجتمعِ دیگری بیرون نمی‌کشد — از ۱۰/۴ با عضویتِ دوم: عضوِ جای
     * دیگر با کدِ معتبر می‌پذیرد و عضویتِ قبلی‌اش سرِ جایش می‌ماند. (پیش از ۱۰/۴ پذیرش ۴۲۲
     * می‌گرفت، چون حساب فقط یک مجتمع داشت.)
     */
    public function test_a_member_elsewhere_accepts_and_keeps_the_first_membership(): void
    {
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();
        $elsewhere = Complex::factory()->create();
        $code = $this->as($recipient)->postJson("/api/v1/invitations/{$id}/code")->json('devCode');

        app(MembershipLedger::class)->start($recipient, $elsewhere, UserRole::Owner, ComplexMembership::SOURCE_DIRECT);

        $this->as($recipient->fresh())->postJson("/api/v1/invitations/{$id}/accept", ['code' => $code])->assertOk();

        $active = ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $recipient->id)->pluck('complex_id')->sort()->values()->all();
        $this->assertSame(collect([$elsewhere->id, $this->complex->id])->sort()->values()->all(), $active, 'عضویتِ قبلی با پذیرش پایان یافت.');
    }

    /**
     * دعوتِ مجتمعِ دیگر پس از عضویت زنده می‌ماند و پذیرفتنش عضویتِ **دوم** است (مرحلهٔ ۱۰ ·
     * فاز ۴) — عضویتِ اول و دوره‌ی واحدش سرِ جایش. پیش از ۱۰/۴ این دعوت با پیوستن می‌مُرد.
     */
    public function test_another_complexes_invitation_stays_alive_and_adds_a_second_membership(): void
    {
        $other = Complex::factory()->create();
        $otherUnit = Unit::factory()->create(['complex_id' => $other->id]);
        $otherManager = User::factory()->create(['complex_id' => $other->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        [$foreign] = app(Membership::class)->invite($other, self::TARGET, UserRole::Tenant, $otherUnit, $otherManager);
        [$id] = $this->inviteViaApi();
        $recipient = $this->registrant();

        $this->acceptWithCode($recipient, $id)->assertOk();
        $this->assertSame(ComplexInvitation::PENDING, $foreign->fresh()->status, 'دعوتِ مجتمعِ دیگر با پیوستن مُرد.');

        // کدِ تازه برای همان شماره پس از فاصله‌ی ارسالِ دوباره
        $this->travel(OtpService::RESEND_COOLDOWN + 1)->seconds();
        $this->acceptWithCode($recipient->fresh(), $foreign->id)->assertOk();

        $active = ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $recipient->id)->pluck('complex_id')->sort()->values()->all();
        $this->assertSame(collect([$this->complex->id, $other->id])->sort()->values()->all(), $active);
        $this->assertSame(2, UnitTenure::where('user_id', $recipient->id)->current()->count(), 'دوره‌ی واحدِ مجتمعِ اول با پیوستن به دوم بسته شد.');
        $this->assertSame(ComplexInvitation::ACCEPTED, $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->live_key);
    }

    /** عضویتِ تکراری: حتی اگر دعوتی زنده مانده باشد، عضوِ همین مجتمع دوباره عضو نمی‌شود. */
    public function test_a_duplicate_membership_is_refused_by_the_service(): void
    {
        [$invitation] = app(Membership::class)->invite($this->complex, self::TARGET, UserRole::Owner, $this->unit, $this->manager);
        $recipient = $this->registrant();
        app(MembershipLedger::class)->start($recipient, $this->complex, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT);

        try {
            app(Membership::class)->accept($invitation, $recipient);
            $this->fail('عضویتِ تکراری رد نشد.');
        } catch (DomainException $e) {
            $this->assertSame('account.already_member', $e->errorCode);
        }

        $this->assertSame(0, UnitTenure::where('user_id', $recipient->id)->count());
        $this->assertSame(ComplexInvitation::PENDING, $invitation->fresh()->status);
        $this->assertSame(UserRole::Tenant, $recipient->fresh()->role);
    }

    /**
     * قاعده‌ها در خودِ سرویس هم هستند، نه فقط در کنترلر و FormRequest.
     *
     * خرابکاریِ فاز نشان داد: برداشتنِ این دو لایه از `Membership` هیچ تستی را
     * قرمز نمی‌کرد، چون مسیرِ HTTP پیش از آن جلویش را می‌گیرد. ولی فایلِ
     * معماری (۱۰/۲) همین سرویس را از مسیرهای دیگر هم صدا می‌زند.
     */
    public function test_the_service_itself_binds_the_phone_and_requires_a_unit(): void
    {
        [$invitation] = app(Membership::class)->invite($this->complex, self::TARGET, UserRole::Owner, $this->unit, $this->manager);

        try {
            app(Membership::class)->accept($invitation, $this->registrant('09127779999'));
            $this->fail('پذیرش از حسابِ شماره‌ی دیگر رد نشد.');
        } catch (DomainException $e) {
            $this->assertSame('invitation.wrong_phone', $e->errorCode);
        }

        foreach ([UserRole::Owner, UserRole::Tenant] as $role) {
            try {
                app(Membership::class)->invite($this->complex, '09127770005', $role, null, $this->manager);
                $this->fail('دعوتِ ساکنِ بی‌واحد رد نشد.');
            } catch (DomainException $e) {
                $this->assertSame('membership.unit_required', $e->errorCode);
            }
        }

        $this->assertNull(User::where('phone', '09127779999')->first()->complex_id);
        $this->assertSame(0, ComplexInvitation::where('phone', '09127770005')->count());
    }

    /* ── مدیر و ادمینِ کل ──────────────────────────────────────────────── */

    public function test_a_manager_invitation_makes_a_manager_only_after_acceptance(): void
    {
        $response = $this->as($this->manager)->withConfirmedPassword()
            ->postJson('/api/v1/managers', ['phone' => self::TARGET])
            ->assertCreated()
            ->assertJsonPath('invited', true);

        $this->assertDatabaseMissing('users', ['phone' => self::TARGET]);

        $recipient = $this->registrant();
        $this->acceptWithCode($recipient, (int) $response->json('invitation.id'))->assertOk();

        $this->assertSame(UserRole::ComplexAdmin, $recipient->fresh()->role);
        $this->assertSame($this->complex->id, $recipient->fresh()->complex_id);
        // مدیر به واحد بسته نمی‌شود
        $this->assertSame(0, UnitTenure::where('user_id', $recipient->id)->count());
    }

    /** مسیرِ ساکن هرگز نقشِ مدیر نمی‌دهد. */
    public function test_the_resident_form_cannot_grant_the_manager_role(): void
    {
        $this->as($this->manager)->postJson('/api/v1/residents', [
            'phone' => self::TARGET, 'role' => 'complex_admin', 'unit_id' => $this->unit->id,
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertSame(0, ComplexInvitation::count());
    }

    public function test_the_super_admin_creates_a_complex_with_a_manager_invitation(): void
    {
        $superAdmin = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);

        $response = $this->as($superAdmin)->postJson('/api/v1/system/complexes', [
            'name' => 'مجتمعِ دعوتی', 'type' => 'residential', 'admin_phone' => self::TARGET,
        ])->assertCreated();

        $complex = Complex::where('name', 'مجتمعِ دعوتی')->firstOrFail();
        $this->assertDatabaseMissing('users', ['phone' => self::TARGET]);
        $this->assertStringContainsString('/invite#', $response->json('link'));

        $recipient = $this->registrant();
        $this->acceptWithCode($recipient, (int) $response->json('invitation.id'))->assertOk();
        $this->assertSame($complex->id, $recipient->fresh()->complex_id);
        $this->assertSame(UserRole::ComplexAdmin, $recipient->fresh()->role);
    }

    /** شماره‌ی عضوِ مجتمعِ دیگر: نه دعوت، و نه مجتمعِ بی‌مدیرِ نیمه‌کاره. */
    public function test_a_refused_admin_phone_leaves_no_half_built_complex(): void
    {
        $superAdmin = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
        $taken = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);

        $this->as($superAdmin)->postJson('/api/v1/system/complexes', [
            'name' => 'مجتمعِ نیمه‌کاره', 'type' => 'residential', 'admin_phone' => $taken->phone,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('complexes', ['name' => 'مجتمعِ نیمه‌کاره']);
        $this->assertSame(0, ComplexInvitation::count());
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /** @return array{0: int, 1: string} */
    private function inviteViaApi(string $phone = self::TARGET): array
    {
        $response = $this->as($this->manager)->postJson('/api/v1/residents', [
            'phone' => $phone, 'role' => 'owner', 'unit_id' => $this->unit->id,
        ])->assertCreated();

        return [(int) $response->json('invitation.id'), (string) $response->json('link')];
    }

    private function tokenOf(string $link): string
    {
        $this->assertStringContainsString('/invite#', $link);

        return substr($link, strrpos($link, '#') + 1);
    }

    /** حسابی که صاحبِ شماره خودش با OTP ساخته: اولیه، بی‌مجتمع. */
    private function registrant(string $phone = self::TARGET): User
    {
        return User::factory()->create([
            'complex_id' => null, 'role' => UserRole::Owner, 'phone' => $phone, 'is_active' => true,
            'first_name' => 'صاحب', 'last_name' => 'شماره',
        ]);
    }

    private function acceptWithCode(User $user, int $id): TestResponse
    {
        $code = $this->as($user)->postJson("/api/v1/invitations/{$id}/code")->assertOk()->json('devCode');

        return $this->as($user)->postJson("/api/v1/invitations/{$id}/accept", ['code' => $code]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        // پس از جهشِ زمانیِ تست‌های انقضا، عمرِ نشستِ قبلی تمام است (`EnforceSessionLifetime`)
        $this->flushSession();

        return $this->actingAs($user);
    }
}
