<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\MessagingAccess;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * بستنِ پیام‌رسان روی عضویتِ همان مجتمع — مرحلهٔ ۱۰ · فاز ۹ (یافته‌ی ۱۰/۶؛ همان خانواده‌ی بندهای ۱۲ و ۷۴).
 *
 * تا ۱۰/۹ «بستنِ پیام‌رسان» در صفحه‌ی ساکنین `users.can_message` ِ **حساب** را می‌نوشت: مدیرِ الف پیام‌رسانِ همان
 * شخص را در ب هم می‌بست، جایی که عضوِ سالم است.
 */
class MembershipMessagingTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    private User $managerA;

    private User $managerB;

    private User $mina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'الف', 'messenger_enabled' => true]);
        $this->b = Complex::factory()->create(['name' => 'ب', 'messenger_enabled' => true]);
        $this->managerA = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);
        $this->managerB = User::factory()->create(['complex_id' => $this->b->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true]);

        // مینا: مالکِ الف و مستأجرِ ب؛ زمینه‌ی فعال الف
        $this->mina = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::Owner, 'is_active' => true, 'name' => 'مینا']);
        app(TenureService::class)->open(Unit::factory()->create(['complex_id' => $this->a->id]), $this->mina, ResidentRelation::Owner, 100, now()->subYear());
        app(MembershipLedger::class)->start($this->mina, $this->b, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT, activate: false);
        app(TenureService::class)->open(Unit::factory()->create(['complex_id' => $this->b->id]), $this->mina, ResidentRelation::Tenant, 100, now()->subMonth());
    }

    public function test_muting_in_one_community_leaves_the_account_and_the_other_community_untouched(): void
    {
        $this->as($this->managerA)->patchJson("/api/v1/residents/{$this->mina->id}/toggle-messaging")->assertOk()
            ->assertJsonPath('resident.canMessage', false);

        $this->assertTrue((bool) $this->mina->fresh()->can_message, 'ستونِ حساب دست نمی‌خورد.');
        $this->assertTrue(MessagingAccess::blocked($this->mina, $this->a->id));
        $this->assertFalse(MessagingAccess::blocked($this->mina, $this->b->id));

        // در الف نمی‌نویسد
        $this->send($this->a)->assertForbidden();
        $this->assertSame('ارسال پیام برای شما در این مجتمع محدود شده است.', $this->list()->json('reason'));

        // در ب می‌نویسد
        $this->as($this->mina)->postJson('/api/v1/account/context', ['complex_id' => $this->b->id])->assertOk();
        $this->mina = $this->mina->fresh();
        $this->send($this->b)->assertCreated();
        $this->assertTrue($this->list()->json('canSend'));

        // هر مدیر وضعیتِ مجتمعِ خودش را می‌بیند
        $this->assertFalse($this->row($this->managerA)['canMessage']);
        $this->assertTrue($this->row($this->managerB)['canMessage']);

        // و بازکردن هم فقط همان‌جا
        $this->as($this->managerA)->patchJson("/api/v1/residents/{$this->mina->id}/toggle-messaging")->assertOk()
            ->assertJsonPath('resident.canMessage', true);
        $this->assertFalse(MessagingAccess::blocked($this->mina, $this->a->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'resident.messaging_blocked', 'complex_id' => $this->a->id]);
    }

    /** دادهٔ موجود: بستنِ ثبت‌شده‌ی مدیر فقط در همان مجتمع؛ بستنِ بی‌سابقه در همه — چیزی بی‌صدا باز نمی‌شود. */
    public function test_the_migration_moves_old_account_blocks_onto_memberships(): void
    {
        $migration = require base_path('database/migrations/2026_10_15_000001_restructure_messenger_conversations.php');
        $migration->down();

        $other = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::Tenant, 'is_active' => true]);
        app(MembershipLedger::class)->start($other, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT, activate: false);
        DB::table('users')->whereIn('id', [$this->mina->id, $other->id])->update(['can_message' => false]);
        DB::table('audit_logs')->insert([
            'complex_id' => $this->a->id, 'user_id' => $this->managerA->id, 'action' => 'resident.messaging_blocked',
            'subject_type' => User::class, 'subject_id' => $this->mina->id, 'description' => 'x', 'created_at' => now()->subDay(),
        ]);

        $migration->up();

        $this->assertTrue(MessagingAccess::blocked($this->mina, $this->a->id));
        $this->assertFalse(MessagingAccess::blocked($this->mina, $this->b->id), 'بستنِ مدیرِ الف به ب سرایت کرد.');
        $this->assertTrue(MessagingAccess::blocked($other, $this->a->id));
        $this->assertTrue(MessagingAccess::blocked($other, $this->b->id), 'بستنِ بی‌سابقه بی‌صدا باز شد.');
        $this->assertSame(0, DB::table('users')->where('can_message', false)->count(), 'منبعِ دوم ماند.');

        // و برگشت همان حساب‌ها را دوباره می‌بندد
        $migration->down();
        $this->assertEqualsCanonicalizing([$this->mina->id, $other->id], DB::table('users')->where('can_message', false)->pluck('id')->all());
        $migration->up();
    }

    /** 🔴 برگشتِ اضطراری گفت‌وگوهای خصوصی را جلوی مدیر نمی‌گذارد: کدِ پیش از ۱۰/۹ هر پیامی را به مدیر نشان می‌دهد. */
    public function test_rolling_back_removes_private_conversations_instead_of_exposing_them(): void
    {
        app(TenantContext::class)->set(null);
        $saved = app(ConversationDirectory::class)->savedThread($this->a, $this->mina);
        DB::table('messages')->insert([
            'complex_id' => $this->a->id, 'conversation_id' => $saved->id, 'user_id' => $this->mina->id, 'body' => 'یادداشت',
            'audience' => 'management', 'author_name' => 'مینا', 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_10_15_000001_restructure_messenger_conversations.php');
        $migration->down();

        $this->assertSame(0, DB::table('messages')->where('body', 'یادداشت')->count());
        $migration->up();
    }

    private function send(Complex $complex): TestResponse
    {
        app(TenantContext::class)->set(null);
        $chat = app(ConversationDirectory::class)->userThread($complex, $this->mina);

        return $this->as($this->mina)->postJson("/api/v1/messenger/conversations/{$chat->id}/messages", ['body' => 'سلام']);
    }

    private function list(): TestResponse
    {
        return $this->as($this->mina)->getJson('/api/v1/messenger/conversations')->assertOk();
    }

    /** @return array<string, mixed> */
    private function row(User $manager): array
    {
        return collect($this->as($manager)->getJson('/api/v1/residents')->assertOk()->json('data'))->firstWhere('id', $this->mina->id);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
