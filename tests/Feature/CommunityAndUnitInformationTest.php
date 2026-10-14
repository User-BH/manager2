<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «اطلاعاتِ مجتمع» و «اطلاعاتِ واحد» — مرحلهٔ ۱۰ · فاز ۷ (بندهای ۲، ۷، ۹ و ۱۸ ِ فایلِ معماری).
 *
 * دو اصل در هر آزمون: آنچه از داده بیرون می‌آید (شمارش‌ها، مالک و مستأجر) ذخیره نمی‌شود و با داده عوض می‌شود
 * (بندِ ۲)؛ و ورودی‌های شارژ — مساحت، طبقه، ساکنان، ضریب — از مسیرِ «اطلاعاتِ واحد» که مالک و مستأجر هم صدایش
 * می‌زنند هرگز نوشته نمی‌شوند.
 */
class CommunityAndUnitInformationTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    private User $owner;

    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع اطلاعات', 'type' => ComplexType::Residential]);
        $this->manager = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $this->unit = Unit::factory()->create([
            'complex_id' => $this->complex->id, 'unit_number' => '5', 'floor' => 2, 'area' => 120, 'residents_count' => 3, 'coefficient' => 1,
        ]);
        $this->owner = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);
        $this->tenant = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true]);
        app(TenureService::class)->open($this->unit, $this->owner, ResidentRelation::Owner, 100, now()->subYear());
        app(TenureService::class)->open($this->unit, $this->tenant, ResidentRelation::Tenant, 100, now()->subMonth());
    }

    /* ── اطلاعاتِ مجتمع ───────────────────────────────────────────────── */

    public function test_the_manager_completes_the_community_information_and_the_prompt_goes_away(): void
    {
        $this->assertFalse($this->as($this->manager)->getJson('/api/v1/dashboard')->json('complexInformationCompleted'));

        $this->as($this->manager)->putJson('/api/v1/complex/information', $this->complexPayload())
            ->assertOk()
            ->assertJsonPath('information.city', 'تهران')
            ->assertJsonPath('information.postalCode', '1234567890')
            ->assertJsonPath('information.facilities', ['elevator', 'parking']);

        $this->assertNotNull($this->complex->fresh()->information_completed_at);
        $this->assertTrue($this->as($this->manager)->getJson('/api/v1/dashboard')->json('complexInformationCompleted'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'complex.information_updated']);
    }

    public function test_the_counts_come_from_the_data_and_follow_it(): void
    {
        Building::create(['complex_id' => $this->complex->id, 'name' => 'الف', 'floors_count' => 6]);
        Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '6', 'unit_type' => 'shop', 'block' => 'B']);
        $this->unit->forceFill(['block' => 'A'])->save();

        $derived = $this->as($this->manager)->getJson('/api/v1/complex/information')->assertOk()->json('derived');
        $this->assertSame([1, 2, 6, 2, 1, 1, 1, 1, 1], [
            $derived['buildings'], $derived['blocks'], $derived['floors'], $derived['units'], $derived['commercialSpaces'],
            $derived['owners'], $derived['tenants'], $derived['occupiedUnits'], $derived['vacantUnits'],
        ]);
        $this->assertSame([$this->manager->name], $derived['managers']);

        // مستأجر می‌رود: شمارش همان لحظه عوض می‌شود — چیزی ذخیره نشده بود که کهنه بماند
        app(Membership::class)->end($this->tenant, $this->manager, $this->complex->id);
        $derived = $this->as($this->manager)->getJson('/api/v1/complex/information')->json('derived');
        $this->assertSame(0, $derived['tenants']);
    }

    public function test_the_type_is_not_changed_here_and_validation_is_server_side(): void
    {
        $this->as($this->manager)->putJson('/api/v1/complex/information', [...$this->complexPayload(), 'type' => 'commercial'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame(ComplexType::Residential, $this->complex->fresh()->type);

        $this->as($this->manager)->putJson('/api/v1/complex/information', [
            ...$this->complexPayload(), 'postal_code' => '123', 'facilities' => ['spaceport'], 'latitude' => 120,
        ])->assertUnprocessable()->assertJsonValidationErrors(['postal_code', 'facilities.0', 'latitude']);

        // نشانی، استان و شهر هستهٔ «تکمیل‌شده»اند
        $this->as($this->manager)->putJson('/api/v1/complex/information', ['name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['address', 'province', 'city']);
        $this->assertNull($this->complex->fresh()->information_completed_at);

        // ارقامِ فارسیِ کدِ پستی پذیرفته می‌شود
        $this->as($this->manager)->putJson('/api/v1/complex/information', [...$this->complexPayload(), 'postal_code' => '۱۲۳۴۵۶۷۸۹۰'])
            ->assertOk()->assertJsonPath('information.postalCode', '1234567890');
    }

    public function test_only_this_complexes_manager_reads_or_writes_it(): void
    {
        $this->as($this->owner)->getJson('/api/v1/complex/information')->assertForbidden();
        $this->as($this->owner)->putJson('/api/v1/complex/information', $this->complexPayload())->assertForbidden();

        $other = Complex::factory()->create();
        $otherManager = User::factory()->create(['complex_id' => $other->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $this->as($otherManager)->putJson('/api/v1/complex/information', $this->complexPayload())->assertOk();

        // مدیرِ دیگر فقط مجتمعِ خودش را نوشت
        $this->assertNull($this->complex->fresh()->city);
        $this->assertSame('تهران', $other->fresh()->city);
    }

    /* ── اطلاعاتِ واحد ─────────────────────────────────────────────────── */

    public function test_a_member_completes_the_unit_information_and_the_prompt_goes_away(): void
    {
        $this->assertFalse($this->as($this->owner)->getJson('/api/v1/dashboard')->json('units.0.informationCompleted'));

        $this->as($this->tenant)->putJson("/api/v1/units/{$this->unit->id}/information", $this->unitPayload())
            ->assertOk()
            ->assertJsonPath('information.unitType', 'residential')
            ->assertJsonPath('information.meters.water', '12345')
            ->assertJsonPath('managed.area', 120);

        $this->assertTrue($this->as($this->owner)->getJson('/api/v1/dashboard')->json('units.0.informationCompleted'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'unit.information_updated', 'user_id' => $this->tenant->id]);
    }

    public function test_billing_inputs_are_never_written_here_not_even_by_the_manager(): void
    {
        foreach ([$this->owner, $this->manager] as $actor) {
            $this->as($actor)->putJson("/api/v1/units/{$this->unit->id}/information", [
                ...$this->unitPayload(), 'area' => 30, 'residents_count' => 1, 'coefficient' => 0.1, 'floor' => 0, 'unit_number' => '99',
            ])->assertUnprocessable()->assertJsonValidationErrors(['area', 'residents_count', 'coefficient', 'floor', 'unit_number']);
        }

        $fresh = $this->unit->fresh();
        $this->assertSame([120.0, 3, 2, '5'], [(float) $fresh->area, (int) $fresh->residents_count, (int) $fresh->floor, $fresh->unit_number]);
        $this->assertNull($fresh->information_completed_at);
    }

    public function test_owner_and_tenant_come_from_current_tenures_not_a_copy(): void
    {
        $people = $this->as($this->owner)->getJson("/api/v1/units/{$this->unit->id}/information")->assertOk()->json('people');
        $this->assertEqualsCanonicalizing([$this->owner->name, $this->tenant->name], array_column($people, 'name'));

        // مستأجر می‌رود: دیگر در اطلاعاتِ واحد نیست
        app(Membership::class)->end($this->tenant, $this->manager, $this->complex->id);
        $people = $this->as($this->owner)->getJson("/api/v1/units/{$this->unit->id}/information")->json('people');
        $this->assertSame([$this->owner->name], array_column($people, 'name'));
    }

    public function test_another_units_resident_a_former_tenant_or_another_complex_get_nothing(): void
    {
        $neighbourUnit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $neighbour = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner, 'is_active' => true]);
        app(TenureService::class)->open($neighbourUnit, $neighbour, ResidentRelation::Owner);

        $this->as($neighbour)->getJson("/api/v1/units/{$this->unit->id}/information")->assertNotFound();
        $this->as($neighbour)->putJson("/api/v1/units/{$this->unit->id}/information", $this->unitPayload())->assertNotFound();
        // بدنه‌ی نامعتبر هم ۴۰۴ — مجوز پیش از اعتبارسنجی؛ ۴۲۲ یعنی «این واحد را پیدا کردم» (خرابکاریِ ۱۰/۷)
        $this->as($neighbour)->putJson("/api/v1/units/{$this->unit->id}/information", ['unit_type' => 'castle'])->assertNotFound();

        /*
         * مستأجری که از **این واحد** رفته ولی هنوز در همین مجتمع ساکن است (به واحدِ همسایه رفته): عضویتش فعال است و
         * اتصالِ مدلِ مسیر جلویش را نمی‌گیرد — فقط «دوره‌ی فعلی در همین واحد» (خرابکاریِ ۱۰/۷).
         */
        $tenure = UnitTenure::withoutGlobalScopes()->where('unit_id', $this->unit->id)->where('user_id', $this->tenant->id)->sole();
        app(TenureService::class)->close($tenure);
        app(TenureService::class)->open($neighbourUnit, $this->tenant, ResidentRelation::Tenant);
        $this->as($this->tenant->fresh())->getJson("/api/v1/units/{$this->unit->id}/information")->assertNotFound();
        $this->as($this->tenant->fresh())->getJson("/api/v1/units/{$neighbourUnit->id}/information")->assertOk();

        app(Membership::class)->end($this->tenant, $this->manager, $this->complex->id);
        $this->as($this->tenant->fresh())->getJson("/api/v1/units/{$this->unit->id}/information")->assertNotFound();

        $outsider = User::factory()->create(['complex_id' => Complex::factory()->create()->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $this->as($outsider)->getJson("/api/v1/units/{$this->unit->id}/information")->assertNotFound();
        $this->assertSame(ComplexMembership::ENDED, ComplexMembership::withoutGlobalScopes()->where('user_id', $this->tenant->id)->value('status'));
    }

    public function test_unit_values_are_validated_on_the_server(): void
    {
        $this->as($this->owner)->putJson("/api/v1/units/{$this->unit->id}/information", [
            'unit_type' => 'castle', 'orientation' => 'up', 'rooms_count' => -1, 'meters' => ['water' => '<script>', 'phone' => '1'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['unit_type', 'orientation', 'rooms_count', 'meters', 'meters.water']);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /** @return array<string, mixed> */
    private function complexPayload(): array
    {
        return [
            'name' => 'مجتمع اطلاعات', 'address' => 'خیابانِ اول، پلاکِ ۲', 'province' => 'تهران', 'city' => 'تهران',
            'postal_code' => '1234567890', 'facilities' => ['elevator', 'parking'], 'structure' => 'دو بلوکِ شش‌طبقه',
            'response_hours' => 'شنبه تا چهارشنبه ۹ تا ۱۷', 'emergency_info' => 'آتش‌نشانی ۱۲۵',
        ];
    }

    /** @return array<string, mixed> */
    private function unitPayload(): array
    {
        return [
            'unit_type' => 'residential', 'rooms_count' => 3, 'bedrooms_count' => 2, 'bathrooms_count' => 2,
            'has_terrace' => false, 'has_balcony' => true, 'orientation' => 'south', 'parking_numbers' => 'P-12',
            'meters' => ['water' => '۱۲۳۴۵', 'electricity' => 'E-778'],
        ];
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
