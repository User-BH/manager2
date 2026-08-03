<?php

namespace Tests\Feature;

use App\Enums\MessageAudience;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Message;
use App\Models\MessagePoll;
use App\Models\MessageRead;
use App\Models\PollVote;
use App\Models\Unit;
use App\Models\User;
use App\Support\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * پیوست، رسیدِ خواندن، شمارنده و نظرسنجی (R23b).
 *
 * همه‌ی این‌ها روی همان دامنه‌ی دیدِ R23a سوارند، پس آزمون‌ها بیش از «کار
 * می‌کند» این را می‌سنجند که **از مرزِ مخاطب بیرون نمی‌زنند**: پیوستِ گفت‌وگوی
 * واحدِ دیگری نباید دانلود شود و رأیِ نظرسنجیِ نادیدنی نباید ثبت شود.
 */
class MessengerFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->complex = Complex::factory()->create(['messenger_enabled' => true]);
        $this->manager = $this->userIn(UserRole::ComplexAdmin);
    }

    private function userIn(UserRole $role): User
    {
        // `can_message` صریح داده می‌شود: نمونه‌ی حافظه‌ای مقدارِ پیش‌فرضِ
        // دیتابیس را نمی‌خواند و null می‌ماند (درسِ R23a).
        return User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => $role,
            'is_active' => true,
            'can_message' => true,
        ]);
    }

    private function resident(): array
    {
        $unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $user = $this->userIn(UserRole::Owner);
        $unit->residents()->attach($user->id, [
            'relation' => 'owner',
            'complex_id' => $this->complex->id,
        ]);

        return [$user, $unit];
    }

    // ── پیوست ──────────────────────────────────────────────────────────────

    public function test_a_manager_can_attach_an_image_to_a_message(): void
    {
        $response = $this->actingAs($this->manager)->post('/api/v1/messenger', [
            'body' => 'نقشه‌ی پارکینگ',
            'audience' => MessageAudience::All->value,
            'attachment' => UploadedFile::fake()->image('plan.jpg'),
        ]);

        $response->assertCreated();

        $message = Message::firstOrFail();
        $this->assertTrue($message->hasAttachment());
        $this->assertSame('image', $message->attachment_kind);
        Storage::disk('local')->assertExists($message->attachment_path);
    }

    public function test_an_attachment_alone_is_enough_and_needs_no_text(): void
    {
        $this->actingAs($this->manager)->post('/api/v1/messenger', [
            'audience' => MessageAudience::All->value,
            'attachment' => UploadedFile::fake()->create('rules.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame('file', Message::firstOrFail()->attachment_kind);
    }

    public function test_an_empty_message_with_no_attachment_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/v1/messenger', ['audience' => MessageAudience::All->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_an_executable_disguised_as_an_attachment_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/v1/messenger', [
                'body' => 'سلام',
                'audience' => MessageAudience::All->value,
                'attachment' => UploadedFile::fake()->create('payload.php', 4, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        // فایلِ ردشده نباید روی دیسک بماند
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_resident_cannot_download_an_attachment_addressed_to_another_unit(): void
    {
        [$alice] = $this->resident();
        [$bob, $bobUnit] = $this->resident();

        $this->actingAs($this->manager)->post('/api/v1/messenger', [
            'body' => 'قبض واحد شما',
            'audience' => MessageAudience::Units->value,
            'unit_ids' => [$bobUnit->id],
            'attachment' => UploadedFile::fake()->image('bill.png'),
        ])->assertCreated();

        $message = Message::firstOrFail();

        $this->actingAs($bob)->get("/api/v1/messenger/{$message->id}/attachment")->assertOk();
        $this->actingAs($alice)->get("/api/v1/messenger/{$message->id}/attachment")->assertNotFound();
    }

    // ── رسیدِ خواندن ────────────────────────────────────────────────────────

    public function test_marking_a_message_read_is_idempotent(): void
    {
        [$resident] = $this->resident();
        $message = $this->managerMessageToAll();

        foreach ([1, 2] as $_) {
            $this->actingAs($resident)
                ->postJson('/api/v1/messenger/read', ['ids' => [$message->id]])
                ->assertOk()
                ->assertJson(['marked' => 1]);
        }

        $this->assertSame(1, MessageRead::where('message_id', $message->id)->count());
    }

    public function test_a_resident_cannot_mark_a_message_they_cannot_see(): void
    {
        [$alice] = $this->resident();
        [, $bobUnit] = $this->resident();

        $this->actingAs($this->manager)->post('/api/v1/messenger', [
            'body' => 'فقط برای واحد باب',
            'audience' => MessageAudience::Units->value,
            'unit_ids' => [$bobUnit->id],
        ])->assertCreated();

        $this->actingAs($alice)
            ->postJson('/api/v1/messenger/read', ['ids' => [Message::firstOrFail()->id]])
            ->assertOk()
            ->assertJson(['marked' => 0]);

        $this->assertSame(0, MessageRead::count());
    }

    public function test_the_read_count_is_shown_to_the_manager_but_not_to_residents(): void
    {
        [$resident] = $this->resident();
        $message = $this->managerMessageToAll();

        $this->actingAs($resident)->postJson('/api/v1/messenger/read', ['ids' => [$message->id]]);

        $this->assertSame(1, $this->actingAs($this->manager)->getJson('/api/v1/messenger')
            ->json('messages.0.readCount'));

        $this->assertNull($this->actingAs($resident)->getJson('/api/v1/messenger')
            ->json('messages.0.readCount'));
    }

    // ── شمارنده‌ی نخوانده ──────────────────────────────────────────────────

    public function test_the_unread_counter_drops_after_reading_and_ignores_ones_own_messages(): void
    {
        [$resident] = $this->resident();
        $message = $this->managerMessageToAll();

        $this->actingAs($resident)->post('/api/v1/messenger', ['body' => 'پیامِ خودم']);

        // پیامِ خودِ کاربر نخوانده حساب نمی‌شود، پس عدد ۱ است نه ۲
        $this->assertSame(1, Notifications::messengerUnread($resident->fresh()));

        $this->actingAs($resident)->postJson('/api/v1/messenger/read', ['ids' => [$message->id]]);

        $this->assertSame(0, Notifications::messengerUnread($resident->fresh()));
    }

    public function test_a_message_for_another_unit_does_not_raise_the_counter(): void
    {
        [$alice] = $this->resident();
        [, $bobUnit] = $this->resident();

        $this->actingAs($this->manager)->post('/api/v1/messenger', [
            'body' => 'فقط باب',
            'audience' => MessageAudience::Units->value,
            'unit_ids' => [$bobUnit->id],
        ]);

        $this->assertSame(0, Notifications::messengerUnread($alice->fresh()));
    }

    // ── نظرسنجی ────────────────────────────────────────────────────────────

    public function test_a_manager_can_publish_a_poll(): void
    {
        $this->postPoll()->assertCreated();

        $poll = MessagePoll::firstOrFail();
        $this->assertSame('رنگ نمای ساختمان؟', $poll->question);
        $this->assertSame(['آبی', 'خاکستری'], $poll->options()->pluck('label')->all());
    }

    public function test_a_resident_cannot_publish_a_poll(): void
    {
        [$resident] = $this->resident();

        $this->actingAs($resident)->postJson('/api/v1/messenger', [
            'body' => 'نظرسنجی من',
            'poll_question' => 'ساعت جلسه؟',
            'poll_options' => ['۸', '۹'],
        ])->assertCreated();

        // پیام ثبت می‌شود ولی نظرسنجی نه — تصمیمِ ساختمان با مدیر است
        $this->assertSame(0, MessagePoll::count());
    }

    public function test_a_poll_question_without_options_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/v1/messenger', ['body' => 'x', 'poll_question' => 'چه رنگی؟'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('poll_options');
    }

    public function test_changing_a_vote_replaces_it_instead_of_adding_one(): void
    {
        [$resident] = $this->resident();
        $this->postPoll();
        $poll = MessagePoll::firstOrFail();
        $options = $poll->options()->pluck('id')->all();

        foreach ($options as $optionId) {
            $this->actingAs($resident)
                ->postJson("/api/v1/messenger/polls/{$poll->id}/vote", ['option_id' => $optionId])
                ->assertOk();
        }

        $this->assertSame(1, PollVote::count());
        $this->assertSame($options[1], PollVote::firstOrFail()->poll_option_id);
    }

    public function test_an_option_from_another_poll_is_rejected(): void
    {
        [$resident] = $this->resident();
        $this->postPoll();
        $this->postPoll();

        [$first, $second] = MessagePoll::orderBy('id')->get()->all();

        $this->actingAs($resident)
            ->postJson("/api/v1/messenger/polls/{$first->id}/vote", [
                'option_id' => $second->options()->firstOrFail()->id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, PollVote::count());
    }

    public function test_a_closed_poll_rejects_votes(): void
    {
        [$resident] = $this->resident();
        $this->postPoll();

        $poll = MessagePoll::firstOrFail();
        $poll->update(['closes_at' => now()->subMinute()]);

        $this->actingAs($resident)
            ->postJson("/api/v1/messenger/polls/{$poll->id}/vote", [
                'option_id' => $poll->options()->firstOrFail()->id,
            ])
            ->assertStatus(422);
    }

    public function test_a_resident_cannot_vote_in_a_poll_addressed_to_another_unit(): void
    {
        [$alice] = $this->resident();
        [, $bobUnit] = $this->resident();

        $this->actingAs($this->manager)->post('/api/v1/messenger', [
            'body' => 'نظرسنجی واحد باب',
            'audience' => MessageAudience::Units->value,
            'unit_ids' => [$bobUnit->id],
            'poll_question' => 'موافقید؟',
            'poll_options' => ['بله', 'خیر'],
        ])->assertCreated();

        $poll = MessagePoll::firstOrFail();

        $this->actingAs($alice)
            ->postJson("/api/v1/messenger/polls/{$poll->id}/vote", [
                'option_id' => $poll->options()->firstOrFail()->id,
            ])
            ->assertNotFound();
    }

    public function test_the_results_are_visible_to_everyone_who_sees_the_poll(): void
    {
        [$resident] = $this->resident();
        $this->postPoll();
        $poll = MessagePoll::firstOrFail();
        $chosen = $poll->options()->firstOrFail();

        $this->actingAs($resident)->postJson("/api/v1/messenger/polls/{$poll->id}/vote", [
            'option_id' => $chosen->id,
        ]);

        $payload = $this->actingAs($resident)->getJson('/api/v1/messenger')->json('messages.0.poll');

        $this->assertSame(1, $payload['totalVotes']);
        $this->assertSame($chosen->id, $payload['myOptionId']);
        $this->assertSame(1, $payload['options'][0]['votes']);

        // مدیری که رأی نداده، نتیجه را می‌بیند ولی رأیِ خودش خالی است
        $managerView = $this->actingAs($this->manager)->getJson('/api/v1/messenger')->json('messages.0.poll');
        $this->assertSame(1, $managerView['totalVotes']);
        $this->assertNull($managerView['myOptionId']);
    }

    // ── کمکی ───────────────────────────────────────────────────────────────

    private function managerMessageToAll(): Message
    {
        $this->actingAs($this->manager)->post('/api/v1/messenger', [
            'body' => 'اطلاعیه‌ی عمومی',
            'audience' => MessageAudience::All->value,
        ])->assertCreated();

        return Message::latest('id')->firstOrFail();
    }

    private function postPoll(): TestResponse
    {
        return $this->actingAs($this->manager)->postJson('/api/v1/messenger', [
            'body' => 'لطفاً رأی بدهید',
            'audience' => MessageAudience::All->value,
            'poll_question' => 'رنگ نمای ساختمان؟',
            'poll_options' => ['آبی', 'خاکستری'],
        ]);
    }
}
