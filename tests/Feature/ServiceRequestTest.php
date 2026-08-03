<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\ServiceRequestCategory;
use App\Enums\ServiceRequestPriority;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestComment;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\ServiceRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * درخواست‌های ساکنین و واگذاری به مسئول (R25).
 *
 * سه چیز سنجیده می‌شود که یک سامانه‌ی درخواست بدونشان اسباب‌بازی است:
 *
 *   ۱. **دامنه‌ی دید** — همسایه نباید پرونده‌ی واحدِ دیگری را ببیند، ولی
 *      مسئولِ واگذارشده باید ببیند.
 *   ۲. **چرخه‌ی وضعیت** — «انجام شد» پایانِ کار نیست؛ تاییدِ ساکن است.
 *   ۳. **پاسخگویی** — یادداشتِ داخلی نباید به ساکن برسد و درخواست پاک نشود.
 */
class ServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->complex = Complex::factory()->create();
        $this->manager = $this->makeUser(UserRole::ComplexAdmin);
    }

    private function makeUser(UserRole $role): User
    {
        return User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @return array{0: User, 1: Unit} */
    private function resident(): array
    {
        $unit = Unit::factory()->create(['complex_id' => $this->complex->id]);
        $user = $this->makeUser(UserRole::Owner);

        $unit->residents()->attach($user->id, [
            'relation' => ResidentRelation::Owner->value,
            'complex_id' => $this->complex->id,
            'is_current' => true,
        ]);

        return [$user, $unit];
    }

    private function submit(User $user, array $overrides = []): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/service-requests', array_merge([
            'title' => 'آسانسور کار نمی‌کند',
            'description' => 'از دیروز بین طبقه ۳ و ۴ گیر می‌کند.',
            'category' => ServiceRequestCategory::Elevator->value,
        ], $overrides));
    }

    private function move(User $user, ServiceRequest $request, ServiceRequestStatus $to, ?string $note = null): TestResponse
    {
        return $this->actingAs($user)->patchJson(
            "/api/v1/service-requests/{$request->id}/status",
            array_filter(['status' => $to->value, 'note' => $note]),
        );
    }

    // ── ثبت ────────────────────────────────────────────────────────────────

    public function test_a_resident_can_submit_a_request_for_their_own_unit(): void
    {
        [$resident, $unit] = $this->resident();

        $this->submit($resident)->assertCreated();

        $request = ServiceRequest::firstOrFail();
        $this->assertSame($unit->id, $request->unit_id);
        $this->assertSame(ServiceRequestStatus::New, $request->status);
        $this->assertSame(ServiceRequestPriority::Normal, $request->priority);
    }

    public function test_a_resident_cannot_file_a_request_against_a_neighbours_unit(): void
    {
        [$alice] = $this->resident();
        [, $bobUnit] = $this->resident();

        $this->submit($alice, ['unit_id' => $bobUnit->id])->assertCreated();

        // واحدِ فرستاده‌شده نادیده گرفته می‌شود و واحدِ خودش می‌نشیند
        $this->assertNotSame($bobUnit->id, ServiceRequest::firstOrFail()->unit_id);
    }

    public function test_a_resident_cannot_mark_their_own_request_critical(): void
    {
        [$resident] = $this->resident();

        $this->submit($resident, ['priority' => ServiceRequestPriority::Critical->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('priority');
    }

    public function test_an_attachment_is_stored_privately_and_served_through_the_controller(): void
    {
        [$resident] = $this->resident();

        $this->actingAs($resident)->post('/api/v1/service-requests', [
            'title' => 'ترکیدگی لوله',
            'description' => 'زیر سینک آب می‌آید.',
            'category' => ServiceRequestCategory::Facilities->value,
            'attachment' => UploadedFile::fake()->image('leak.jpg'),
        ])->assertCreated();

        $request = ServiceRequest::firstOrFail();
        Storage::disk('local')->assertExists($request->attachment_path);

        $this->actingAs($resident)->get("/api/v1/service-requests/{$request->id}/attachment")->assertOk();
    }

    public function test_a_rejected_attachment_leaves_nothing_on_disk(): void
    {
        [$resident] = $this->resident();

        $this->actingAs($resident)->postJson('/api/v1/service-requests', [
            'title' => 'x',
            'description' => 'y',
            'category' => ServiceRequestCategory::Other->value,
            'attachment' => UploadedFile::fake()->create('payload.php', 4, 'application/x-php'),
        ])->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ── دامنه‌ی دید ────────────────────────────────────────────────────────

    public function test_a_neighbour_can_neither_list_nor_open_someone_elses_request(): void
    {
        [$alice] = $this->resident();
        [$bob] = $this->resident();

        $this->submit($alice)->assertCreated();
        $request = ServiceRequest::firstOrFail();

        $this->assertSame([], $this->actingAs($bob)->getJson('/api/v1/service-requests')->json('requests'));
        $this->actingAs($bob)->getJson("/api/v1/service-requests/{$request->id}")->assertForbidden();
        $this->actingAs($bob)->get("/api/v1/service-requests/{$request->id}/attachment")->assertForbidden();
    }

    public function test_the_other_resident_of_the_same_unit_sees_the_request(): void
    {
        [$owner, $unit] = $this->resident();

        $tenant = $this->makeUser(UserRole::Tenant);
        $unit->residents()->attach($tenant->id, [
            'relation' => ResidentRelation::Tenant->value,
            'complex_id' => $this->complex->id,
            'is_current' => true,
        ]);

        $this->submit($owner)->assertCreated();

        // پرونده‌ی واحد است، نه پرونده‌ی شخص
        $this->assertCount(1, $this->actingAs($tenant)->getJson('/api/v1/service-requests')->json('requests'));
    }

    public function test_an_assignee_sees_a_request_from_another_unit(): void
    {
        [$alice] = $this->resident();
        [$caretaker] = $this->resident();

        $this->submit($alice)->assertCreated();
        $request = ServiceRequest::firstOrFail();

        $this->assertCount(0, $this->actingAs($caretaker)->getJson('/api/v1/service-requests')->json('requests'));

        $this->actingAs($this->manager)->patchJson(
            "/api/v1/service-requests/{$request->id}/assign",
            ['assigned_to' => $caretaker->id],
        )->assertOk();

        $this->assertCount(1, $this->actingAs($caretaker)->getJson('/api/v1/service-requests')->json('requests'));
    }

    public function test_a_manager_of_another_complex_cannot_open_the_request(): void
    {
        [$alice] = $this->resident();
        $this->submit($alice)->assertCreated();

        $outsider = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        // ۴۰۴ و نه ۴۰۳: شناسه‌ی مجتمعِ دیگر نباید حتی وجودش تایید شود
        $this->actingAs($outsider)
            ->getJson('/api/v1/service-requests/'.ServiceRequest::firstOrFail()->id)
            ->assertNotFound();
    }

    // ── چرخه‌ی وضعیت ───────────────────────────────────────────────────────

    public function test_a_resident_cannot_declare_their_own_request_resolved(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);
        $request = ServiceRequest::firstOrFail();

        $this->move($resident, $request, ServiceRequestStatus::Resolved)->assertStatus(422);
        $this->assertSame(ServiceRequestStatus::New, $request->fresh()->status);
    }

    public function test_an_assignee_can_take_the_job_and_report_it_done(): void
    {
        [$resident] = $this->resident();
        [$caretaker] = $this->resident();

        $this->submit($resident);
        $request = ServiceRequest::firstOrFail();

        $this->actingAs($this->manager)->patchJson(
            "/api/v1/service-requests/{$request->id}/assign",
            ['assigned_to' => $caretaker->id],
        );

        $this->move($caretaker, $request, ServiceRequestStatus::InProgress)->assertOk();
        $this->move($caretaker, $request->fresh(), ServiceRequestStatus::Resolved)->assertOk();

        $this->assertNotNull($request->fresh()->resolved_at);
    }

    public function test_only_the_requester_closes_the_request(): void
    {
        [$resident] = $this->resident();
        [$other] = $this->resident();

        $this->submit($resident);
        $request = ServiceRequest::firstOrFail();
        $this->move($this->manager, $request, ServiceRequestStatus::Resolved);

        $this->move($other, $request->fresh(), ServiceRequestStatus::Closed)->assertForbidden();
        $this->move($resident, $request->fresh(), ServiceRequestStatus::Closed)->assertOk();

        $this->assertSame(ServiceRequestStatus::Closed, $request->fresh()->status);
    }

    public function test_a_resident_can_reopen_a_request_that_was_not_really_fixed(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);
        $request = ServiceRequest::firstOrFail();

        $this->move($this->manager, $request, ServiceRequestStatus::Resolved);
        $this->assertNotNull($request->fresh()->resolved_at);

        $this->move($resident, $request->fresh(), ServiceRequestStatus::InProgress, 'هنوز درست نشده')
            ->assertOk();

        $fresh = $request->fresh();
        $this->assertSame(ServiceRequestStatus::InProgress, $fresh->status);

        // مهرِ «حل شد» پاک می‌شود، وگرنه گزارشِ زمانِ پاسخگویی دروغ می‌گفت
        $this->assertNull($fresh->resolved_at);
        $this->assertSame('هنوز درست نشده', ServiceRequestComment::firstOrFail()->body);
    }

    public function test_an_illegal_jump_in_the_lifecycle_is_refused(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);

        // از «ثبت‌شده» مستقیم به «بسته‌شده» راهی نیست
        $this->move($this->manager, ServiceRequest::firstOrFail(), ServiceRequestStatus::Closed)
            ->assertStatus(422);
    }

    public function test_only_a_manager_rejects_a_request(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);
        $request = ServiceRequest::firstOrFail();

        $this->move($resident, $request, ServiceRequestStatus::Rejected)->assertStatus(422);
        $this->move($this->manager, $request->fresh(), ServiceRequestStatus::Rejected)->assertOk();
    }

    // ── واگذاری ────────────────────────────────────────────────────────────

    public function test_a_resident_cannot_assign_a_request(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);

        $this->actingAs($resident)->patchJson(
            '/api/v1/service-requests/'.ServiceRequest::firstOrFail()->id.'/assign',
            ['assigned_to' => $resident->id],
        )->assertForbidden();
    }

    public function test_a_user_of_another_complex_cannot_be_made_responsible(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);

        $outsider = User::factory()->create([
            'complex_id' => Complex::factory()->create()->id,
            'role' => UserRole::Owner,
        ]);

        $this->actingAs($this->manager)->patchJson(
            '/api/v1/service-requests/'.ServiceRequest::firstOrFail()->id.'/assign',
            ['assigned_to' => $outsider->id],
        )->assertStatus(422);

        $this->assertNull(ServiceRequest::firstOrFail()->assigned_to);
    }

    public function test_the_assignee_is_notified(): void
    {
        [$resident] = $this->resident();
        [$caretaker] = $this->resident();

        $this->submit($resident);

        $this->actingAs($this->manager)->patchJson(
            '/api/v1/service-requests/'.ServiceRequest::firstOrFail()->id.'/assign',
            ['assigned_to' => $caretaker->id],
        )->assertOk();

        Notification::assertSentTo($caretaker, ServiceRequestNotification::class);
    }

    public function test_a_status_change_notifies_the_requester_but_not_the_actor(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);

        $this->move($this->manager, ServiceRequest::firstOrFail(), ServiceRequestStatus::InProgress);

        Notification::assertSentTo($resident, ServiceRequestNotification::class);
        Notification::assertNotSentTo($this->manager, ServiceRequestNotification::class);
    }

    // ── گفت‌وگو ────────────────────────────────────────────────────────────

    public function test_an_internal_note_never_reaches_the_resident(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);
        $request = ServiceRequest::firstOrFail();

        $this->actingAs($this->manager)->postJson("/api/v1/service-requests/{$request->id}/comments", [
            'body' => 'به تاسیسات زنگ زدم، هفته‌ی بعد می‌آید.',
            'is_internal' => true,
        ])->assertOk();

        $bodies = fn (User $user) => collect(
            $this->actingAs($user)->getJson("/api/v1/service-requests/{$request->id}")
                ->json('request.comments'),
        )->pluck('body')->all();

        $this->assertContains('به تاسیسات زنگ زدم، هفته‌ی بعد می‌آید.', $bodies($this->manager));
        $this->assertSame([], $bodies($resident));
    }

    public function test_a_resident_cannot_write_an_internal_note(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);
        $request = ServiceRequest::firstOrFail();

        $this->actingAs($resident)->postJson("/api/v1/service-requests/{$request->id}/comments", [
            'body' => 'یادداشت من',
            'is_internal' => true,
        ])->assertOk();

        // درخواستِ ساکن برای «داخلی» نادیده گرفته می‌شود
        $this->assertFalse(ServiceRequestComment::firstOrFail()->is_internal);
    }

    public function test_a_closed_request_takes_no_more_comments_from_residents(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident);
        $request = ServiceRequest::firstOrFail();

        $this->move($this->manager, $request, ServiceRequestStatus::Resolved);
        $this->move($resident, $request->fresh(), ServiceRequestStatus::Closed);

        $this->actingAs($resident)
            ->postJson("/api/v1/service-requests/{$request->id}/comments", ['body' => 'سلام'])
            ->assertStatus(422);
    }

    // ── فهرست ──────────────────────────────────────────────────────────────

    public function test_the_manager_list_is_sorted_by_priority_then_recency(): void
    {
        [$resident] = $this->resident();

        $this->submit($resident, ['title' => 'قدیمی عادی']);
        $this->submit($resident, ['title' => 'تازه عادی']);
        $this->submit($resident, ['title' => 'فوری', 'priority' => ServiceRequestPriority::Urgent->value]);

        $titles = collect($this->actingAs($this->manager)->getJson('/api/v1/service-requests')->json('requests'))
            ->pluck('title')->all();

        $this->assertSame(['فوری', 'تازه عادی', 'قدیمی عادی'], $titles);
    }

    public function test_the_open_filter_excludes_finished_requests(): void
    {
        [$resident] = $this->resident();
        $this->submit($resident, ['title' => 'باز']);
        $this->submit($resident, ['title' => 'رد شده']);

        $rejected = ServiceRequest::where('title', 'رد شده')->firstOrFail();
        $this->move($this->manager, $rejected, ServiceRequestStatus::Rejected);

        $response = $this->actingAs($this->manager)->getJson('/api/v1/service-requests?status=open');

        $this->assertSame(['باز'], collect($response->json('requests'))->pluck('title')->all());
        $this->assertSame(1, $response->json('counts.open'));
        $this->assertSame(1, $response->json('counts.rejected'));
    }

    public function test_the_mine_filter_shows_only_what_is_assigned_to_the_viewer(): void
    {
        [$resident] = $this->resident();
        [$caretaker] = $this->resident();

        $this->submit($resident, ['title' => 'مالِ من']);
        $this->submit($resident, ['title' => 'مالِ کسِ دیگر']);

        $this->actingAs($this->manager)->patchJson(
            '/api/v1/service-requests/'.ServiceRequest::where('title', 'مالِ من')->firstOrFail()->id.'/assign',
            ['assigned_to' => $caretaker->id],
        );

        $titles = collect(
            $this->actingAs($caretaker)->getJson('/api/v1/service-requests?mine=1')->json('requests'),
        )->pluck('title')->all();

        $this->assertSame(['مالِ من'], $titles);
    }

    public function test_the_assignable_list_is_only_given_to_the_manager(): void
    {
        [$resident] = $this->resident();

        $this->assertNotEmpty(
            $this->actingAs($this->manager)->getJson('/api/v1/service-requests')->json('assignables'),
        );

        $this->assertSame(
            [],
            $this->actingAs($resident)->getJson('/api/v1/service-requests')->json('assignables'),
        );
    }
}
