<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\MessagePoll;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Poll\PollService;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * قاعده‌های نظرسنجی — مرحلهٔ ۱۰ · فاز ۱۱ (بندهای ۶۱، ۶۴، ۶۵، ۶۶، ۶۹ و ۱۱۹ ِ فایلِ معماری).
 *
 * فهرستِ بندِ ۱۱۹ یک‌به‌یک: مدیر می‌سازد (فقط در گروه)، عضو می‌بیند، مالک و مستأجرِ مجاز رأی می‌دهند، غیرمجاز رد
 * می‌شود ولی نظرسنجی را با دلیلش می‌بیند، تکرار و گزینه‌ی نامعتبر و شناسه‌ی مجتمعِ دیگر، پس از پایانِ عضویت، و سقفِ
 * چندگزینه‌ای.
 */
class PollRulesTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private User $owner;

    private User $tenant;

    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);
        $this->manager = $this->person($this->complex, UserRole::ComplexAdmin);
        $unit = Unit::factory()->create(['complex_id' => $this->complex->id, 'is_active' => true]);
        $this->owner = $this->person($this->complex, UserRole::Owner);
        $this->tenant = $this->person($this->complex, UserRole::Tenant);
        app(TenureService::class)->open($unit, $this->owner, ResidentRelation::Owner, 100, now()->subYear());
        app(TenureService::class)->open($unit, $this->tenant, ResidentRelation::Tenant, 100, now()->subMonth());

        app(TenantContext::class)->set(null);
        $this->group = app(ConversationDirectory::class)->channel($this->complex);
    }

    /** بندِ ۶۱: فقط در گروه — گروهِ مجتمع و گروهِ مدیر؛ گفت‌وگوی خصوصی نه، و نه بی‌صدا به پیامِ ساده. */
    public function test_polls_live_only_in_groups(): void
    {
        $this->create($this->group, ['poll_options' => ['بله', 'نه']])->assertCreated()->assertJsonPath('message.poll.question', 'رنگِ لابی؟');

        app(TenantContext::class)->set(null);
        $board = app(ConversationDirectory::class)->createGroup($this->complex, 'طبقه‌ها', [Unit::query()->withoutGlobalScopes()->value('id')], true, $this->manager);
        $this->create($board, ['poll_options' => ['بله', 'نه']])->assertCreated();

        app(TenantContext::class)->set(null);
        $chat = app(ConversationDirectory::class)->userThread($this->complex, $this->owner);
        $before = DB::table('messages')->count();
        $this->create($chat, ['poll_options' => ['بله', 'نه']])
            ->assertUnprocessable()->assertJsonPath('code', 'messenger.poll_only_in_groups');
        $this->assertSame($before, DB::table('messages')->count(), 'پیامِ ساده هم ساخته نشد.');
    }

    /** بندهای ۶۵ و ۶۶: «فقط مستأجران» — مستأجر رأی می‌دهد؛ مالک نظرسنجی را با دلیلش می‌بیند و سرور هم ردش می‌کند. */
    public function test_eligibility_is_separate_from_visibility_and_enforced_by_the_server(): void
    {
        $poll = $this->pollFor(['poll_voter_scope' => 'tenants']);

        $seen = $this->pollSeenBy($this->owner, $poll);
        $this->assertSame('فقط مستأجران', $seen['voterScopeLabel']);
        $this->assertSame('این نظرسنجی فقط برای مستأجران است.', $seen['blockReason']);
        $this->vote($this->owner, $poll, [$this->optionsOf($poll)[0]])->assertUnprocessable()->assertJsonPath('code', 'poll.not_eligible');

        $this->assertNull($this->pollSeenBy($this->tenant, $poll)['blockReason']);
        $this->vote($this->tenant, $poll, [$this->optionsOf($poll)[1]])->assertOk()->assertJsonPath('poll.totalVotes', 1);

        // «فقط مالکان» برعکسش
        $owners = $this->pollFor(['poll_voter_scope' => 'owners']);
        $this->vote($this->owner, $owners, [$this->optionsOf($owners)[0]])->assertOk();
        $this->vote($this->tenant, $owners, [$this->optionsOf($owners)[0]])->assertUnprocessable();
    }

    /** بندِ ۶۴: چندگزینه‌ای با سقف؛ رأیِ دوباره جایگزین است؛ رأی‌دهنده یک بار شمرده می‌شود. */
    public function test_multiple_choice_respects_its_cap(): void
    {
        $poll = $this->pollFor(['poll_options' => ['الف', 'ب', 'پ', 'ت'], 'poll_max_selections' => 2]);
        [$a, $b, $c] = $this->optionsOf($poll);

        $this->vote($this->owner, $poll, [$a, $b, $c])->assertUnprocessable()->assertJsonPath('code', 'poll.too_many_options');
        $this->vote($this->owner, $poll, [$a, $a])->assertUnprocessable()->assertJsonPath('code', 'poll.invalid_option');

        $result = $this->vote($this->owner, $poll, [$a, $b])->assertOk()->json('poll');
        $this->assertSame(1, $result['totalVotes']);
        $this->assertSame(2, $result['maxSelections']);
        $this->assertEqualsCanonicalizing([$a, $b], $result['myOptionIds']);

        // تغییرِ رأی جایگزین می‌کند، نه افزوده
        $result = $this->vote($this->owner, $poll, [$c])->assertOk()->json('poll');
        $this->assertSame([$c], $result['myOptionIds']);
        $this->assertSame(1, DB::table('poll_votes')->where('user_id', $this->owner->id)->count());

        // تک‌گزینه‌ای همان یکی
        $single = $this->pollFor();
        $this->vote($this->owner, $single, $this->optionsOf($single))->assertUnprocessable()->assertJsonPath('code', 'poll.too_many_options');

        // سقفِ بیشتر از شمارِ گزینه‌ها ساخته نمی‌شود
        $this->create($this->group, ['poll_options' => ['الف', 'ب'], 'poll_max_selections' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('poll_max_selections');
    }

    /** بندِ ۱۱۹: گزینه‌ی نظرسنجیِ دیگر و شناسه‌ی نظرسنجیِ مجتمعِ دیگر. */
    public function test_options_and_polls_from_elsewhere_are_refused(): void
    {
        $poll = $this->pollFor();
        $other = $this->pollFor();
        $this->vote($this->owner, $poll, [$this->optionsOf($other)[0]])->assertUnprocessable()->assertJsonPath('code', 'poll.invalid_option');

        $elsewhere = Complex::factory()->create(['messenger_enabled' => true]);
        $stranger = $this->person($elsewhere, UserRole::Owner);
        app(TenureService::class)->open(Unit::factory()->create(['complex_id' => $elsewhere->id, 'is_active' => true]), $stranger, ResidentRelation::Owner, 100, now()->subYear());
        $this->vote($stranger, $poll, [$this->optionsOf($poll)[0]])->assertNotFound();
        $this->assertSame(0, DB::table('poll_votes')->count());
    }

    /** بندِ ۶۹: پس از پایانِ عضویت رأیِ تازه نه؛ رأیِ قبلی سرِ جایش. */
    public function test_no_new_vote_after_membership_ends_but_history_stays(): void
    {
        $poll = $this->pollFor(['poll_options' => ['الف', 'ب']]);
        [$a, $b] = $this->optionsOf($poll);
        $this->vote($this->tenant, $poll, [$a])->assertOk();

        app(Membership::class)->end($this->tenant, $this->manager, $this->complex->id);

        // حسابی که دیگر عضوِ فعالِ هیچ مجتمعی نیست زمینه ندارد: ۴۰۳ ِ «مجتمعی انتخاب نشده»، پیش از رسیدن به نظرسنجی
        $this->vote($this->tenant->fresh(), $poll, [$b])->assertForbidden();
        $this->assertSame([$a], DB::table('poll_votes')->where('user_id', $this->tenant->id)->pluck('poll_option_id')->map(fn ($id) => (int) $id)->all());

        // خودِ سرویس هم — نه فقط لایه‌ی زمینه — عضوِ رفته را رد می‌کند
        app(TenantContext::class)->set(null);
        $model = MessagePoll::query()->withoutGlobalScopes()->findOrFail($poll);
        $this->assertSame('عضویتِ شما در این مجتمع فعال نیست.', app(PollService::class)->blockReason($model, $this->tenant->fresh()));
    }

    /* ── کمک‌ها ─────────────────────────────────────────────────────────── */

    /** @param  array<string, mixed>  $extra */
    private function create(Conversation $conversation, array $extra = []): TestResponse
    {
        return $this->as($this->manager)->postJson("/api/v1/messenger/conversations/{$conversation->id}/messages", [
            'poll_question' => 'رنگِ لابی؟', 'poll_options' => ['سفید', 'کرم', 'طوسی'], ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function pollFor(array $extra = []): int
    {
        return (int) $this->create($this->group, $extra)->assertCreated()->json('message.poll.id');
    }

    /** @return list<int> */
    private function optionsOf(int $poll): array
    {
        return DB::table('poll_options')->where('message_poll_id', $poll)->orderBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    private function pollSeenBy(User $user, int $poll): array
    {
        $messages = $this->as($user)->getJson("/api/v1/messenger/conversations/{$this->group->id}/messages")->assertOk()->json('messages');

        return collect($messages)->firstWhere('poll.id', $poll)['poll'];
    }

    /** @param  list<int>  $options */
    private function vote(User $user, int $poll, array $options): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/messenger/polls/{$poll}/vote", ['option_ids' => $options]);
    }

    private function person(Complex $complex, UserRole $role): User
    {
        return User::factory()->create(['complex_id' => $complex->id, 'role' => $role, 'is_active' => true]);
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
