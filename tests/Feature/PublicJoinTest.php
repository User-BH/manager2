<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\JoinRequestNotification;
use App\Services\Account\MembershipLedger;
use App\Services\Account\PublicJoinLink;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * QR ِ ورودِ عمومیِ مجتمع (مرحلهٔ ۱۰ · فاز ۵؛ بندهای ۲۲، ۲۳ و ۲۵ ِ فایلِ معماری).
 *
 *   QR ← صفحه‌ی پیوستن ← ثبت‌نام/ورود ← درخواستِ عضویت ← بررسیِ مدیر ← تأیید ← عضویتِ فعال ← داشبوردِ واحد
 *
 * «صرفِ اسکنِ QR به معنی عضویت نیست» — هر آزمون یکی از حلقه‌های این زنجیره را از سمتِ مهاجم یا کاربرِ
 * عادی می‌سنجد.
 */
class PublicJoinTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع QR']);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '12']);
    }

    /* ── سمتِ مدیر ──────────────────────────────────────────────────────── */

    public function test_showing_the_qr_twice_keeps_the_printed_code(): void
    {
        $this->as($this->manager)->getJson('/api/v1/join-link')
            ->assertOk()->assertExactJson(['link' => null, 'createdAt' => null]);

        $first = $this->as($this->manager)->postJson('/api/v1/join-link')->assertCreated()->json('link');
        $this->assertMatchesRegularExpression('~/join#[A-Za-z0-9_-]{22}$~', $first);

        // دوباره «نمایشِ QR»: همان کد — QR ِ چاپ‌شده در لابی باطل نمی‌شود
        $this->as($this->manager)->postJson('/api/v1/join-link')->assertOk()->assertJsonPath('link', $first);
        $this->as($this->manager)->getJson('/api/v1/join-link')->assertOk()->assertJsonPath('link', $first);

        $this->assertDatabaseHas('audit_logs', ['action' => 'join_link.created']);
    }

    public function test_rotating_retires_the_old_qr_at_once(): void
    {
        $old = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));
        $new = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link/rotate')->assertOk()->json('link'));

        $this->assertNotSame($old, $new);
        $this->postJson('/api/v1/join-link/preview', ['code' => $old])->assertNotFound();
        $this->postJson('/api/v1/join-link/preview', ['code' => $new])->assertOk();

        // درخواست با QR ِ قدیمی هم رد می‌شود، نه فقط پیش‌نمایش
        $this->as($this->registrant())->postJson('/api/v1/join-requests', ['code' => $old])
            ->assertUnprocessable()->assertJsonPath('code', 'join_link.invalid');
        $this->assertSame(0, ComplexInvitation::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'join_link.rotated']);
    }

    public function test_disabling_turns_public_joining_off(): void
    {
        $code = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));

        $this->as($this->manager)->deleteJson('/api/v1/join-link')->assertOk()->assertJsonPath('link', null);

        $this->postJson('/api/v1/join-link/preview', ['code' => $code])->assertNotFound();
        $this->as($this->registrant())->postJson('/api/v1/join-requests', ['code' => $code])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'join_link.disabled']);
    }

    public function test_only_this_complexes_manager_sees_or_changes_the_code(): void
    {
        $code = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));

        $tenant = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true,
        ]);
        foreach ([['get', ''], ['post', ''], ['post', '/rotate'], ['delete', '']] as [$method, $suffix]) {
            $this->as($tenant)->json($method, '/api/v1/join-link'.$suffix)->assertForbidden();
        }
        $this->as($this->registrant())->getJson('/api/v1/join-link')->assertForbidden();

        // مدیرِ مجتمعِ دیگر فقط کدِ مجتمعِ خودش را می‌بیند و می‌چرخاند
        $other = Complex::factory()->create();
        $otherManager = User::factory()->create([
            'complex_id' => $other->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);
        $this->as($otherManager)->getJson('/api/v1/join-link')->assertJsonPath('link', null);
        $this->as($otherManager)->postJson('/api/v1/join-link/rotate')->assertOk();
        $this->assertSame($code, $this->complex->fresh()->join_code);

        // کد در هیچ پاسخِ دیگری نیست — نه برای ساکن، نه در خروجیِ مدل
        $this->assertStringNotContainsString($code, $this->as($tenant)->getJson('/api/v1/me')->getContent());
        $this->assertArrayNotHasKey('join_code', $this->complex->fresh()->toArray());
    }

    /* ── سمتِ دارنده‌ی QR ──────────────────────────────────────────────── */

    public function test_the_preview_tells_a_guest_only_the_complex_name(): void
    {
        $code = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/join-link/preview', ['code' => $code])
            ->assertOk()->assertExactJson(['complexName' => 'مجتمع QR']);

        /*
         * 🔴 و برای حسابِ اولیه‌ای که همین حالا با QR ثبت‌نام کرده و وارد شده: پیش‌نمایش POST است و قفلِ
         * حالتِ اولیه (`LockInitialAccount`) پیش از استثنا ۴۰۳ می‌داد — یعنی دقیقاً کاربرِ این مسیر نامِ
         * مجتمع را نمی‌دید.
         */
        $this->as($this->registrant())->postJson('/api/v1/join-link/preview', ['code' => $code])
            ->assertOk()->assertExactJson(['complexName' => 'مجتمع QR']);
    }

    public function test_a_wrong_malformed_case_changed_or_suspended_code_all_look_the_same(): void
    {
        $code = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));
        $flipped = strtoupper($code) !== $code ? strtoupper($code) : strtolower($code);

        foreach (['', 'abc', str_repeat('A', 22), $flipped, $code.'x', ['x']] as $bad) {
            $this->postJson('/api/v1/join-link/preview', ['code' => $bad])->assertNotFound();
        }

        // مجتمعِ تعلیق‌شده عضوِ تازه نمی‌پذیرد — از چشمِ اسکن‌کننده، نه مدیرِ همان مجتمع
        $this->complex->update(['is_active' => false]);
        $applicant = $this->registrant();
        $this->as($applicant)->postJson('/api/v1/join-link/preview', ['code' => $code])->assertNotFound();
        $this->as($applicant)->postJson('/api/v1/join-requests', ['code' => $code])
            ->assertUnprocessable()->assertJsonPath('code', 'join_link.invalid');
    }

    public function test_scanning_and_requesting_is_not_membership_until_the_manager_approves(): void
    {
        Notification::fake();
        $code = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));
        $applicant = $this->registrant();

        $this->as($applicant)->postJson('/api/v1/join-requests', ['code' => $code])
            ->assertStatus(202)->assertJsonPath('message', fn (string $m) => str_contains($m, 'مجتمع QR'));

        // تا پیش از تأیید: active membership = false (بندِ ۲۳)
        $this->assertSame(0, ComplexMembership::withoutGlobalScopes()->where('user_id', $applicant->id)->count());
        $this->assertNull($applicant->fresh()->complex_id);
        $this->assertSame(AccountState::Initial->value, $this->as($applicant)->getJson('/api/v1/me')->json('user.accountState'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'join_request.sent']);
        Notification::assertSentTo($this->manager, JoinRequestNotification::class);

        // درخواست در همان صندوقِ مدیر، کنارِ درخواستِ با شماره‌ی مدیر
        $request = $this->as($this->manager)->getJson('/api/v1/join-requests')->assertOk()->json('data.0');
        $this->assertSame($applicant->phone, $request['phone']);

        $this->as($this->manager)->postJson("/api/v1/join-requests/{$request['id']}/approve", [
            'role' => 'tenant', 'unit_id' => $this->unit->id,
        ])->assertOk();

        $membership = ComplexMembership::withoutGlobalScopes()->where('user_id', $applicant->id)->sole();
        $this->assertSame(ComplexMembership::ACTIVE, $membership->status);
        $this->assertSame(ComplexMembership::SOURCE_JOIN_REQUEST, $membership->source);
        $me = $this->as($applicant->fresh())->getJson('/api/v1/me')->json('user');
        $this->assertSame(AccountState::Member->value, $me['accountState']);
        $this->assertSame('tenant', $me['role']);
        $this->assertSame([], app(MembershipLedger::class)->violations());
    }

    public function test_a_rejected_request_leaves_no_membership(): void
    {
        $code = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));
        $applicant = $this->registrant();
        $this->as($applicant)->postJson('/api/v1/join-requests', ['code' => $code])->assertStatus(202);

        $id = ComplexInvitation::requests()->sole()->id;
        $this->as($this->manager)->postJson("/api/v1/join-requests/{$id}/reject")->assertOk();

        $this->assertSame(0, ComplexMembership::withoutGlobalScopes()->where('user_id', $applicant->id)->count());
        $this->assertNull($applicant->fresh()->complex_id);
    }

    public function test_scanning_twice_makes_one_request_and_one_notification(): void
    {
        Notification::fake();
        $code = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));
        $applicant = $this->registrant();

        $this->as($applicant)->postJson('/api/v1/join-requests', ['code' => $code])->assertStatus(202);
        $this->as($applicant)->postJson('/api/v1/join-requests', ['code' => $code])->assertStatus(202);

        $this->assertSame(1, ComplexInvitation::requests()->pending()->count());
        Notification::assertSentToTimes($this->manager, JoinRequestNotification::class, 1);
    }

    public function test_a_member_of_this_complex_is_refused_and_a_member_elsewhere_may_ask(): void
    {
        $code = $this->codeOf($this->as($this->manager)->postJson('/api/v1/join-link')->json('link'));

        $resident = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true,
        ]);
        $this->as($resident)->postJson('/api/v1/join-requests', ['code' => $code])
            ->assertUnprocessable()->assertJsonPath('code', 'account.already_member');

        // عضوِ مجتمعِ دیگر درخواست می‌دهد و عضویتِ قبلی‌اش دست نمی‌خورد (۱۰/۴)
        $elsewhere = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id, 'role' => UserRole::Tenant, 'is_active' => true,
        ]);
        $this->as($elsewhere)->postJson('/api/v1/join-requests', ['code' => $code])->assertStatus(202);
        $this->assertSame(1, ComplexMembership::withoutGlobalScopes()->active()->where('user_id', $elsewhere->id)->count());
    }

    public function test_the_code_is_unguessable_and_unique_per_complex(): void
    {
        $links = app(PublicJoinLink::class);
        $codes = collect(range(1, 20))->map(fn () => $links->rotate(Complex::factory()->create()));

        $this->assertSame(20, $codes->unique()->count());
        $codes->each(fn (string $c) => $this->assertMatchesRegularExpression(PublicJoinLink::PATTERN, $c));
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function codeOf(string $link): string
    {
        $this->assertStringContainsString('/join#', $link);

        return substr($link, strrpos($link, '#') + 1);
    }

    /** حسابِ اولیه، بی‌مجتمع — همان که ثبت‌نام می‌سازد. */
    private function registrant(): User
    {
        return User::factory()->create([
            'complex_id' => null, 'role' => UserRole::Owner, 'phone' => '09127770077', 'is_active' => true,
        ]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
