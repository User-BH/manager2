<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * سیاستِ تاریخچه‌ی پیام برای ساکنِ تازه (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶؛ تصمیمِ کارفرما).
 *
 * • گروهِ عمومیِ مجتمع (`CHANNEL`، «همه‌ی اهالی»): **کلِ تاریخچه**، حتی پیش از عضویت.
 * • هر چیزِ دیگر — رشته‌ی خصوصیِ واحد با مدیریت، رشته‌ی شخصیِ ساکنِ قبلی، گروه‌های
 *   چندواحده‌ی مدیر — فقط از شروعِ دوره‌ی خودِ ساکن؛ و این مرز برای پیام، پیوست،
 *   پیش‌نمایش، جست‌وجو، نخوانده و واکنش در backend است، نه پنهان‌کردن در رابط.
 * • پایانِ عضویتِ ساکنِ قبلی هویتِ نویسنده‌ی پیام‌های تاریخی را عوض نمی‌کند.
 */
class MessengerHistoryPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private Unit $unit;

    private User $alice;

    private Conversation $channel;

    private Conversation $unitThread;

    private Conversation $aliceThread;

    private Conversation $group;

    private Message $oldChannel;

    private Message $oldPrivate;

    private Message $oldPersonal;

    private Message $oldGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);
        $this->manager = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'can_message' => true,
        ]);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '9']);
        $neighbour = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '10']);
        $this->alice = User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => UserRole::Tenant, 'is_active' => true,
            'can_message' => true, 'name' => 'آلیسِ قبلی',
        ]);
        app(TenureService::class)->open($this->unit, $this->alice, ResidentRelation::Tenant, 100, now()->subYear());

        $directory = app(ConversationDirectory::class);
        $this->unitThread = $directory->unitThread($this->complex, $this->unit);
        $this->aliceThread = $directory->userThread($this->complex, $this->alice);
        $this->group = $directory->createGroup($this->complex, 'گروهِ بلوکِ الف', [$this->unit->id, $neighbour->id], true, $this->manager);
        $this->channel = $directory->channel($this->complex);

        $this->travel(-60)->days();
        $this->oldChannel = $this->say($this->channel, $this->manager, 'اطلاعیه‌ی قدیمیِ گروهِ عمومی');
        $this->oldPrivate = $this->say($this->unitThread, $this->alice, 'رازِ خصوصیِ آلیس', 'messages/secret.pdf');
        $this->oldPersonal = $this->say($this->aliceThread, $this->alice, 'رشته‌ی شخصیِ آلیس');
        $this->oldGroup = $this->say($this->group, $this->alice, 'پیامِ قدیمیِ گروهِ بلوک');
        $this->travelBack();
    }

    public function test_the_new_resident_sees_the_whole_public_group_history(): void
    {
        $bob = $this->nextTenant();

        $ids = $this->ids($this->as($bob)->getJson("/api/v1/messenger/conversations/{$this->channel->id}/messages"));
        $this->assertContains($this->oldChannel->id, $ids, 'تاریخچه‌ی گروهِ عمومی برای ساکنِ تازه نیست.');
    }

    public function test_nothing_private_of_the_previous_resident_reaches_the_new_one(): void
    {
        $bob = $this->nextTenant();

        // پیام‌ها
        $this->assertNotContains($this->oldPrivate->id, $this->ids($this->as($bob)->getJson("/api/v1/messenger/conversations/{$this->unitThread->id}/messages")));
        // رشته‌ی شخصیِ ساکنِ قبلی: ساکنِ تازه اصلاً عضوش نیست
        $this->as($bob)->getJson("/api/v1/messenger/conversations/{$this->aliceThread->id}/messages")->assertNotFound();
        // پیوست
        $this->as($bob)->getJson("/api/v1/messenger/{$this->oldPrivate->id}/attachment")->assertNotFound();
        // جست‌وجو
        $this->as($bob)->getJson("/api/v1/messenger/conversations/{$this->unitThread->id}/search?q=".urlencode('رازِ'))
            ->assertOk()->assertJsonCount(0, 'results');
        // واکنش
        $this->as($bob)->postJson("/api/v1/messenger/conversations/{$this->unitThread->id}/messages/{$this->oldPrivate->id}/reactions", ['emoji' => '👍'])
            ->assertNotFound();
        // پیش‌نمایش و نخوانده در فهرست
        $list = $this->as($bob)->getJson('/api/v1/messenger/conversations')->assertOk();
        $this->assertStringNotContainsString('رازِ خصوصیِ آلیس', $this->decoded($list));
        $this->assertStringNotContainsString('رشته‌ی شخصیِ آلیس', $this->decoded($list));
        // اعلان‌های ساکنِ قبلی مالِ حسابِ خودش‌اند
        $this->assertStringNotContainsString('آلیس', $this->decoded($this->as($bob)->getJson('/api/v1/notifications')->assertOk()));
    }

    /** گروهِ چندواحده‌ی مدیر «گروهِ عمومی» نیست: فقط از شروعِ دوره‌ی ساکن. */
    public function test_a_unit_group_shows_only_messages_from_the_new_residents_tenure(): void
    {
        $bob = $this->nextTenant();
        $fresh = $this->say($this->group, $this->manager, 'پیامِ تازه‌ی گروهِ بلوک');

        $ids = $this->ids($this->as($bob)->getJson("/api/v1/messenger/conversations/{$this->group->id}/messages"));
        $this->assertNotContains($this->oldGroup->id, $ids);
        $this->assertContains($fresh->id, $ids);
    }

    /** پایانِ عضویت نویسنده‌ی تاریخی را عوض نمی‌کند؛ مدیر همه را با همان نام می‌بیند. */
    public function test_ending_membership_keeps_the_historical_author(): void
    {
        $this->nextTenant();

        $messages = collect($this->as($this->manager)->getJson("/api/v1/messenger/conversations/{$this->unitThread->id}/messages")->assertOk()->json('messages'));
        $old = $messages->firstWhere('id', $this->oldPrivate->id);
        $this->assertNotNull($old, 'مدیر سابقه را نمی‌بیند.');
        $this->assertSame('آلیسِ قبلی', $old['authorName']);
        $this->assertSame($this->alice->id, Message::withoutGlobalScopes()->find($this->oldPrivate->id)->user_id);
    }

    /**
     * تصمیمِ کارفرما (۴.۳): «گروه‌های چندواحده‌ی مدیر» همان گروهِ عمومی نیستند.
     * گروهِ عمومی یک `CHANNEL` برای هر مجتمع است (کلید `channel`، همه‌ی اهالی)؛
     * گروهِ مدیر `GROUP` است با فهرستِ صریحِ واحدهای انتخابی — یعنی گفت‌وگوی
     * محدود، با همان مرزِ دوره‌ی سکونت.
     */
    public function test_the_public_group_and_a_managers_unit_group_are_different_kinds(): void
    {
        $this->assertSame([Conversation::CHANNEL, 'channel', null], [$this->channel->type, $this->channel->key, $this->channel->unit_id]);
        $this->assertSame(0, $this->channel->units()->count(), 'گروهِ عمومی به واحدِ خاصی بسته شد.');
        $this->assertSame(Conversation::GROUP, $this->group->type);
        $this->assertSame(2, $this->group->units()->count(), 'گروهِ مدیر فهرستِ صریحِ واحد ندارد.');
        $this->assertSame(1, Conversation::withoutGlobalScopes()->where('complex_id', $this->complex->id)->where('type', Conversation::CHANNEL)->count());
    }

    /** شمارِ نخوانده‌ی ساکنِ تازه پیامِ خصوصیِ پیش از او را نمی‌شمارد؛ گروهِ عمومی همه را. */
    public function test_unread_counts_follow_the_same_boundary(): void
    {
        $bob = $this->nextTenant();

        $list = collect($this->as($bob)->getJson('/api/v1/messenger/conversations')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(0, $list[$this->unitThread->id]['unreadCount'] ?? 0, 'نخوانده‌ی رشته‌ی واحد پیامِ ساکنِ قبلی را شمرد.');
        $this->assertSame(0, $list[$this->group->id]['unreadCount'] ?? 0, 'نخوانده‌ی گروهِ واحدها پیامِ پیش از دوره را شمرد.');
        $this->assertSame(1, $list[$this->channel->id]['unreadCount'] ?? 0, 'گروهِ عمومی تاریخچه‌اش را برای ساکنِ تازه نشمرد.');
        $this->assertFalse($list->has($this->aliceThread->id), 'رشته‌ی شخصیِ ساکنِ قبلی در فهرستِ ساکنِ تازه آمد.');
    }

    /** دسترسی به گروهِ عمومی با عضویتِ **فعلی** است: ساکنِ رفته دیگر آن را نمی‌خواند. */
    public function test_a_former_member_no_longer_reads_the_public_group(): void
    {
        $this->nextTenant();
        $alice = User::withoutGlobalScopes()->find($this->alice->id);
        $this->assertNull($alice->complex_id);

        $status = $this->as($alice)->getJson("/api/v1/messenger/conversations/{$this->channel->id}/messages")->status();
        $this->assertContains($status, [403, 404], 'ساکنِ رفته هنوز گروهِ عمومیِ مجتمع را می‌خواند.');
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function say(Conversation $conversation, User $author, string $body, ?string $attachment = null): Message
    {
        $message = Message::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'conversation_id' => $conversation->id, 'user_id' => $author->id,
            'body' => $body, 'client_id' => (string) Str::uuid(), 'author_name' => $author->name,
            'attachment_path' => $attachment, 'attachment_name' => $attachment ? 'secret.pdf' : null,
            'attachment_kind' => $attachment ? 'file' : null,
        ]);
        $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => now()])->save();

        return $message;
    }

    private function nextTenant(): User
    {
        $this->as($this->manager)->deleteJson("/api/v1/residents/{$this->alice->id}")->assertOk();

        $bob = User::factory()->create(['complex_id' => null, 'role' => UserRole::Owner, 'is_active' => true, 'can_message' => true, 'phone' => '09127000009']);
        [$invitation] = app(Membership::class)->invite($this->complex, $bob->phone, UserRole::Tenant, $this->unit, $this->manager);
        app(Membership::class)->accept($invitation, $bob);

        return $bob->fresh();
    }

    /** @return list<int> */
    private function ids(TestResponse $response): array
    {
        return collect($response->assertOk()->json('messages'))->pluck('id')->all();
    }

    private function decoded(TestResponse $response): string
    {
        return (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
