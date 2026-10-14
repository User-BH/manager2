<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Account\MembershipLedger;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * اطلاعاتِ گروه — مرحلهٔ ۱۰ · فاز ۹ (بندهای ۳۵، ۳۶ و ۷۰ ِ فایلِ معماری).
 *
 * شمار و فهرست فقط از **عضویتِ فعال**: عضوِ معلق، رفته و عضوِ مجتمعِ دیگر در شمار نیست، و همان لحظه‌ای که عضویت
 * عوض می‌شود شمار هم عوض است — چیزی ذخیره نشده که کهنه بماند. فهرست تلفن و ایمیل ندارد.
 */
class GroupInfoTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $owner;

    private User $otherOwner;

    private User $tenant;

    private Unit $unit;

    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['name' => 'مجتمع آفتاب', 'messenger_enabled' => true]);
        $this->manager = $this->person($this->complex, 'مدیر', UserRole::ComplexAdmin);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '10']);
        $unit11 = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '11']);

        $this->owner = $this->person($this->complex, 'مالک ده', UserRole::Owner);
        $this->otherOwner = $this->person($this->complex, 'مالک یازده', UserRole::Owner);
        $this->tenant = $this->person($this->complex, 'مستأجر ده', UserRole::Tenant);
        app(TenureService::class)->open($this->unit, $this->owner, ResidentRelation::Owner, 100, now()->subYear());
        app(TenureService::class)->open($unit11, $this->otherOwner, ResidentRelation::Owner, 100, now()->subYear());
        app(TenureService::class)->open($this->unit, $this->tenant, ResidentRelation::Tenant, 100, now()->subMonth());

        // مجتمعِ دیگر: اعضایش در شمارِ این‌جا نیستند
        $other = Complex::factory()->create();
        $this->person($other, 'بیگانه', UserRole::Owner);

        app(TenantContext::class)->set(null);
        $this->group = app(ConversationDirectory::class)->channel($this->complex);
    }

    public function test_the_counts_come_from_active_memberships_and_follow_them(): void
    {
        $this->assertSame(['members' => 4, 'owners' => 2, 'tenants' => 1, 'managers' => 1], $this->info($this->owner)->json('counts'));

        // معلق شمرده نمی‌شود؛ برگشتش دوباره شمرده می‌شود
        app(MembershipLedger::class)->suspend($this->otherOwner, $this->complex->id, $this->manager);
        $this->assertSame(['members' => 3, 'owners' => 1, 'tenants' => 1, 'managers' => 1], $this->info($this->owner)->json('counts'));

        // رفته هم نه
        app(Membership::class)->end($this->tenant, $this->manager, $this->complex->id);
        $this->assertSame(['members' => 2, 'owners' => 1, 'tenants' => 0, 'managers' => 1], $this->info($this->owner)->json('counts'));
    }

    public function test_the_member_list_shows_photo_name_role_and_unit_but_no_contact_details(): void
    {
        $response = $this->info($this->tenant)->assertOk();

        $this->assertSame('گروهِ مجتمع آفتاب', $response->json('conversation.title'));
        $row = collect($response->json('members'))->firstWhere('id', $this->tenant->id);
        $this->assertSame(['id', 'name', 'avatarUrl', 'role', 'roleLabel', 'unit', 'isModerator'], array_keys($row));
        $this->assertSame(['مستأجر ده', 'tenant', 'واحد 10', false], [$row['name'], $row['role'], $row['unit'], $row['isModerator']]);
        $this->assertSame('complex_admin', collect($response->json('members'))->firstWhere('id', $this->manager->id)['role']);

        $raw = (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        foreach ([$this->tenant->phone, $this->owner->phone, 'بیگانه'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $raw);
        }
    }

    /** گروهِ مدیر: فقط ساکنانِ فعلیِ واحدهای خودش و مدیریت. */
    public function test_a_managers_group_counts_only_the_current_residents_of_its_units(): void
    {
        app(TenantContext::class)->set(null);
        $board = app(ConversationDirectory::class)->createGroup($this->complex, 'طبقه‌ی اول', [$this->unit->id], true, $this->manager);

        $this->assertSame(['members' => 3, 'owners' => 1, 'tenants' => 1, 'managers' => 1], $this->info($this->owner, $board)->json('counts'));
        $this->info($this->otherOwner, $board)->assertNotFound();
    }

    public function test_the_member_list_is_paged(): void
    {
        foreach (range(1, 55) as $i) {
            $this->person($this->complex, 'عضو '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), UserRole::Owner);
        }

        $first = $this->info($this->owner)->assertOk();
        $this->assertCount(50, $first->json('members'));
        $this->assertTrue($first->json('hasMore'));
        $this->assertSame(59, $first->json('counts.members'));

        $second = $this->as($this->owner)->getJson("/api/v1/messenger/conversations/{$this->group->id}/info?page=2")->assertOk();
        $this->assertCount(9, $second->json('members'));
        $this->assertFalse($second->json('hasMore'));
        $this->assertSame([], array_intersect(array_column($first->json('members'), 'id'), array_column($second->json('members'), 'id')));
    }

    public function test_only_groups_have_info_and_only_for_those_who_see_them(): void
    {
        app(TenantContext::class)->set(null);
        $chat = app(ConversationDirectory::class)->userThread($this->complex, $this->owner);
        $this->info($this->owner, $chat)->assertNotFound();

        $other = Complex::factory()->create(['messenger_enabled' => true]);
        $stranger = $this->person($other, 'مدیرِ دیگر', UserRole::ComplexAdmin);
        $this->info($stranger)->assertNotFound();
    }

    /** «فقط مدیریت بنویسد»: فقط مدیر، فقط برای گروه. */
    public function test_only_the_manager_closes_a_group_to_members(): void
    {
        $url = "/api/v1/messenger/conversations/{$this->group->id}/posting";

        $this->as($this->owner)->postJson($url, ['members_can_post' => false])->assertForbidden();
        $this->as($this->manager)->postJson($url, ['members_can_post' => false])->assertOk()
            ->assertJsonPath('conversation.membersCanPost', false);
        $this->assertFalse($this->info($this->owner)->json('conversation.membersCanPost'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'messenger.posting_changed']);

        app(TenantContext::class)->set(null);
        $chat = app(ConversationDirectory::class)->userThread($this->complex, $this->owner);
        $this->as($this->manager)->postJson("/api/v1/messenger/conversations/{$chat->id}/posting", ['members_can_post' => false])
            ->assertUnprocessable()->assertJsonPath('code', 'messenger.not_a_group');
    }

    private function person(Complex $complex, string $name, UserRole $role): User
    {
        static $n = 0;
        $n++;

        return User::factory()->create([
            'complex_id' => $complex->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'phone' => '0915'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
        ]);
    }

    private function info(User $as, ?Conversation $conversation = null): TestResponse
    {
        return $this->as($as)->getJson('/api/v1/messenger/conversations/'.($conversation ?? $this->group)->id.'/info');
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
