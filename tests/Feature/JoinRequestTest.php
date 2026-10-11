<?php

namespace Tests\Feature;

use App\Enums\AccountState;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * درخواستِ پیوستن از سمتِ واحد (R21b).
 *
 * تا پیش از این، تنها راهِ پیوستن این بود که **مدیر** اول اقدام کند. ساکنی
 * که مدیرش سراغش نمی‌آمد هیچ کاری از دستش برنمی‌آمد.
 */
class JoinRequestTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $applicant;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع آفتاب']);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::ComplexAdmin,
            'phone' => '09120001111',
            'is_active' => true,
        ]);

        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id]);

        $this->applicant = User::factory()->create([
            'complex_id' => null,
            'role' => UserRole::Owner,
            'name' => 'زهرا کریمی',
            'phone' => '09125556666',
            'is_active' => true,
        ]);
    }

    /* ── اعتبارسنجیِ لحظه‌ای ────────────────────────────────────────────── */

    public function test_a_managers_phone_resolves_to_their_complex_name(): void
    {
        /*
         * نامِ مجتمع برگردانده می‌شود تا کاربر **پیش از فرستادن** مطمئن شود
         * درخواستش کجا می‌رود. بدونِ آن ممکن بود نام و شماره‌اش را برای
         * مجتمعِ اشتباهی بفرستد.
         */
        $this->actingAs($this->applicant)
            ->getJson('/api/v1/join-requests/lookup?phone=09120001111')
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('complexName', 'مجتمع آفتاب');
    }

    public function test_an_unknown_phone_is_not_found(): void
    {
        $this->actingAs($this->applicant)
            ->getJson('/api/v1/join-requests/lookup?phone=09129998888')
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('complexName', null);
    }

    public function test_a_plain_residents_phone_is_not_a_manager(): void
    {
        // ساکنِ عادیِ همان مجتمع نباید به‌عنوان مدیر شناخته شود
        $resident = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner,
            'phone' => '09127778888',
            'is_active' => true,
        ]);

        $this->actingAs($this->applicant)
            ->getJson("/api/v1/join-requests/lookup?phone={$resident->phone}")
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_a_deactivated_manager_is_not_found(): void
    {
        $this->manager->update(['is_active' => false]);

        $this->actingAs($this->applicant)
            ->getJson('/api/v1/join-requests/lookup?phone=09120001111')
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_lookup_requires_authentication(): void
    {
        // وگرنه هرکسی می‌توانست شماره‌ها را بپیماید و مدیرها را پیدا کند
        $this->getJson('/api/v1/join-requests/lookup?phone=09120001111')
            ->assertStatus(401);
    }

    /* ── ارسالِ درخواست ─────────────────────────────────────────────────── */

    public function test_sending_a_request_reaches_the_managers_inbox(): void
    {
        $this->actingAs($this->applicant)
            ->postJson('/api/v1/join-requests', ['phone' => '09120001111'])
            ->assertStatus(202);

        $this->actingAs($this->manager)
            ->getJson('/api/v1/join-requests')
            ->assertOk()
            // مدیر باید بداند چه کسی درخواست داده: نام و شماره
            ->assertJsonPath('data.0.name', 'زهرا کریمی')
            ->assertJsonPath('data.0.phone', '09125556666');
    }

    public function test_the_applicant_is_not_attached_before_approval(): void
    {
        $this->actingAs($this->applicant)
            ->postJson('/api/v1/join-requests', ['phone' => '09120001111'])
            ->assertStatus(202);

        $this->assertNull($this->applicant->fresh()->complex_id);
    }

    public function test_a_request_to_a_non_manager_is_refused(): void
    {
        $this->actingAs($this->applicant)
            ->postJson('/api/v1/join-requests', ['phone' => '09129998888'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'join_request.manager_not_found');
    }

    public function test_an_existing_member_cannot_send_a_request(): void
    {
        $member = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);

        $this->actingAs($member)
            ->postJson('/api/v1/join-requests', ['phone' => '09120001111'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'account.already_member');
    }

    public function test_sending_twice_does_not_flood_the_inbox(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->applicant)
                ->postJson('/api/v1/join-requests', ['phone' => '09120001111'])
                ->assertStatus(202);
        }

        $this->assertSame(1, ComplexInvitation::requests()->pending()->count());
    }

    /**
     * مدیر از راهِ اعلانِ داشبورد باخبر می‌شود (تصمیمِ کارفرما؛ مرحلهٔ ۹ ·
     * اصلاحاتِ پس از فاز ۶) — فقط مدیرانِ همین مجتمع، یک بار، بی شماره‌ی متقاضی.
     */
    public function test_the_complexes_managers_get_a_dashboard_notification_once(): void
    {
        $resident = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);
        $foreignManager = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true,
        ]);

        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111'])->assertStatus(202);
        $this->actingAs($this->applicant)->postJson('/api/v1/join-requests', ['phone' => '09120001111'])->assertStatus(202);

        $notifications = $this->manager->fresh()->notifications;
        $this->assertCount(1, $notifications);
        $this->assertSame('join_request.received', $notifications->first()->data['type']);
        $this->assertStringContainsString('زهرا کریمی', $notifications->first()->data['body']);
        $this->assertStringNotContainsString('09125556666', json_encode($notifications->first()->data, JSON_UNESCAPED_UNICODE));

        $this->assertCount(0, $resident->fresh()->notifications);
        $this->assertCount(0, $foreignManager->fresh()->notifications);

        // در فهرستِ اعلان‌های داشبورد، با پیوند به صندوقِ درخواست‌ها
        $this->actingAs($this->manager)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonFragment(['title' => 'درخواستِ تازه‌ی عضویت', 'link' => '/residents']);
    }

    /* ── تاییدِ مدیر ────────────────────────────────────────────────────── */

    public function test_approving_adds_the_applicant_to_the_complex(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/join-requests/{$request->id}/approve", $this->placement())
            ->assertOk();

        $joined = $this->applicant->fresh();
        $this->assertSame($this->complex->id, $joined->complex_id);
        $this->assertSame(AccountState::Member, AccountState::of($joined));

        // F6 (مرحلهٔ ۹ · فاز ۶): با دوره‌ی جاری روی همان واحد، نه عضوِ بی‌واحد
        $this->assertSame([$this->unit->id], $joined->currentUnits()->pluck('units.id')->all());
    }

    /**
     * 🔴 F6: تأیید بی واحد پذیرفته نیست.
     *
     * پیش از این همین درخواست عضوِ مجتمع **بی هیچ واحدی** می‌ساخت (Audit ِ ۹/۲:
     * `unit_user` صفر ردیف).
     */
    public function test_approving_without_a_unit_is_refused(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/join-requests/{$request->id}/approve", ['role' => 'owner'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');

        $this->assertNull($this->applicant->fresh()->complex_id);
        $this->assertSame(ComplexInvitation::PENDING, $request->fresh()->status);
    }

    /** واحدِ مجتمعِ دیگر یا واحدِ حذف‌شده هم نه. */
    public function test_a_foreign_or_deleted_unit_is_refused(): void
    {
        $request = $this->makeRequest();
        $foreign = Unit::factory()->create(['complex_id' => Complex::factory()->create()->id]);
        $deleted = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $deleted->delete();

        foreach ([$foreign, $deleted] as $unit) {
            $this->actingAs($this->manager)
                ->postJson("/api/v1/join-requests/{$request->id}/approve", ['role' => 'owner', 'unit_id' => $unit->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors('unit_id');
        }

        $this->assertNull($this->applicant->fresh()->complex_id);
    }

    public function test_the_manager_chooses_the_role(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/join-requests/{$request->id}/approve", $this->placement('tenant'))
            ->assertOk();

        $this->assertSame(UserRole::Tenant, $this->applicant->fresh()->role);
    }

    public function test_rejecting_leaves_the_applicant_alone(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/join-requests/{$request->id}/reject")
            ->assertOk();

        $this->assertNull($this->applicant->fresh()->complex_id);
    }

    /**
     * مدیرِ مجتمعِ دیگر نباید بتواند این درخواست را تایید کند.
     *
     * وگرنه هر مدیری با حدسِ شناسه می‌توانست متقاضیِ مجتمعِ دیگری را به
     * مجتمعِ خودش بکشد.
     */
    public function test_another_complexes_manager_cannot_approve(): void
    {
        $request = $this->makeRequest();

        $otherComplex = Complex::factory()->create();
        $otherManager = User::factory()->create([
            'complex_id' => $otherComplex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);
        // ورودیِ معتبر برای مجتمعِ خودش، تا فقط مالکیتِ درخواست سنجیده شود
        $ownUnit = Unit::factory()->create(['complex_id' => $otherComplex->id]);

        $this->actingAs($otherManager)
            ->postJson("/api/v1/join-requests/{$request->id}/approve", ['role' => 'owner', 'unit_id' => $ownUnit->id])
            ->assertNotFound();

        $this->assertNull($this->applicant->fresh()->complex_id);
    }

    public function test_approving_twice_is_refused(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->manager)->postJson("/api/v1/join-requests/{$request->id}/approve", $this->placement())->assertOk();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/join-requests/{$request->id}/approve", $this->placement())
            ->assertStatus(422);
    }

    /**
     * اگر متقاضی بینِ ارسال و تایید جای دیگری عضو شده باشد، تایید نباید
     * بی‌سروصدا از مجتمعِ فعلی‌اش بیرونش بکشد.
     */
    public function test_approving_someone_who_already_joined_elsewhere_is_refused(): void
    {
        $request = $this->makeRequest();

        app(MembershipLedger::class)->start($this->applicant, Complex::factory()->create(), UserRole::Owner, ComplexMembership::SOURCE_DIRECT);
        $elsewhere = $this->applicant->fresh()->complex_id;

        $this->actingAs($this->manager)
            ->postJson("/api/v1/join-requests/{$request->id}/approve", $this->placement())
            ->assertStatus(422);

        $this->assertSame($elsewhere, $this->applicant->fresh()->complex_id);
    }

    /* ── قفلِ حالتِ اولیه نباید مانع شود ────────────────────────────────── */

    public function test_the_read_only_lock_still_allows_sending_a_request(): void
    {
        /*
         * قفلِ R21 پیش‌فرضش بستن است؛ اگر این مسیر مستثنا نمی‌شد، تنها راهِ
         * بیرون آمدن از حالتِ اولیه خودش قفل می‌ماند.
         */
        $this->actingAs($this->applicant)
            ->postJson('/api/v1/join-requests', ['phone' => '09120001111'])
            ->assertStatus(202);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /** @return array{role: string, unit_id: int} */
    private function placement(string $role = 'owner'): array
    {
        return ['role' => $role, 'unit_id' => $this->unit->id];
    }

    private function makeRequest(): ComplexInvitation
    {
        return ComplexInvitation::create([
            'complex_id' => $this->complex->id,
            'user_id' => $this->applicant->id,
            'role' => UserRole::Owner,
            'direction' => ComplexInvitation::REQUEST,
            'invited_by' => $this->applicant->id,
            'status' => ComplexInvitation::PENDING,
        ]);
    }
}
