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
use App\Services\Account\MembershipLedger;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\MessageActions;
use App\Services\Messenger\MessageSender;
use App\Services\Units\TenureService;
use App\Support\PrivateFiles;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ناظرِ گروه، حذف و سنجاق و هدایت — مرحلهٔ ۱۰ · فاز ۱۰ (بندهای ۱۶، ۵۰، ۵۲، ۵۳، ۵۵ تا ۵۹، ۱۰۱ و ۱۱۸ ِ فایلِ معماری).
 *
 * ماتریسِ بندِ ۱۰۱ ستون به ستون: عضو، مدیر، ناظر. ناظر فقط درونِ **همان** گروه اختیار دارد و نقشِ عضویت نیست — هیچ
 * مسیرِ مدیریتی، مالی یا عضویتی برایش باز نمی‌شود.
 */
class GroupModerationTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $owner;

    private User $tenant;

    private User $moderator;

    private Unit $unit;

    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PrivateFiles::name());
        $this->complex = Complex::factory()->create(['name' => 'مجتمع آفتاب', 'messenger_enabled' => true]);
        $this->manager = $this->person('مدیر', UserRole::ComplexAdmin);
        $this->unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '10']);
        $this->owner = $this->person('مالک', UserRole::Owner);
        $this->tenant = $this->person('مستأجر', UserRole::Tenant);
        $this->moderator = $this->person('ناظر', UserRole::Owner);
        app(TenureService::class)->open($this->unit, $this->owner, ResidentRelation::Owner, 100, now()->subYear());
        app(TenureService::class)->open($this->unit, $this->tenant, ResidentRelation::Tenant, 100, now()->subMonth());
        app(TenureService::class)->open(Unit::factory()->create(['complex_id' => $this->complex->id, 'unit_number' => '11']), $this->moderator, ResidentRelation::Owner, 100, now()->subYear());

        app(TenantContext::class)->set(null);
        $this->group = app(ConversationDirectory::class)->channel($this->complex);
        $this->appoint($this->moderator)->assertOk()->assertJsonPath('moderators', [$this->moderator->id]);
    }

    /* ── بندهای ۵۵، ۱۰۱ و ۱۱۸: حذف ─────────────────────────────────────── */

    public function test_in_the_group_only_the_manager_and_the_moderator_delete_others(): void
    {
        $first = $this->say($this->owner, $this->group, 'پیامِ مالک');
        $this->remove($this->tenant, $this->group, $first)->assertForbidden()->assertJsonPath('code', 'messenger.not_yours');

        $this->remove($this->moderator, $this->group, $first)->assertOk()
            ->assertJsonPath('message.isDeleted', true)
            ->assertJsonPath('message.removedByModeration', true);
        $this->assertSame($this->moderator->id, Message::withoutGlobalScopes()->find($first)->deleted_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'messenger.message_removed']);

        $second = $this->say($this->tenant, $this->group, 'پیامِ مستأجر');
        $this->remove($this->manager, $this->group, $second)->assertOk();

        // هر کس پیامِ خودش را
        $own = $this->say($this->tenant, $this->group, 'پیامِ خودم');
        $this->remove($this->tenant, $this->group, $own)->assertOk()->assertJsonPath('message.removedByModeration', false);

        // و منوی پیام همان را می‌گوید
        $third = $this->say($this->owner, $this->group, 'سوم');
        $this->assertTrue($this->row($this->moderator, $this->group, $third)['canDelete']);
        $this->assertFalse($this->row($this->tenant, $this->group, $third)['canDelete']);
    }

    /** در گفت‌وگوی خصوصی هر کس فقط پیامِ خودش — حتی مدیر (بندِ ۵۵). */
    public function test_in_a_private_chat_nobody_deletes_the_other_sides_message(): void
    {
        app(TenantContext::class)->set(null);
        $chat = app(ConversationDirectory::class)->userThread($this->complex, $this->owner);
        $id = $this->say($this->owner, $chat, 'به مدیر');

        $this->remove($this->manager, $chat, $id)->assertForbidden()->assertJsonPath('code', 'messenger.not_yours');
        $this->assertFalse(Message::withoutGlobalScopes()->find($id)->isDeleted());
    }

    /* ── بندهای ۱۶، ۵۷ و ۱۱۸: ناظر مدیر نیست ───────────────────────────── */

    public function test_the_moderator_is_not_a_manager_anywhere_else(): void
    {
        $this->as($this->moderator)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.role', 'owner');

        $this->as($this->moderator)->postJson('/api/v1/residents', ['phone' => '09139990099', 'role' => 'tenant'])->assertForbidden();
        $this->as($this->moderator)->patchJson("/api/v1/residents/{$this->tenant->id}/toggle-active")->assertForbidden();
        $this->as($this->moderator)->postJson('/api/v1/units', ['unit_number' => '99'])->assertForbidden();
        $this->as($this->moderator)->putJson('/api/v1/complex/information', ['name' => 'x'])->assertForbidden();
        $this->as($this->moderator)->postJson("/api/v1/messenger/conversations/{$this->group->id}/posting", ['members_can_post' => false])->assertForbidden();
        $this->as($this->moderator)->postJson("/api/v1/messenger/conversations/{$this->group->id}/moderators", ['user_id' => $this->owner->id, 'moderator' => true])->assertForbidden();

        // نظرسنجی نمی‌سازد (بندِ ۶۲): پیام می‌رود، نظرسنجی نه
        $id = $this->as($this->moderator)->postJson("/api/v1/messenger/conversations/{$this->group->id}/messages", [
            'body' => 'رأی بدهید', 'poll_question' => 'رنگِ لابی؟', 'poll_options' => ['سفید', 'کرم'],
        ])->assertCreated()->json('message.id');
        $this->assertNull(Message::withoutGlobalScopes()->find($id)->poll);
    }

    /** ناظرِ گروهِ مجتمع در گروهِ دیگرِ مدیر اختیاری ندارد. */
    public function test_the_moderator_acts_only_in_their_own_group(): void
    {
        app(TenantContext::class)->set(null);
        $board = app(ConversationDirectory::class)->createGroup($this->complex, 'طبقه‌ها', [$this->unit->id], true, $this->manager);
        // ناظر ساکنِ واحدِ ۱۱ است و عضوِ این گروه نیست؛ مالکِ ۱۰ عضو است ولی ناظرِ این‌جا نه
        $id = $this->say($this->tenant, $board, 'در گروهِ طبقه‌ها');

        $this->remove($this->owner, $board, $id)->assertForbidden();
        $this->as($this->owner)->postJson("/api/v1/messenger/conversations/{$board->id}/pin", ['message_id' => $id])->assertForbidden();
    }

    /** ناظری به عضویت بسته است: معلق یا رفته ناظر نیست، و بازگشت با عضویتِ تازه ناظری را برنمی‌گرداند. */
    public function test_moderation_ends_with_the_membership(): void
    {
        $id = $this->say($this->owner, $this->group, 'x');

        app(MembershipLedger::class)->suspend($this->moderator, $this->complex->id, $this->manager);
        app(MembershipLedger::class)->resume($this->moderator, $this->complex->id);
        $this->remove($this->moderator->fresh(), $this->group, $id)->assertOk(); // همان عضویت، معلق و برگشته

        app(Membership::class)->end($this->moderator, $this->manager, $this->complex->id);
        app(MembershipLedger::class)->start($this->moderator->fresh(), $this->complex, UserRole::Owner, 'direct');
        $other = $this->say($this->owner, $this->group, 'y');
        $this->remove($this->moderator->fresh(), $this->group, $other)->assertForbidden();
    }

    public function test_only_an_active_member_of_the_group_can_be_appointed(): void
    {
        $this->appoint($this->manager)->assertUnprocessable()->assertJsonPath('code', 'messenger.moderator_not_member');

        $stranger = User::factory()->create(['complex_id' => Complex::factory()->create()->id, 'role' => UserRole::Owner, 'is_active' => true]);
        $this->appoint($stranger)->assertUnprocessable();

        app(TenantContext::class)->set(null);
        $chat = app(ConversationDirectory::class)->userThread($this->complex, $this->owner);
        $this->as($this->manager)->postJson("/api/v1/messenger/conversations/{$chat->id}/moderators", ['user_id' => $this->owner->id, 'moderator' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'messenger.not_a_group');

        // تکرار ردیفِ دوم نمی‌سازد؛ برداشتن کار می‌کند
        $this->appoint($this->moderator)->assertOk();
        $this->assertSame(1, DB::table('conversation_moderators')->count());
        $this->as($this->manager)->postJson("/api/v1/messenger/conversations/{$this->group->id}/moderators", ['user_id' => $this->moderator->id, 'moderator' => false])
            ->assertOk()->assertJsonPath('moderators', []);

        // و فهرستِ اعضا ناظر را نشان می‌داد
        $this->appoint($this->moderator);
        $row = collect($this->as($this->owner)->getJson("/api/v1/messenger/conversations/{$this->group->id}/info")->json('members'))->firstWhere('id', $this->moderator->id);
        $this->assertTrue($row['isModerator']);
    }

    /* ── بندهای ۵۸ و ۵۹: سنجاق ─────────────────────────────────────────── */

    public function test_pins_are_many_and_follow_the_matrix(): void
    {
        $a = $this->say($this->manager, $this->group, 'قانونِ پارکینگ');
        $b = $this->say($this->owner, $this->group, 'شماره‌ی تأسیسات');

        $this->pin($this->tenant, $this->group, $a)->assertForbidden();
        $this->pin($this->moderator, $this->group, $a)->assertOk();
        $this->pin($this->manager, $this->group, $b)->assertOk()->assertJsonCount(2, 'pins');

        $view = $this->as($this->tenant)->getJson("/api/v1/messenger/conversations/{$this->group->id}/messages")->assertOk();
        $this->assertSame([$b, $a], array_column($view->json('pins'), 'id'));
        $this->assertSame($b, $view->json('pinned.id'));

        // برداشتنِ یکی؛ حذفِ پیامِ سنجاق‌شده سنجاقش را هم می‌برد
        $this->pin($this->moderator, $this->group, $a, false)->assertOk()->assertJsonCount(1, 'pins');
        $this->remove($this->owner, $this->group, $b)->assertOk();
        $this->assertSame([], $this->as($this->tenant)->getJson("/api/v1/messenger/conversations/{$this->group->id}/messages")->json('pins'));

        // سقف — پر کردنش از خودِ سرویس، نه HTTP: سقفِ نرخِ ارسال واقعی است و این‌جا موضوع نیست
        app(TenantContext::class)->set(null);
        $this->actingAs($this->manager);
        foreach (range(1, MessageActions::MAX_PINS) as $i) {
            [$message] = app(MessageSender::class)->send($this->complex, $this->group, $this->manager, ['body' => "سنجاقِ {$i}"]);
            app(MessageActions::class)->pin($this->group, $message, $this->manager);
        }
        $this->pin($this->manager, $this->group, $this->say($this->owner, $this->group, 'یکی بیشتر'))
            ->assertStatus(409)->assertJsonPath('code', 'messenger.too_many_pins');

        // `null` همه را برمی‌دارد (API ِ پیشین)
        $this->as($this->manager)->postJson("/api/v1/messenger/conversations/{$this->group->id}/pin", ['message_id' => null])->assertOk()->assertJsonPath('pins', []);
    }

    public function test_both_sides_of_a_private_chat_pin(): void
    {
        app(TenantContext::class)->set(null);
        $this->as($this->owner)->getJson('/api/v1/messenger/conversations');
        $pair = Conversation::withoutGlobalScopes()->where('type', Conversation::PAIR)->sole();
        $chat = app(ConversationDirectory::class)->userThread($this->complex, $this->owner);

        $this->pin($this->tenant, $pair, $this->say($this->owner, $pair, 'کلید'))->assertOk();
        $this->pin($this->owner, $pair, $this->say($this->tenant, $pair, 'رمز'))->assertOk()->assertJsonCount(2, 'pins');
        $this->pin($this->owner, $chat, $this->say($this->manager, $chat, 'پاسخِ مدیر'))->assertOk();
        $this->pin($this->manager, $chat, $this->say($this->owner, $chat, 'پرسشِ مالک'))->assertOk();
    }

    /* ── بندهای ۵۲ و ۵۳: هدایت ────────────────────────────────────────── */

    public function test_forwarding_copies_into_a_conversation_the_sender_can_write_in(): void
    {
        $source = $this->as($this->owner)->post("/api/v1/messenger/conversations/{$this->group->id}/messages", [
            'body' => 'برنامه‌ی آب‌قطعی', 'attachment' => UploadedFile::fake()->image('plan.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('message.id');

        app(TenantContext::class)->set(null);
        $saved = app(ConversationDirectory::class)->savedThread($this->complex, $this->tenant);
        $copy = $this->forward($this->tenant, $saved, $source)->assertCreated()
            ->assertJsonPath('message.body', 'برنامه‌ی آب‌قطعی')
            ->assertJsonPath('message.forwardedFrom.authorName', 'مالک')
            ->assertJsonPath('message.authorName', 'مستأجر')
            ->json('message.id');

        $copied = Message::withoutGlobalScopes()->find($copy);
        $original = Message::withoutGlobalScopes()->find($source);
        $this->assertSame($source, $copied->forwarded_from_id);
        $this->assertNotSame($original->attachment_path, $copied->attachment_path, 'پیوست کپی است، نه ارجاع.');

        // حذفِ مبدأ نسخه را پاک نمی‌کند
        $this->remove($this->owner, $this->group, $source)->assertOk();
        Storage::disk(PrivateFiles::name())->assertExists($copied->attachment_path);
        $this->as($this->tenant)->get("/api/v1/messenger/{$copy}/attachment")->assertOk();

        // تکرار با همان client_id پیامِ دوم نمی‌سازد
        $first = $this->say($this->manager, $this->group, 'اطلاعیه');
        $this->forward($this->tenant, $saved, $first, 'fw-1')->assertCreated();
        $this->forward($this->tenant, $saved, $first, 'fw-1')->assertOk();
        $this->assertSame(1, Message::withoutGlobalScopes()->where('forwarded_from_id', $first)->count());
    }

    /** دو دسترسی جدا: مبدأ باید دیدنی باشد، مقصد نوشتنی (بندِ ۵۳). */
    public function test_forwarding_checks_the_source_and_the_target_separately(): void
    {
        app(TenantContext::class)->set(null);
        $this->as($this->owner)->getJson('/api/v1/messenger/conversations');
        $pair = Conversation::withoutGlobalScopes()->where('type', Conversation::PAIR)->sole();
        $secret = $this->say($this->owner, $pair, 'رازِ مالک و مستأجر');
        $managersChat = app(ConversationDirectory::class)->userThread($this->complex, $this->manager);

        // مدیر پیامِ گفت‌وگوی خصوصیِ آن دو را نمی‌بیند، پس هدایتش هم «نیست»
        $this->forward($this->manager, $managersChat, $secret)->assertNotFound();
        $this->assertSame(0, Message::withoutGlobalScopes()->where('forwarded_from_id', $secret)->count());

        // مقصدِ نادیدنی ۴۰۴، مقصدِ فقط‌خواندنی ۴۰۳
        $ownersChat = app(ConversationDirectory::class)->userThread($this->complex, $this->owner);
        $this->forward($this->tenant, $ownersChat, $this->say($this->tenant, $pair, 'x'))->assertNotFound();
        $this->as($this->manager)->postJson("/api/v1/messenger/conversations/{$this->group->id}/posting", ['members_can_post' => false])->assertOk();
        $this->forward($this->tenant, $this->group, $this->say($this->tenant, $pair, 'y'))
            ->assertForbidden()->assertJsonPath('code', 'messenger.read_only');

        // پیامِ حذف‌شده، پنهان‌شده و نظرسنجی هدایت نمی‌شوند
        $saved = app(ConversationDirectory::class)->savedThread($this->complex, $this->owner);
        $gone = $this->say($this->owner, $pair, 'رفته');
        $this->remove($this->owner, $pair, $gone)->assertOk();
        $this->forward($this->owner, $saved, $gone)->assertUnprocessable()->assertJsonPath('code', 'messenger.not_forwardable');
        $poll = $this->as($this->manager)->postJson("/api/v1/messenger/conversations/{$this->group->id}/messages", [
            'poll_question' => 'ساعتِ جلسه؟', 'poll_options' => ['۱۸', '۱۹'],
        ])->assertCreated()->json('message.id');
        $this->forward($this->owner, $saved, $poll)->assertUnprocessable();
    }

    /* ── کمک‌ها ─────────────────────────────────────────────────────────── */

    private function person(string $name, UserRole $role): User
    {
        static $n = 0;
        $n++;

        return User::factory()->create([
            'complex_id' => $this->complex->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'phone' => '0913999'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        ]);
    }

    private function appoint(User $member): TestResponse
    {
        return $this->as($this->manager)->postJson("/api/v1/messenger/conversations/{$this->group->id}/moderators", [
            'user_id' => $member->id, 'moderator' => true,
        ]);
    }

    private function say(User $user, Conversation $conversation, string $body): int
    {
        return (int) $this->as($user)->postJson("/api/v1/messenger/conversations/{$conversation->id}/messages", ['body' => $body])
            ->assertCreated()->json('message.id');
    }

    private function remove(User $user, Conversation $conversation, int $id): TestResponse
    {
        return $this->as($user)->deleteJson("/api/v1/messenger/conversations/{$conversation->id}/messages/{$id}");
    }

    private function pin(User $user, Conversation $conversation, int $id, bool $pinned = true): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/messenger/conversations/{$conversation->id}/pin", ['message_id' => $id, 'pinned' => $pinned]);
    }

    private function forward(User $user, Conversation $target, int $id, ?string $clientId = null): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/messenger/conversations/{$target->id}/forward", array_filter([
            'message_id' => $id, 'client_id' => $clientId,
        ]));
    }

    /** @return array<string, mixed> */
    private function row(User $user, Conversation $conversation, int $id): array
    {
        return collect($this->as($user)->getJson("/api/v1/messenger/conversations/{$conversation->id}/messages")->json('messages'))->firstWhere('id', $id);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
