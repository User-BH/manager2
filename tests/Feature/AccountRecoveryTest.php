<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountRecoveryRequest;
use App\Models\AuditLog;
use App\Models\Complex;
use App\Models\OtpCode;
use App\Models\TrustedDevice;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * بازیابیِ حساب وقتی شماره‌ی قبلی در دسترس نیست (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * «اثباتِ شماره‌ی تازه به‌تنهایی حساب را نمی‌دهد»: درخواست فقط ثبت می‌شود و
 * شماره تنها با تأییدِ ادمینِ کل عوض می‌شود. مسیرِ عادیِ تغییرِ شماره جداست
 * (`PhoneChangeTest`).
 */
class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = '09126660101';

    private const NEW = '09126660199';

    private User $owner;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $complex = Complex::factory()->create(['name' => 'مجتمع بازیابی']);
        $this->owner = User::factory()->create([
            'complex_id' => $complex->id, 'role' => UserRole::Owner, 'is_active' => true,
            'phone' => self::OLD, 'password' => Hash::make('secret123'), 'national_id' => '0012345678',
        ]);
        $unit = Unit::factory()->create(['complex_id' => $complex->id]);
        app(WalletService::class)->credit($this->owner, $unit, 80000, WalletTransaction::SOURCE_ADJUSTMENT);
        $this->superAdmin = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    /** درخواست ثبت می‌شود و **هیچ‌چیز** عوض نمی‌شود تا ادمینِ کل تأیید کند. */
    public function test_a_verified_request_changes_nothing_until_approved(): void
    {
        $this->submit()->assertCreated();

        $request = AccountRecoveryRequest::sole();
        $this->assertSame([AccountRecoveryRequest::PENDING, $this->owner->id], [$request->status, $request->user_id]);
        $this->assertSame(self::OLD, $this->owner->fresh()->phone);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_recovery.requested']);
    }

    /**
     * تأییدِ ادمینِ کل: شماره عوض، همه‌ی نشست‌ها و دستگاه‌ها باطل، کیف و حساب همان،
     * و ورود با شماره‌ی تازه کار می‌کند.
     */
    public function test_approval_moves_the_phone_and_ends_every_session(): void
    {
        $this->submit()->assertCreated();
        TrustedDevice::create(['user_id' => $this->owner->id, 'token_hash' => hash('sha256', Str::random(40)), 'expires_at' => now()->addDays(5)]);
        $rememberBefore = $this->owner->fresh()->remember_token;

        $this->as($this->superAdmin)->withConfirmedPassword()
            ->postJson('/api/v1/system/account-recovery/'.AccountRecoveryRequest::sole()->id.'/approve', ['note' => 'تماسِ تصویری و کارتِ ملی'])
            ->assertOk();

        $owner = $this->owner->fresh();
        $this->assertSame(self::NEW, $owner->phone);
        $this->assertNotSame($rememberBefore, $owner->remember_token);
        $this->assertSame(0, TrustedDevice::where('user_id', $owner->id)->count());
        $this->assertSame(80000.0, app(WalletService::class)->balance($owner));
        $this->assertSame(AccountRecoveryRequest::APPROVED, AccountRecoveryRequest::sole()->status);
        $audit = AuditLog::where('action', 'account_recovery.approved')->sole();
        $this->assertSame([self::OLD, self::NEW, $this->superAdmin->id], [$audit->properties['old_phone'], $audit->properties['new_phone'], $audit->user_id]);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/v1/login', ['phone' => self::NEW, 'password' => 'secret123'])->assertOk();
    }

    /** پاسخ نمی‌گوید شماره‌ی قبلی حساب دارد؛ درخواستِ بی‌حساب هرگز تأیید نمی‌شود. */
    public function test_an_unknown_old_phone_gets_the_same_answer_and_cannot_be_approved(): void
    {
        $known = $this->submit()->assertCreated();
        $unknown = $this->submit(old: '09126660155', new: '09126660177')->assertCreated();
        $this->assertSame($known->json('message'), $unknown->json('message'));

        $orphan = AccountRecoveryRequest::where('old_phone', '09126660155')->sole();
        $this->assertNull($orphan->user_id);
        $this->as($this->superAdmin)->withConfirmedPassword()
            ->postJson("/api/v1/system/account-recovery/{$orphan->id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'account_recovery.no_account');
    }

    /** شماره‌ی تازه‌ای که حساب دارد: کدی نمی‌گیرد و حتی با کد هرگز به حساب نمی‌رسد (ادغام نه). */
    public function test_a_new_phone_that_belongs_to_another_account_is_never_given(): void
    {
        $other = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'phone' => self::NEW]);

        $taken = $this->postJson('/api/v1/account-recovery/code', ['new_phone' => self::NEW])->assertOk();
        $free = $this->postJson('/api/v1/account-recovery/code', ['new_phone' => '09126660188'])->assertOk();
        $this->assertNull($taken->json('devCode'));
        $this->assertSame($free->json('message'), $taken->json('message'));

        // حتی اگر کسی کدِ معتبرِ آن شماره را داشته باشد
        OtpCode::where('phone', self::NEW)->delete();
        OtpCode::create(['phone' => self::NEW, 'code_hash' => Hash::make('123456'), 'attempts' => 0, 'expires_at' => now()->addMinute()]);
        $this->postJson('/api/v1/account-recovery', $this->payload(code: '123456'))->assertCreated();

        $this->as($this->superAdmin)->withConfirmedPassword()
            ->postJson('/api/v1/system/account-recovery/'.AccountRecoveryRequest::sole()->id.'/approve')
            ->assertStatus(422)->assertJsonPath('code', 'account_recovery.unavailable');
        $this->assertSame(self::OLD, $this->owner->fresh()->phone);
        $this->assertSame(self::NEW, $other->fresh()->phone);
    }

    public function test_a_wrong_or_expired_code_records_nothing(): void
    {
        $code = $this->postJson('/api/v1/account-recovery/code', ['new_phone' => self::NEW])->json('devCode');
        $wrong = $code === '111111' ? '222222' : '111111';

        $this->postJson('/api/v1/account-recovery', $this->payload(code: $wrong))->assertStatus(422)->assertJsonValidationErrors('code');
        $this->travel(200)->seconds();
        $this->postJson('/api/v1/account-recovery', $this->payload(code: (string) $code))->assertStatus(422);

        $this->assertSame(0, AccountRecoveryRequest::count());
    }

    /** ردِ با دلیل؛ تصمیمِ گرفته‌شده و درخواستِ کهنه دوباره تأیید نمی‌شوند. */
    public function test_decided_or_stale_requests_cannot_be_approved(): void
    {
        $this->submit()->assertCreated();
        $id = AccountRecoveryRequest::sole()->id;

        $this->as($this->superAdmin)->withConfirmedPassword()->postJson("/api/v1/system/account-recovery/{$id}/reject")
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->as($this->superAdmin)->withConfirmedPassword()->postJson("/api/v1/system/account-recovery/{$id}/reject", ['note' => 'شواهد کافی نبود'])
            ->assertOk();
        $this->as($this->superAdmin)->withConfirmedPassword()->postJson("/api/v1/system/account-recovery/{$id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'account_recovery.not_pending');

        $this->submit(new: '09126660166')->assertCreated();
        $stale = AccountRecoveryRequest::where('status', AccountRecoveryRequest::PENDING)->sole();
        $this->travel(AccountRecoveryRequest::VALID_DAYS + 1)->days();
        $this->as($this->superAdmin)->withConfirmedPassword()->postJson("/api/v1/system/account-recovery/{$stale->id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'account_recovery.not_pending');

        $this->assertSame(self::OLD, $this->owner->fresh()->phone);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_recovery.rejected']);
    }

    /** ادمینِ کل بازیابیِ حسابِ خودش را تأیید نمی‌کند. */
    public function test_a_super_admin_cannot_approve_their_own_recovery(): void
    {
        $this->superAdmin->forceFill(['phone' => '09126660111'])->save();
        $this->submit(old: '09126660111')->assertCreated();

        $this->as($this->superAdmin)->withConfirmedPassword()
            ->postJson('/api/v1/system/account-recovery/'.AccountRecoveryRequest::sole()->id.'/approve')
            ->assertStatus(422)->assertJsonPath('code', 'account_recovery.self_approval');
        $this->assertSame('09126660111', $this->superAdmin->fresh()->phone);
    }

    /** فقط ادمینِ کل، با تأییدِ دوباره‌ی رمز؛ مدیر، ساکن و مهمان نه. */
    public function test_only_a_reconfirmed_super_admin_decides(): void
    {
        $this->submit()->assertCreated();
        $id = AccountRecoveryRequest::sole()->id;
        $manager = User::factory()->create(['complex_id' => $this->owner->complex_id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);

        foreach ([$manager, $this->owner] as $user) {
            $this->as($user)->getJson('/api/v1/system/account-recovery')->assertForbidden();
            $this->as($user)->withConfirmedPassword()->postJson("/api/v1/system/account-recovery/{$id}/approve")->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson("/api/v1/system/account-recovery/{$id}/approve")->assertUnauthorized();

        $this->as($this->superAdmin)->postJson("/api/v1/system/account-recovery/{$id}/approve")
            ->assertStatus(403)->assertJsonPath('code', 'auth.reauth_required');

        $listed = $this->as($this->superAdmin)->getJson('/api/v1/system/account-recovery')->assertOk()->json('data.0');
        $this->assertSame([true, $this->owner->id], [$listed['nationalIdMatches'], $listed['account']['id']]);
        $this->assertSame(self::OLD, $this->owner->fresh()->phone);
    }

    public function test_the_code_never_reaches_the_log_and_submissions_are_capped(): void
    {
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $code = (string) $this->postJson('/api/v1/account-recovery/code', ['new_phone' => self::NEW])->json('devCode');
        $this->postJson('/api/v1/account-recovery', $this->payload(code: $code))->assertCreated();

        $others = array_filter($logged, fn (string $line) => ! str_starts_with($line, '[SMS:log]'));
        $this->assertStringNotContainsString($code, implode("\n", $others).json_encode(AuditLog::all()->toArray()));

        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->postJson('/api/v1/account-recovery', $this->payload(code: '000000'))->status();
        }
        $this->assertSame(429, end($statuses), 'سقفِ روزانه‌ی هر IP اعمال نشد: '.implode(',', $statuses));
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function submit(string $old = self::OLD, string $new = self::NEW): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $code = $this->postJson('/api/v1/account-recovery/code', ['new_phone' => $new])->assertOk()->json('devCode');

        return $this->postJson('/api/v1/account-recovery', $this->payload($old, $new, (string) $code));
    }

    /** @return array<string, string> */
    private function payload(string $old = self::OLD, string $new = self::NEW, string $code = '000000'): array
    {
        return [
            'old_phone' => $old, 'new_phone' => $new, 'code' => $code, 'national_id' => '0012345678',
            'explanation' => 'سیم‌کارتِ قبلی گم شد و به نامِ من نیست؛ مدیرِ مجتمع می‌شناسدم.',
        ];
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user->fresh());
    }
}
