<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Unit;
use App\Models\UnitTenure;
use App\Models\User;
use App\Services\Units\TenureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مالکیت، سکونت و تاریخچه‌ی واحد (R26).
 *
 * ─── چیزی که این تست‌ها واقعاً محافظت می‌کنند ────────────────────────────────
 * جدولِ `unit_user` از اول برای نگه‌داشتنِ سابقه ساخته شده بود، ولی کدی که
 * ساکن را جابه‌جا می‌کرد از `syncWithoutDetaching` استفاده می‌کرد — و آن،
 * ردیفِ موجودِ همان (واحد، کاربر) را **بازنویسی** می‌کند. یعنی طرح درست بود
 * و رفتار غلط، بی‌آنکه هیچ خطایی رخ بدهد.
 *
 * پس محورِ این آزمون‌ها یک جمله است: **هیچ دوره‌ای پاک نمی‌شود.**
 */
class UnitTenureTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private TenureService $tenures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();
        $this->manager = $this->makeUser(UserRole::ComplexAdmin);
        $this->tenures = app(TenureService::class);
    }

    private function makeUser(UserRole $role = UserRole::Owner): User
    {
        return User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function unit(string $number = '1'): Unit
    {
        return Unit::factory()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => $number,
        ]);
    }

    // ── تاریخچه پاک نمی‌شود ────────────────────────────────────────────────

    public function test_returning_to_the_same_unit_keeps_the_earlier_period(): void
    {
        $unit = $this->unit();
        $tenant = $this->makeUser(UserRole::Tenant);

        $first = $this->tenures->open($unit, $tenant, ResidentRelation::Tenant);
        $this->tenures->close($first, now()->addYear());

        // همان مستاجر، همان واحد، دو سال بعد
        $second = $this->tenures->open($unit, $tenant, ResidentRelation::Tenant, 100, now()->addYears(3));

        $this->assertSame(2, UnitTenure::where('unit_id', $unit->id)->count());
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotNull($first->fresh()->end_date);
    }

    public function test_moving_to_another_unit_closes_the_old_period_with_an_end_date(): void
    {
        $old = $this->unit('1');
        $new = $this->unit('2');
        $tenant = $this->makeUser(UserRole::Tenant);

        $first = $this->tenures->open($old, $tenant, ResidentRelation::Tenant);
        $this->tenures->open($new, $tenant, ResidentRelation::Tenant);

        $closed = $first->fresh();

        $this->assertFalse($closed->is_current);
        // ⚠️ پیش از R26 این `null` می‌ماند و دوره‌ی بسته‌شده تاریخ پایان نداشت
        $this->assertNotNull($closed->end_date);
        $this->assertSame(2, UnitTenure::where('user_id', $tenant->id)->count());
    }

    public function test_a_units_bills_survive_a_change_of_resident(): void
    {
        $unit = $this->unit();
        $first = $this->makeUser(UserRole::Tenant);
        $second = $this->makeUser(UserRole::Tenant);

        $this->tenures->open($unit, $first, ResidentRelation::Tenant);
        foreach (['1405-03', '1405-04', '1405-05'] as $period) {
            Bill::create([
                'complex_id' => $this->complex->id,
                'unit_id' => $unit->id,
                'period' => $period,
                'base_amount' => 500000,
                'total_amount' => 500000,
                'due_date' => now()->addDays(10),
            ]);
        }

        $this->tenures->close($unit->tenures()->current()->firstOrFail());
        $this->tenures->open($unit, $second, ResidentRelation::Tenant);

        // پرونده به واحد بسته است، نه به کسی که امروز آنجا زندگی می‌کند
        $this->assertSame(3, $unit->bills()->count());
    }

    public function test_the_dossier_endpoint_shows_past_periods_too(): void
    {
        $unit = $this->unit();
        $past = $this->makeUser(UserRole::Tenant);
        $now = $this->makeUser(UserRole::Tenant);

        $this->tenures->open($unit, $past, ResidentRelation::Tenant);
        $this->tenures->close($unit->tenures()->current()->firstOrFail());
        $this->tenures->open($unit, $now, ResidentRelation::Tenant);

        $response = $this->actingAs($this->manager)->getJson("/api/v1/units/{$unit->id}");

        $response->assertOk();
        $names = collect($response->json('tenures'))->pluck('name')->all();

        $this->assertContains($past->name, $names);
        $this->assertContains($now->name, $names);

        // جاری اول می‌آید تا مدیر وضعیتِ امروز را در یک نگاه ببیند
        $this->assertTrue($response->json('tenures.0.isCurrent'));
    }

    // ── چند مالک و سهم ─────────────────────────────────────────────────────

    public function test_a_unit_can_have_several_owners_with_shares(): void
    {
        $unit = $this->unit();

        $this->tenures->open($unit, $this->makeUser(), ResidentRelation::Owner, 60);
        $this->tenures->open($unit, $this->makeUser(), ResidentRelation::Owner, 40);

        $this->assertSame(2, $unit->tenures()->current()->owners()->count());
        $this->assertEqualsWithDelta(
            100.0,
            (float) $unit->tenures()->current()->owners()->sum('share_percent'),
            0.01,
        );
    }

    public function test_shares_beyond_one_hundred_percent_are_refused(): void
    {
        $unit = $this->unit();
        $this->tenures->open($unit, $this->makeUser(), ResidentRelation::Owner, 70);

        $this->expectExceptionMessage('جمع سهم مالکان از ۱۰۰ درصد بیشتر می‌شود');
        $this->tenures->open($unit, $this->makeUser(), ResidentRelation::Owner, 40);
    }

    public function test_a_second_owner_does_not_evict_the_first(): void
    {
        $unit = $this->unit();
        $first = $this->makeUser();

        $this->tenures->open($unit, $first, ResidentRelation::Owner, 50);
        $this->tenures->open($unit, $this->makeUser(), ResidentRelation::Owner, 50);

        // بستنِ دوره‌ها فقط برای **خودِ کاربر** است، نه بقیه‌ی ساکنانِ واحد
        $this->assertTrue($first->fresh()->units()->wherePivot('is_current', true)->exists());
    }

    // ── انتقال مالکیت ──────────────────────────────────────────────────────

    public function test_transferring_ownership_closes_the_old_owners_and_opens_the_new(): void
    {
        $unit = $this->unit();
        $seller = $this->makeUser();
        $buyer = $this->makeUser();

        $this->tenures->open($unit, $seller, ResidentRelation::Owner, 100);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/units/{$unit->id}/transfer-ownership", [
                'owners' => [['user_id' => $buyer->id, 'share_percent' => 100]],
            ])
            ->assertOk();

        $current = $unit->tenures()->current()->owners()->get();

        $this->assertCount(1, $current);
        $this->assertSame($buyer->id, $current->first()->user_id);

        // فروشنده پاک نمی‌شود؛ دوره‌اش بسته می‌شود
        $sellerTenure = UnitTenure::where('user_id', $seller->id)->firstOrFail();
        $this->assertFalse($sellerTenure->is_current);
        $this->assertNotNull($sellerTenure->end_date);
    }

    public function test_a_transfer_whose_shares_do_not_add_up_is_refused(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/units/{$unit->id}/transfer-ownership", [
                'owners' => [
                    ['user_id' => $this->makeUser()->id, 'share_percent' => 60],
                    ['user_id' => $this->makeUser()->id, 'share_percent' => 30],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $unit->tenures()->count());
    }

    public function test_thirds_are_accepted_despite_floating_point(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/units/{$unit->id}/transfer-ownership", [
                'owners' => [
                    ['user_id' => $this->makeUser()->id, 'share_percent' => 33.33],
                    ['user_id' => $this->makeUser()->id, 'share_percent' => 33.33],
                    ['user_id' => $this->makeUser()->id, 'share_percent' => 33.34],
                ],
            ])
            ->assertOk();

        $this->assertSame(3, $unit->tenures()->current()->owners()->count());
    }

    public function test_an_owner_from_another_complex_cannot_be_given_the_unit(): void
    {
        $unit = $this->unit();

        $outsider = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id,
            'role' => UserRole::Owner,
        ]);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/units/{$unit->id}/transfer-ownership", [
                'owners' => [['user_id' => $outsider->id, 'share_percent' => 100]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owners.0.user_id');
    }

    public function test_a_manager_of_another_complex_cannot_open_the_dossier(): void
    {
        $unit = $this->unit();

        $outsider = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        // ۴۰۴ و نه ۴۰۳ — شناسه‌ی مجتمعِ دیگر نباید حتی وجودش تایید شود
        $this->actingAs($outsider)->getJson("/api/v1/units/{$unit->id}")->assertNotFound();
    }

    // ── بستنِ دستیِ دوره ────────────────────────────────────────────────────

    public function test_a_manager_can_end_a_period_manually(): void
    {
        $unit = $this->unit();
        $tenant = $this->makeUser(UserRole::Tenant);
        $tenure = $this->tenures->open($unit, $tenant, ResidentRelation::Tenant);

        $this->actingAs($this->manager)
            ->patchJson("/api/v1/units/{$unit->id}/tenures/{$tenure->id}/end")
            ->assertOk();

        $this->assertFalse($tenure->fresh()->is_current);
        $this->assertNotNull($tenure->fresh()->end_date);
    }

    public function test_a_period_of_another_unit_cannot_be_ended_through_this_unit(): void
    {
        $mine = $this->unit('1');
        $other = $this->unit('2');
        $tenure = $this->tenures->open($other, $this->makeUser(UserRole::Tenant), ResidentRelation::Tenant);

        $this->actingAs($this->manager)
            ->patchJson("/api/v1/units/{$mine->id}/tenures/{$tenure->id}/end")
            ->assertNotFound();

        $this->assertTrue($tenure->fresh()->is_current);
    }

    public function test_an_end_date_before_the_start_is_refused(): void
    {
        $unit = $this->unit();
        $tenure = $this->tenures->open($unit, $this->makeUser(UserRole::Tenant), ResidentRelation::Tenant);

        $this->expectExceptionMessage('تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.');
        $this->tenures->close($tenure, now()->subYear());
    }

    // ── انباری ─────────────────────────────────────────────────────────────

    public function test_a_unit_records_its_storage_rooms(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/units', [
            'unit_number' => '101',
            'floor' => 1,
            'area' => 90,
            'residents_count' => 3,
            'parking_count' => 1,
            'storage_count' => 2,
            'occupancy_status' => 'owner_occupied',
            'coefficient' => 1,
        ])->assertCreated();

        $this->assertSame(2, Unit::latest('id')->firstOrFail()->storage_count);

        $this->assertSame(
            2,
            $this->actingAs($this->manager)->getJson('/api/v1/units')->json('data.0.storageCount'),
        );
    }

    // ── مسیرِ واقعیِ ساکن (همان چیزی که باگ داشت) ───────────────────────────

    public function test_reassigning_a_resident_through_the_api_does_not_erase_history(): void
    {
        $old = $this->unit('1');
        $new = $this->unit('2');

        $this->actingAs($this->manager)->postJson('/api/v1/residents', [
            'name' => 'مستاجر آزمایشی',
            'phone' => '09123334444',
            'role' => UserRole::Tenant->value,
            'unit_id' => $old->id,
            'password' => 'secret1234',
        ])->assertCreated();

        $resident = User::where('phone', '09123334444')->firstOrFail();

        $this->actingAs($this->manager)->putJson("/api/v1/residents/{$resident->id}", [
            'name' => 'مستاجر آزمایشی',
            'phone' => '09123334444',
            'role' => UserRole::Tenant->value,
            'unit_id' => $new->id,
        ])->assertOk();

        /*
         * ⚠️ رگرسیونِ اصلیِ این مرحله: پیش از R26 اینجا فقط **یک** ردیف
         * می‌ماند، چون `syncWithoutDetaching` ردیفِ قبلی را بازنویسی می‌کرد.
         */
        $this->assertSame(2, UnitTenure::where('user_id', $resident->id)->count());
        $this->assertSame(1, UnitTenure::where('user_id', $resident->id)->where('is_current', true)->count());
        $this->assertNotNull(
            UnitTenure::where('user_id', $resident->id)->where('unit_id', $old->id)->firstOrFail()->end_date,
        );
    }
}
