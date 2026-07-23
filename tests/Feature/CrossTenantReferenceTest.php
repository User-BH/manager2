<?php

namespace Tests\Feature;

use App\Enums\OccupancyStatus;
use App\Enums\UserRole;
use App\Models\Building;
use App\Models\Complex;
use App\Models\Discount;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ارجاع بین‌مجتمعی از راه قواعد `exists`.
 *
 * `ComplexScope` روی کوئری‌های Eloquent اعمال می‌شود ولی قاعده‌ی `exists`
 * لاراول کوئری خام می‌زند و از آن رد می‌شود. پس شناسه‌ی واحد یا ساختمانِ
 * مجتمع دیگر از اعتبارسنجی عبور می‌کرد و رکورد با ارجاع متقاطع ساخته می‌شد.
 */
class CrossTenantReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Complex $mine;

    private Complex $theirs;

    private User $admin;

    private Unit $foreignUnit;

    private Building $foreignBuilding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Complex::create(['name' => 'مجتمع من', 'slug' => 'mine-'.uniqid()]);
        $this->theirs = Complex::create(['name' => 'مجتمع دیگر', 'slug' => 'theirs-'.uniqid()]);

        $this->admin = User::create([
            'complex_id' => $this->mine->id, 'name' => 'مدیر من', 'phone' => '09125550001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->foreignUnit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->theirs->id, 'unit_number' => '99', 'floor' => 1,
            'area' => 100, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->foreignBuilding = Building::withoutGlobalScopes()->create([
            'complex_id' => $this->theirs->id, 'name' => 'بلوک بیگانه', 'floors_count' => 5,
        ]);
    }

    public function test_discount_cannot_target_a_unit_of_another_complex(): void
    {
        $this->actingAs($this->admin)->postJson('/api/discounts', [
            'unit_id' => $this->foreignUnit->id,
            'period' => '1405-04',
            'amount' => 50000,
        ])->assertStatus(422)->assertJsonValidationErrors('unit_id');

        $this->assertSame(0, Discount::withoutGlobalScopes()->count());
    }

    public function test_unit_cannot_be_attached_to_a_building_of_another_complex(): void
    {
        $this->actingAs($this->admin)->postJson('/api/units', [
            'unit_number' => '5',
            'building_id' => $this->foreignBuilding->id,
            'floor' => 1, 'area' => 90, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => 'owner_occupied',
        ])->assertStatus(422)->assertJsonValidationErrors('building_id');

        $this->assertSame(0, Unit::withoutGlobalScopes()->where('complex_id', $this->mine->id)->count());
    }

    public function test_resident_cannot_be_linked_to_a_unit_of_another_complex(): void
    {
        $this->actingAs($this->admin)->postJson('/api/residents', [
            'name' => 'ساکن تازه',
            'phone' => '09125559999',
            'role' => 'owner',
            'password' => 'secret123',
            'unit_id' => $this->foreignUnit->id,
        ])->assertStatus(422)->assertJsonValidationErrors('unit_id');

        $this->assertNull(User::where('phone', '09125559999')->first());
    }

    /** قید تازه نباید مسیر درست را بشکند. */
    public function test_references_inside_the_same_complex_still_work(): void
    {
        $ownBuilding = Building::withoutGlobalScopes()->create([
            'complex_id' => $this->mine->id, 'name' => 'بلوک خودی', 'floors_count' => 4,
        ]);

        $this->actingAs($this->admin)->postJson('/api/units', [
            'unit_number' => '5',
            'building_id' => $ownBuilding->id,
            'floor' => 1, 'area' => 90, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => 'owner_occupied',
        ])->assertStatus(201);

        $ownUnit = Unit::withoutGlobalScopes()->where('complex_id', $this->mine->id)->firstOrFail();

        $this->actingAs($this->admin)->postJson('/api/discounts', [
            'unit_id' => $ownUnit->id, 'period' => '1405-04', 'amount' => 50000,
        ])->assertStatus(201);

        $this->actingAs($this->admin)->postJson('/api/residents', [
            'name' => 'ساکن خودی', 'phone' => '09125558888', 'role' => 'owner',
            'password' => 'secret123', 'unit_id' => $ownUnit->id,
        ])->assertStatus(201);
    }

    /** دوره باید الگوی شمسی داشته باشد، وگرنه تخفیفی ثبت می‌شد که هرگز اعمال نمی‌شد. */
    public function test_discount_rejects_a_malformed_period(): void
    {
        $ownUnit = Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->mine->id, 'unit_number' => '7', 'floor' => 1,
            'area' => 90, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);

        $this->actingAs($this->admin)->postJson('/api/discounts', [
            'unit_id' => $ownUnit->id, 'period' => 'abc', 'amount' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors('period');
    }
}
