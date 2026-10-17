<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حفظِ داده — مرحلهٔ ۱۲ · فاز ۴ (بندهای ۲۱، ۲۳ و ۲۶ ِ سندِ هسته).
 *
 * سیاهه‌ی همه‌ی حذف‌های سختِ کد در `docs/STAGE12_ROADMAP.md` (۱۲/۴) آمده؛ تنها حذفی که سابقه‌ی مالی را می‌برد و
 * جایگزینِ «غیرفعال» داشت حذفِ پکیجِ فروخته‌شده بود: کلیدِ `plan_id` روی اشتراک `SET NULL` است، پس اشتراکِ فعال امکاناتِ
 * پکیج را از دست می‌داد و سابقه‌ی فروش نمی‌گفت چه پکیجی بود. پایانِ عضویت، حذفِ «ساکن» و تعلیقِ مجتمع پیش از این آزمون
 * دارند (`MembershipLifecycleTest`، `DeleteSemanticsTest`).
 */
class DataPreservationTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withConfirmedPassword();
        $this->super = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true, 'phone' => '09125558001']);
    }

    /** 🔴 پکیجِ فروخته‌شده حذف نمی‌شود؛ اشتراک پیوندش را نگه می‌دارد و «غیرفعال» راهِ کنار گذاشتن است. */
    public function test_a_plan_that_was_sold_is_deactivated_not_deleted(): void
    {
        $complex = Complex::factory()->create(['type' => ComplexType::Residential]);
        $plan = Plan::create(['name' => 'طلایی', 'slug' => 'gold', 'price' => 100000, 'months' => 1, 'is_active' => true]);
        $this->actingAs($this->super)->postJson('/api/v1/system/plans/grant', ['complex_id' => $complex->id, 'plan_id' => $plan->id])->assertCreated();
        $subscription = Subscription::withoutGlobalScopes()->where('plan_id', $plan->id)->sole();

        $this->actingAs($this->super)->deleteJson("/api/v1/system/plans/{$plan->id}")
            ->assertStatus(409)->assertJsonPath('code', 'plan.in_use');

        $this->assertNotNull($plan->fresh());
        $this->assertSame($plan->id, $subscription->fresh()->plan_id);

        $this->actingAs($this->super)->patchJson("/api/v1/system/plans/{$plan->id}/toggle")->assertOk();
        $this->assertFalse($plan->fresh()->is_active);
    }

    /** پکیجی که هرگز فروخته نشده هنوز حذف می‌شود — قاعده فقط سابقه را نگه می‌دارد، نه هر پکیجی را. */
    public function test_an_unused_plan_is_still_deleted(): void
    {
        $plan = Plan::create(['name' => 'آزمایشی', 'slug' => 'trial-x', 'price' => 50000, 'months' => 1, 'is_active' => true]);

        $this->actingAs($this->super)->deleteJson("/api/v1/system/plans/{$plan->id}")->assertOk();
        $this->assertNull($plan->fresh());
    }
}
