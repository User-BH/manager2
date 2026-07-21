<?php

namespace Tests\Feature;

use App\Enums\AnnouncementAudience;
use App\Enums\OccupancyStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Complex;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ابزارهای هدر و حساب کاربری: جستجوی سراسری، اعلان‌ها، پروفایل و اشتراک.
 */
class DashboardToolsTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $admin;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::create([
            'name' => 'مجتمع نگین', 'slug' => 'negin', 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'fake',
        ]);

        $this->admin = User::create([
            'complex_id' => $this->complex->id, 'name' => 'مدیر نگین', 'phone' => '09120000001',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $this->resident = User::create([
            'complex_id' => $this->complex->id, 'name' => 'ساکن نگین', 'phone' => '09120000002',
            'role' => UserRole::Owner, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    private function makeUnit(string $number): Unit
    {
        return Unit::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_number' => $number, 'floor' => 2,
            'area' => 90, 'residents_count' => 2, 'coefficient' => 1,
            'occupancy_status' => OccupancyStatus::OwnerOccupied, 'uses_elevator' => true,
        ]);
    }

    private function makeAnnouncement(string $title, AnnouncementAudience $audience = AnnouncementAudience::All): Announcement
    {
        return Announcement::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id,
            'title' => $title,
            'body' => 'متن اطلاعیه برای آزمون.',
            'audience' => $audience,
            'is_active' => true,
            'published_at' => now(),
        ]);
    }

    /* ------------------------------ جستجو ------------------------------ */

    public function test_search_needs_at_least_two_characters(): void
    {
        $this->actingAs($this->admin)->getJson('/api/search?q=a')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('groups', []);
    }

    public function test_admin_search_finds_units_and_residents(): void
    {
        $this->makeUnit('۱۰۲');
        $this->makeUnit('205');

        $response = $this->actingAs($this->admin)->getJson('/api/search?q=205')->assertOk();

        $groups = collect($response->json('groups'))->keyBy('id');
        $this->assertTrue($groups->has('units'));
        $this->assertSame('واحد 205', $groups['units']['items'][0]['title']);

        // نام ساکن هم باید پیدا شود
        $byName = $this->actingAs($this->admin)->getJson('/api/search?q='.urlencode('ساکن'))->assertOk();
        $this->assertContains('residents', collect($byName->json('groups'))->pluck('id')->all());
    }

    /**
     * مهم‌ترین قید امنیتی جستجو: ساکن نباید از این راه به داده‌ای برسد که
     * صفحه‌اش با میدل‌ور role: برایش بسته است.
     */
    public function test_resident_search_never_returns_units_or_residents(): void
    {
        $this->makeUnit('205');

        $response = $this->actingAs($this->resident)->getJson('/api/search?q=205')->assertOk();
        $groupIds = collect($response->json('groups'))->pluck('id')->all();

        $this->assertNotContains('units', $groupIds);
        $this->assertNotContains('residents', $groupIds);
        $this->assertNotContains('bills', $groupIds);
        $this->assertNotContains('expenses', $groupIds);
    }

    public function test_search_respects_announcement_audience(): void
    {
        $this->makeAnnouncement('اطلاعیه ویژه مستاجران', AnnouncementAudience::Tenants);

        // ساکن با نقش «مالک» نباید اطلاعیه‌ی مخصوص مستاجر را در جستجو ببیند
        $response = $this->actingAs($this->resident)->getJson('/api/search?q='.urlencode('ویژه'))->assertOk();
        $this->assertSame(0, $response->json('total'));

        // ولی مدیر همه را می‌بیند
        $adminResponse = $this->actingAs($this->admin)->getJson('/api/search?q='.urlencode('ویژه'))->assertOk();
        $this->assertSame(1, $adminResponse->json('total'));
    }

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/api/search?q=test')->assertStatus(401);
    }

    /* ----------------------------- اعلان‌ها ----------------------------- */

    public function test_unread_count_drops_after_reading_one(): void
    {
        $first = $this->makeAnnouncement('اطلاعیه اول');
        $this->makeAnnouncement('اطلاعیه دوم');

        $this->actingAs($this->resident)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unreadCount', 2)
            ->assertJsonCount(2, 'items');

        $this->actingAs($this->resident)->postJson("/api/notifications/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('unreadCount', 1);
    }

    public function test_read_all_zeroes_the_counter_and_is_idempotent(): void
    {
        $this->makeAnnouncement('یک');
        $this->makeAnnouncement('دو');
        $this->makeAnnouncement('سه');

        $this->actingAs($this->resident)->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('marked', 3)
            ->assertJsonPath('unreadCount', 0);

        // بار دوم نباید ردیف تکراری بسازد و نه خطا بدهد
        $this->actingAs($this->resident)->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('marked', 0)
            ->assertJsonPath('unreadCount', 0);

        $this->assertSame(3, AnnouncementRead::where('user_id', $this->resident->id)->count());
    }

    /** خوانده‌شدن باید مخصوص هر کاربر باشد، نه سراسری. */
    public function test_read_state_is_per_user(): void
    {
        $this->makeAnnouncement('اطلاعیه مشترک');

        $this->actingAs($this->resident)->postJson('/api/notifications/read-all')->assertOk();

        $this->actingAs($this->admin)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unreadCount', 1);
    }

    /** اطلاعیه‌ای که کاربر حق دیدنش را ندارد نباید قابل «خوانده کردن» باشد. */
    public function test_cannot_mark_an_invisible_announcement_as_read(): void
    {
        $hidden = $this->makeAnnouncement('فقط مستاجرها', AnnouncementAudience::Tenants);

        $this->actingAs($this->resident)->postJson("/api/notifications/{$hidden->id}/read")
            ->assertStatus(403);
    }

    /** نویسنده نباید بابت اطلاعیه‌ی خودش اعلان نخوانده بگیرد. */
    public function test_author_does_not_get_a_notification_for_their_own_announcement(): void
    {
        $this->actingAs($this->admin)->postJson('/api/announcements', [
            'title' => 'اطلاعیه مدیر',
            'body' => 'متن',
            'audience' => 'all',
        ])->assertStatus(201);

        $this->actingAs($this->admin)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unreadCount', 0);

        // ولی ساکن باید آن را نخوانده ببیند
        $this->actingAs($this->resident)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unreadCount', 1);
    }

    /* ----------------------------- پروفایل ----------------------------- */

    public function test_profile_returns_the_signed_in_user_with_units_and_complexes(): void
    {
        $unit = $this->makeUnit('۳۰۱');
        $this->resident->units()->attach($unit->id, [
            'complex_id' => $this->complex->id,
            'relation' => 'owner',
            'share_percent' => 100,
            'is_current' => true,
        ]);

        $this->actingAs($this->resident)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('profile.phone', '09120000002')
            ->assertJsonPath('profile.roleLabel', 'مالک')
            ->assertJsonCount(1, 'units')
            ->assertJsonPath('units.0.relationLabel', 'مالک')
            ->assertJsonPath('stats.unitsCount', 1)
            ->assertJsonCount(1, 'complexes');
    }

    public function test_profile_update_saves_fields_but_never_the_phone(): void
    {
        $this->actingAs($this->resident)->putJson('/api/profile', [
            'name' => 'ساکن ویرایش‌شده',
            'email' => 'resident@example.com',
            'address' => 'خیابان اول',
            // تلاش برای عوض کردن شماره باید بی‌اثر بماند
            'phone' => '09999999999',
        ])->assertOk()->assertJsonPath('profile.name', 'ساکن ویرایش‌شده');

        $this->resident->refresh();
        $this->assertSame('resident@example.com', $this->resident->email);
        $this->assertSame('09120000002', $this->resident->phone);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $this->actingAs($this->resident)->putJson('/api/profile/password', [
            'current_password' => 'wrong-one',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->actingAs($this->resident)->putJson('/api/profile/password', [
            'current_password' => 'secret123',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertOk();

        $this->assertTrue(Hash::check('newsecret123', $this->resident->fresh()->password));
    }

    /* ----------------------------- اشتراک ------------------------------ */

    public function test_subscription_page_lists_plans_with_server_side_prices(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/subscription')->assertOk();

        $this->assertNull($response->json('current'));
        $this->assertCount(2, $response->json('plans'));
        $this->assertSame(SubscriptionPlan::Pro->price(), $response->json('plans.0.price'));
    }

    public function test_checkout_ignores_a_client_supplied_amount(): void
    {
        config(['subscription.gateway' => 'sandbox']);

        $this->actingAs($this->admin)
            ->post('/subscription/checkout', ['plan' => 'pro', 'amount' => 1])
            ->assertRedirect();

        $subscription = Subscription::where('user_id', $this->admin->id)->firstOrFail();

        // مبلغ باید از enum خوانده شود، نه از درخواست
        $this->assertEquals(SubscriptionPlan::Pro->price(), (float) $subscription->amount);
        $this->assertSame('pending', $subscription->status);
    }

    public function test_sandbox_callback_activates_the_subscription_once(): void
    {
        config(['subscription.gateway' => 'sandbox']);

        $this->actingAs($this->admin)->post('/subscription/checkout', ['plan' => 'pro_yearly']);
        $subscription = Subscription::where('user_id', $this->admin->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get("/subscription/callback/{$subscription->id}?ref={$subscription->ref_id}")
            ->assertRedirectContains('checkout=success');

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertNotNull($subscription->tracking_code);
        $this->assertTrue($subscription->ends_at->greaterThan(now()->addMonths(11)));

        $firstEndsAt = $subscription->ends_at;

        // بازگشت دوباره نباید دوره را تمدید کند
        $this->actingAs($this->admin)
            ->get("/subscription/callback/{$subscription->id}?ref={$subscription->ref_id}")
            ->assertRedirectContains('checkout=success');

        $this->assertTrue($firstEndsAt->equalTo($subscription->fresh()->ends_at));
    }

    public function test_a_wrong_reference_marks_the_subscription_failed(): void
    {
        config(['subscription.gateway' => 'sandbox']);

        $this->actingAs($this->admin)->post('/subscription/checkout', ['plan' => 'pro']);
        $subscription = Subscription::where('user_id', $this->admin->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get("/subscription/callback/{$subscription->id}?ref=NOT-THE-REF")
            ->assertRedirectContains('checkout=failed');

        $this->assertSame('failed', $subscription->fresh()->status);
    }

    public function test_a_user_cannot_finish_someone_elses_checkout(): void
    {
        config(['subscription.gateway' => 'sandbox']);

        $this->actingAs($this->admin)->post('/subscription/checkout', ['plan' => 'pro']);
        $subscription = Subscription::where('user_id', $this->admin->id)->firstOrFail();

        $this->actingAs($this->resident)
            ->get("/subscription/callback/{$subscription->id}?ref={$subscription->ref_id}")
            ->assertStatus(403);

        $this->actingAs($this->resident)
            ->postJson("/api/subscription/{$subscription->id}/cancel")
            ->assertStatus(403);
    }

    /** بدون درگاه پیکربندی‌شده، کاربر باید پیام روشن ببیند نه خطای ۵۰۰. */
    public function test_checkout_without_a_configured_gateway_fails_gracefully(): void
    {
        config(['subscription.gateway' => null]);

        $this->actingAs($this->admin)
            ->post('/subscription/checkout', ['plan' => 'pro'])
            ->assertRedirectContains('checkout=error');

        $this->assertSame('failed', Subscription::where('user_id', $this->admin->id)->first()->status);
    }
}
