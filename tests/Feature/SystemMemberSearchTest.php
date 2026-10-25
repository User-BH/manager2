<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * جست‌وجوی «کاربران» ِ سوپر ادمین (پیدا شده در ۲۰۲۶-۱۰-۲۵): متنِ بی رقم به شماره‌ی خالی normalize می‌شد و
 * `phone like '%%'` همه‌ی کاربران را برمی‌گرداند.
 */
class SystemMemberSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_name_search_returns_only_matching_names(): void
    {
        $admin = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true, 'name' => 'ادمینِ کل', 'phone' => '09120000071']);
        $ali = User::factory()->create(['name' => 'علی رضایی', 'phone' => '09120000072']);
        User::factory()->create(['name' => 'مینا کریمی', 'phone' => '09120000073']);

        $ids = collect($this->actingAs($admin)->getJson('/api/v1/system/members?q='.urlencode('علی'))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$ali->id], $ids);
    }

    public function test_a_phone_search_still_works_with_persian_digits(): void
    {
        $admin = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true, 'phone' => '09120000071']);
        $mina = User::factory()->create(['name' => 'مینا کریمی', 'phone' => '09120000073']);
        User::factory()->create(['name' => 'علی رضایی', 'phone' => '09120000072']);

        $ids = collect($this->actingAs($admin)->getJson('/api/v1/system/members?q='.urlencode('۰۰۷۳'))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$mina->id], $ids);
    }
}
