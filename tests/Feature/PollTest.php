<?php

namespace Tests\Feature;

use App\Enums\MessageAudience;
use App\Enums\PollVoterScope;
use App\Enums\PollWeightMode;
use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\MessagePoll;
use App\Models\PollVote;
use App\Models\Unit;
use App\Models\User;
use App\Services\Poll\PollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * نظرسنجیِ حرفه‌ای (R24).
 *
 * نظرسنجیِ R23b یک نظرخواهیِ ساده بود. اینجا سه چیزی سنجیده می‌شود که یک
 * تصمیمِ واقعیِ ساختمان بدونشان قابلِ دفاع نیست:
 *
 *   ۱. **چه کسی رأی می‌دهد** — واحد یا نفر؛ مالک یا همه‌ی ساکنین.
 *   ۲. **رأیِ دوباره** — نه فقط از همان کاربر، بلکه از همان واحد.
 *   ۳. **نتیجه** — مشارکت، حد نصاب، وزن، و اینکه برنده کِی اعلام می‌شود.
 */
class PollTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    private PollService $polls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);
        $this->manager = $this->makeUser(UserRole::ComplexAdmin);
        $this->polls = app(PollService::class);
    }

    private function makeUser(UserRole $role): User
    {
        return User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => $role,
            'is_active' => true,
            'can_message' => true,
        ]);
    }

    private function unit(float $area = 100): Unit
    {
        return Unit::factory()->create([
            'complex_id' => $this->complex->id,
            'area' => $area,
            'is_active' => true,
        ]);
    }

    private function resident(Unit $unit, ResidentRelation $relation = ResidentRelation::Owner): User
    {
        $user = $this->makeUser(
            $relation === ResidentRelation::Owner ? UserRole::Owner : UserRole::Tenant,
        );

        $unit->residents()->attach($user->id, [
            'relation' => $relation->value,
            'complex_id' => $this->complex->id,
            'is_current' => true,
        ]);

        return $user;
    }

    /** @param  array<string, mixed>  $settings */
    private function createPoll(array $settings = [], array $unitIds = []): MessagePoll
    {
        $this->actingAs($this->manager)->postJson('/api/v1/messenger', array_merge([
            'body' => 'لطفاً رأی بدهید',
            'audience' => $unitIds === [] ? MessageAudience::All->value : MessageAudience::Units->value,
            'unit_ids' => $unitIds,
            'poll_question' => 'تعویض آسانسور؟',
            'poll_options' => ['بله', 'خیر'],
        ], $settings))->assertCreated();

        return MessagePoll::latest('id')->firstOrFail();
    }

    private function vote(User $user, MessagePoll $poll, string $label): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/messenger/polls/{$poll->id}/vote", [
            'option_id' => $poll->options()->where('label', $label)->firstOrFail()->id,
        ]);
    }

    // ── محدودکردن شرکت‌کنندگان ─────────────────────────────────────────────

    public function test_a_tenant_cannot_vote_in_an_owners_only_poll(): void
    {
        $unit = $this->unit();
        $owner = $this->resident($unit);
        $tenant = $this->resident($unit, ResidentRelation::Tenant);

        $poll = $this->createPoll(['poll_voter_scope' => PollVoterScope::Owners->value]);

        $this->vote($tenant, $poll, 'بله')->assertStatus(422);
        $this->vote($owner, $poll, 'بله')->assertOk();

        $this->assertSame(1, PollVote::count());
    }

    public function test_the_organiser_does_not_vote_in_their_own_poll(): void
    {
        $poll = $this->createPoll();

        $this->vote($this->manager, $poll, 'بله')->assertStatus(422);
        $this->assertSame(0, PollVote::count());
    }

    public function test_a_resident_with_no_unit_cannot_vote(): void
    {
        $stranger = $this->makeUser(UserRole::Owner);
        $poll = $this->createPoll();

        $this->vote($stranger, $poll, 'بله')->assertStatus(422);
    }

    // ── جلوگیری از رأی دوباره ──────────────────────────────────────────────

    public function test_a_unit_bound_poll_takes_only_one_vote_per_unit(): void
    {
        $unit = $this->unit();
        $owner = $this->resident($unit);
        $tenant = $this->resident($unit, ResidentRelation::Tenant);

        $poll = $this->createPoll(['poll_weight_mode' => PollWeightMode::PerUnit->value]);

        $this->vote($owner, $poll, 'بله')->assertOk();

        // ساکنِ دومِ همان واحد رأیِ تازه‌ای ندارد — واحد یک رأی دارد
        $this->vote($tenant, $poll, 'خیر')->assertStatus(422);

        $this->assertSame(1, PollVote::count());
        $this->assertSame($owner->id, PollVote::firstOrFail()->user_id);
    }

    public function test_a_per_person_poll_still_takes_a_vote_from_each_resident(): void
    {
        $unit = $this->unit();
        $owner = $this->resident($unit);
        $tenant = $this->resident($unit, ResidentRelation::Tenant);

        $poll = $this->createPoll();

        $this->vote($owner, $poll, 'بله')->assertOk();
        $this->vote($tenant, $poll, 'خیر')->assertOk();

        $this->assertSame(2, PollVote::count());
    }

    public function test_a_locked_poll_refuses_to_change_a_vote(): void
    {
        $voter = $this->resident($this->unit());
        $poll = $this->createPoll(['poll_allow_change' => false]);

        $this->vote($voter, $poll, 'بله')->assertOk();
        $this->vote($voter, $poll, 'خیر')->assertStatus(422);

        $this->assertSame(
            $poll->options()->where('label', 'بله')->firstOrFail()->id,
            PollVote::firstOrFail()->poll_option_id,
        );
    }

    public function test_the_voter_can_change_their_own_unit_vote(): void
    {
        $owner = $this->resident($this->unit());
        $poll = $this->createPoll(['poll_weight_mode' => PollWeightMode::PerUnit->value]);

        $this->vote($owner, $poll, 'بله')->assertOk();
        $this->vote($owner, $poll, 'خیر')->assertOk();

        $this->assertSame(1, PollVote::count());
        $this->assertSame(
            $poll->options()->where('label', 'خیر')->firstOrFail()->id,
            PollVote::firstOrFail()->poll_option_id,
        );
    }

    // ── وزن ────────────────────────────────────────────────────────────────

    public function test_an_area_weighted_poll_counts_square_metres_not_heads(): void
    {
        $small = $this->resident($this->unit(60));
        $large = $this->resident($this->unit(200));

        $poll = $this->createPoll(['poll_weight_mode' => PollWeightMode::ByArea->value]);

        $this->vote($small, $poll, 'بله');
        $this->vote($large, $poll, 'خیر');

        $result = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);

        $this->assertSame(60.0, $result['options'][0]['weight']);
        $this->assertSame(200.0, $result['options'][1]['weight']);

        // ۶۰ از ۲۶۰ ≈ ۲۳٪ — با شمارشِ نفری ۵۰٪ می‌شد
        $this->assertSame(23, $result['options'][0]['share']);
        $this->assertSame(100, $result['turnoutPercent']);
    }

    public function test_editing_a_units_area_after_the_vote_does_not_move_the_result(): void
    {
        $unit = $this->unit(80);
        $voter = $this->resident($unit);

        $poll = $this->createPoll(['poll_weight_mode' => PollWeightMode::ByArea->value]);
        $this->vote($voter, $poll, 'بله');

        $unit->update(['area' => 500]);

        // وزنِ ثبت‌شده عکسِ لحظه‌ی رأی است، نه خواندنِ دوباره از پرونده‌ی واحد
        $this->assertSame(80.0, (float) PollVote::firstOrFail()->weight);
    }

    public function test_an_area_weighted_poll_reports_when_no_area_is_recorded(): void
    {
        $this->resident($this->unit(0));
        $poll = $this->createPoll(['poll_weight_mode' => PollWeightMode::ByArea->value]);

        $result = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);

        $this->assertTrue($result['weightUnavailable']);
    }

    // ── آمار ───────────────────────────────────────────────────────────────

    public function test_turnout_is_measured_against_the_addressed_units_only(): void
    {
        $addressed = $this->unit();
        $voter = $this->resident($addressed);
        $this->resident($this->unit());   // واحدی که نظرسنجی برایش نرفته

        $poll = $this->createPoll(
            ['poll_weight_mode' => PollWeightMode::PerUnit->value],
            [$addressed->id],
        );

        $this->vote($voter, $poll, 'بله');

        $result = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);

        // مخرج ۱ است نه ۲ — وگرنه مشارکت الکی نصف نشان داده می‌شد
        $this->assertSame(1.0, $result['eligibleWeight']);
        $this->assertSame(100, $result['turnoutPercent']);
    }

    public function test_a_poll_below_its_quorum_is_reported_as_not_met(): void
    {
        $voter = $this->resident($this->unit());
        $this->resident($this->unit());
        $this->resident($this->unit());
        $this->resident($this->unit());

        $poll = $this->createPoll([
            'poll_weight_mode' => PollWeightMode::PerUnit->value,
            'poll_quorum_percent' => 50,
        ]);

        $this->vote($voter, $poll, 'بله');

        $result = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);

        $this->assertSame(25, $result['turnoutPercent']);
        $this->assertFalse($result['quorumMet']);
    }

    /**
     * ⚠️ رگرسیونِ واقعی: این کوئری در تولید با
     * «Unknown column 'pivot'» می‌ترکید.
     *
     * علتش این بود که `wherePivot()` فقط روی خودِ رابطه‌ی BelongsToMany
     * معنا دارد؛ داخلِ `whereHas()` سازنده‌ای که به کلوژر می‌رسد Builderِ
     * مدلِ مقصد است، پس `wherePivot` از راهِ `__call` به
     * `where('pivot', 'is_current')` تبدیل می‌شد.
     *
     * تستِ قبلی این را نگرفت چون جامعه‌ی آماری‌اش خالی بود و تابع پیش از
     * ساختنِ کوئری برمی‌گشت. اینجا عمداً ساکنِ واقعی هست.
     */
    public function test_a_per_person_poll_counts_real_residents(): void
    {
        $this->resident($this->unit());
        $this->resident($this->unit());

        $poll = $this->createPoll();
        $result = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);

        $this->assertSame(2.0, $result['eligibleWeight']);
    }

    public function test_an_owners_only_poll_excludes_tenants_from_the_denominator(): void
    {
        $unit = $this->unit();
        $this->resident($unit);
        $this->resident($unit, ResidentRelation::Tenant);

        $poll = $this->createPoll(['poll_voter_scope' => PollVoterScope::Owners->value]);
        $result = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);

        // مستاجر نه رأی می‌دهد و نه در مخرجِ مشارکت می‌آید
        $this->assertSame(1.0, $result['eligibleWeight']);
    }

    public function test_a_poll_with_no_quorum_is_always_reported_as_met(): void
    {
        $poll = $this->createPoll();

        $result = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);

        $this->assertNull($result['quorumPercent']);
        $this->assertTrue($result['quorumMet']);
    }

    public function test_the_winner_is_announced_only_after_the_poll_closes(): void
    {
        $voter = $this->resident($this->unit());
        $poll = $this->createPoll();
        $this->vote($voter, $poll, 'بله');

        $open = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);
        $this->assertNull($open['leaderId']);

        $poll->closeNow();

        $closed = $this->polls->results($poll->fresh(['options', 'votes']), $this->manager);
        $this->assertSame($poll->options()->where('label', 'بله')->firstOrFail()->id, $closed['leaderId']);
        $this->assertFalse($closed['isTie']);
    }

    public function test_an_equal_split_is_reported_as_a_tie(): void
    {
        $poll = $this->createPoll();
        $this->vote($this->resident($this->unit()), $poll, 'بله');
        $this->vote($this->resident($this->unit()), $poll, 'خیر');

        $poll->closeNow();

        $this->assertTrue($this->polls->results($poll->fresh(['options', 'votes']), $this->manager)['isTie']);
    }

    // ── بستن ───────────────────────────────────────────────────────────────

    public function test_a_manager_can_close_a_poll_and_it_stops_taking_votes(): void
    {
        $voter = $this->resident($this->unit());
        $poll = $this->createPoll();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/messenger/polls/{$poll->id}/close")
            ->assertOk();

        $this->vote($voter, $poll, 'بله')->assertStatus(422);
        $this->assertSame(0, PollVote::count());
    }

    public function test_a_resident_cannot_close_a_poll(): void
    {
        $voter = $this->resident($this->unit());
        $poll = $this->createPoll();

        $this->actingAs($voter)
            ->postJson("/api/v1/messenger/polls/{$poll->id}/close")
            ->assertStatus(403);

        $this->assertFalse($poll->fresh()->isClosed());
    }

    public function test_a_manager_of_another_complex_cannot_close_the_poll(): void
    {
        $poll = $this->createPoll();

        $outsider = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        $this->actingAs($outsider)
            ->postJson("/api/v1/messenger/polls/{$poll->id}/close")
            ->assertNotFound();

        $this->assertFalse($poll->fresh()->isClosed());
    }

    // ── سازگاری با R23b ────────────────────────────────────────────────────

    public function test_a_poll_created_without_settings_keeps_the_simple_behaviour(): void
    {
        $poll = $this->createPoll();

        $this->assertSame(PollVoterScope::Residents, $poll->voter_scope);
        $this->assertSame(PollWeightMode::PerPerson, $poll->weight_mode);
        $this->assertTrue($poll->allow_change);
        $this->assertNull($poll->quorum_percent);
    }

    public function test_a_deadline_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/messenger', [
            'body' => 'x',
            'audience' => MessageAudience::All->value,
            'poll_question' => 'دیروز؟',
            'poll_options' => ['بله', 'خیر'],
            'poll_closes_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('poll_closes_at');
    }

    public function test_a_poll_past_its_deadline_stops_taking_votes(): void
    {
        $voter = $this->resident($this->unit());

        $poll = $this->createPoll(['poll_closes_at' => now()->addHour()->toIso8601String()]);
        $this->vote($voter, $poll, 'بله')->assertOk();

        $this->travel(2)->hours();

        $this->vote($voter, $poll, 'خیر')->assertStatus(422);
    }

    public function test_the_message_payload_carries_the_full_result(): void
    {
        $voter = $this->resident($this->unit());
        $poll = $this->createPoll(['poll_weight_mode' => PollWeightMode::PerUnit->value]);
        $this->vote($voter, $poll, 'بله');

        $payload = $this->actingAs($voter)->getJson('/api/v1/messenger')->json('messages.0.poll');

        $this->assertSame('per_unit', $payload['weightMode']);
        $this->assertSame(100, $payload['turnoutPercent']);
        $this->assertNull($payload['blockReason']);
        $this->assertSame($poll->options()->where('label', 'بله')->firstOrFail()->id, $payload['myOptionId']);
    }
}
