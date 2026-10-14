<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Messenger\ConversationDirectory;
use App\Services\Messenger\MessageSender;
use App\Services\Units\TenureService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * جست‌وجوی فهرستِ گفت‌وگوهای پیام‌رسان — مرحلهٔ ۱۰ · فاز ۸ (بندهای ۴۱، ۴۲ و ۸۲ ِ فایلِ معماری).
 *
 * پیش از این فاز جست‌وجو فقط **درونِ** یک گفت‌وگو بود؛ مدیری با صدها رشته راهی جز پیمایش نداشت، و رابط
 * فقط ۳۰ تای اول را نشان می‌داد. جست‌وجو پس از `visibleTo` می‌آید: باریک می‌کند، هرگز باز نمی‌کند و هیچ
 * گفت‌وگویی نمی‌سازد.
 */
class ConversationSearchTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    private User $managerA;

    private User $managerB;

    private User $ali;

    private User $sara;

    private User $narges;

    /** @var array<string, Conversation> */
    private array $threads = [];

    private Conversation $board;

    private Conversation $threadB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'مجتمع الف', 'messenger_enabled' => true]);
        $this->b = Complex::factory()->create(['name' => 'مجتمع ب', 'messenger_enabled' => true]);
        $this->managerA = $this->person($this->a, 'مدیر الف', '09128880001', UserRole::ComplexAdmin);
        $this->managerB = $this->person($this->b, 'مدیر ب', '09128880002', UserRole::ComplexAdmin);

        $tenures = app(TenureService::class);
        $directory = app(ConversationDirectory::class);

        $units = [];
        foreach (['10', '25', '100', '110'] as $number) {
            $units[$number] = Unit::factory()->create(['complex_id' => $this->a->id, 'unit_number' => $number]);
            $this->threads[$number] = $directory->unitThread($this->a, $units[$number]);
        }

        $this->ali = $this->person($this->a, 'علی احمدی', '09128880011', UserRole::Owner);
        $tenures->open($units['10'], $this->ali, ResidentRelation::Owner, 100, now()->subYear());
        $tenures->open($units['110'], $this->person($this->a, 'حسن صدوده', '09128880012', UserRole::Owner), ResidentRelation::Owner, 100, now()->subYear());
        $tenures->open($units['100'], $this->person($this->a, 'مینا صدی', '09128880013', UserRole::Owner), ResidentRelation::Owner, 100, now()->subYear());

        $reza = $this->person($this->a, 'رضا رفته', '09128880014', UserRole::Tenant);
        $tenures->close($tenures->open($units['25'], $reza, ResidentRelation::Tenant, 100, now()->subYears(2)), now()->subYear());
        $this->sara = $this->person($this->a, 'سارا تهرانی', '09128880015', UserRole::Tenant);
        $tenures->open($units['25'], $this->sara, ResidentRelation::Tenant, 100, now()->subMonths(6));

        // ساکنِ بی‌واحد: رشته‌ی خودش
        $this->narges = $this->person($this->a, 'نرگس بی‌واحد', '09128880016', UserRole::Tenant);
        $this->threads['narges'] = $directory->userThread($this->a, $this->narges);

        $this->board = $directory->createGroup($this->a, 'هیئت مدیره', [$units['10']->id], true, $this->managerA);
        $directory->channel($this->a);

        // مجتمعِ ب: همان شماره و همنامِ علی
        $unitB = Unit::factory()->create(['complex_id' => $this->b->id, 'unit_number' => '10']);
        $tenures->open($unitB, $this->person($this->b, 'علی احمدی', '09128880021', UserRole::Owner), ResidentRelation::Owner, 100, now()->subYear());
        $this->threadB = $directory->unitThread($this->b, $unitB);
    }

    public function test_the_manager_finds_conversations_by_the_examples_of_the_spec(): void
    {
        // «مالک واحد ۱۰» و «مستأجر واحد ۲۵»: رشته‌ی همان واحد، اگر الان چنین ساکنی دارد
        $this->assertSame([$this->threads['10']->id], $this->found($this->managerA, 'مالک واحد ۱۰'));
        $this->assertSame([$this->threads['25']->id], $this->found($this->managerA, 'مستأجر واحد ۲۵'));
        $this->assertSame([], $this->found($this->managerA, 'مستأجر واحد ۱۰'), 'واحدِ ۱۰ مستأجر ندارد.');

        // «علی احمدی»: رشته‌ی واحدِ فعلی‌اش؛ «نرگس»: رشته‌ی خودش
        $this->assertSame([$this->threads['10']->id], $this->found($this->managerA, 'علی احمدی'));
        $this->assertSame([$this->threads['narges']->id], $this->found($this->managerA, 'نرگس'));

        // «واحد 100» فقط خودش؛ «۱۰» ِ تنها هر واحدی که ۱۰ دارد
        $this->assertSame([$this->threads['100']->id], $this->found($this->managerA, 'واحد 100'));
        $this->assertEqualsCanonicalizing(
            [$this->threads['10']->id, $this->threads['100']->id, $this->threads['110']->id],
            $this->found($this->managerA, '۱۰'),
        );

        // عنوانِ گروه و کانال
        $this->assertSame([$this->board->id], $this->found($this->managerA, 'هیئت'));
        $this->assertCount(1, $this->found($this->managerA, 'اهالی'));
    }

    public function test_the_former_tenant_and_the_other_community_are_never_found(): void
    {
        $this->assertSame([], $this->found($this->managerA, 'رضا'), 'مستأجرِ رفته رشته‌ی واحدِ ۲۵ را پیدا کرد.');

        $this->assertSame([$this->threadB->id], $this->found($this->managerB, 'علی احمدی'));
        $this->assertSame([$this->threadB->id], $this->found($this->managerB, 'مالک واحد ۱۰'));
        $this->assertSame([], $this->found($this->managerB, 'سارا'));
        $this->assertSame([], $this->found($this->managerB, 'هیئت'));
        $this->assertSame([], $this->found($this->managerB, 'واحد 110'));
    }

    /** ساکن از این راه نامِ دیگران و رشته‌ی واحدهای دیگر را نمی‌شنود؛ فقط از میانِ دیده‌های خودش. */
    public function test_a_resident_searches_only_among_what_they_already_see(): void
    {
        foreach (['علی احمدی', 'مالک واحد ۱۰', 'واحد 10', 'نرگس'] as $q) {
            $this->assertSame([], $this->found($this->sara, $q), $q);
        }
        $this->assertSame([], $this->found($this->sara, 'هیئت'), 'گروهی که واحدش در آن نیست پیدا شد.');
        $this->assertSame([$this->board->id], $this->found($this->ali, 'هیئت'));

        /*
         * جست‌وجوی شماره و نام ابزارِ مدیر است: ساکن با آن حتی رشته‌ی **خودش** را پیدا نمی‌کند. (خرابکاریِ ۱۰/۸:
         * برداشتنِ این قاعده با آزمون‌های بالا دیده نمی‌شد، چون `visibleTo` خودش دیدِ ساکن را می‌بندد — ولی از راهِ
         * نام می‌شد پرسید «فلانی هم‌واحدِ من است؟».)
         */
        $this->assertSame([], $this->found($this->sara, 'واحد 25'));
        $this->assertSame([], $this->found($this->sara, 'سارا'));
    }

    /** جست‌وجو هیچ رشته‌ای نمی‌سازد، و رشته‌ی بی‌پیام را — که فهرستِ مدیر پنهانش می‌کند — پیدا می‌کند. */
    public function test_search_creates_nothing_and_reaches_threads_without_messages(): void
    {
        $before = Conversation::withoutGlobalScopes()->count();

        $plain = collect($this->as($this->managerA)->getJson('/api/v1/messenger/conversations')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($this->threads['25']->id, $plain);
        $this->assertSame([$this->threads['25']->id], $this->found($this->managerA, 'سارا'));

        $this->assertSame($before, Conversation::withoutGlobalScopes()->count());
    }

    public function test_search_results_are_paginated_with_the_same_cursor(): void
    {
        $expected = $this->crowd(35);

        $first = $this->as($this->managerA)->getJson('/api/v1/messenger/conversations?q='.urlencode('پرویز'))->assertOk();
        $this->assertCount(30, $first->json('data'));
        $this->assertTrue($first->json('hasMore'));

        $second = $this->as($this->managerA)
            ->getJson('/api/v1/messenger/conversations?q='.urlencode('پرویز').'&cursor='.urlencode((string) $first->json('nextCursor')))
            ->assertOk();
        $this->assertCount(5, $second->json('data'));
        $this->assertFalse($second->json('hasMore'));

        $seen = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')];
        $this->assertEqualsCanonicalizing($expected, $seen);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_conversations(): void
    {
        $this->crowd(3);
        $small = $this->queries('پرویز');
        $smallUnit = $this->queries('مالک واحد ۱۰');

        $this->crowd(30, 100);
        $this->assertLessThanOrEqual($small, $this->queries('پرویز'));
        $this->assertLessThanOrEqual($smallUnit, $this->queries('مالک واحد ۱۰'));
    }

    /** ادمینِ کل مجتمع را از نشست می‌گیرد؛ جست‌وجو همان مجتمع را می‌پرسد، نه `users.complex_id` ِ او. */
    public function test_the_super_admin_searches_the_community_chosen_in_the_session(): void
    {
        $super = User::factory()->create(['role' => UserRole::SuperAdmin, 'complex_id' => null, 'is_active' => true]);
        app(TenantContext::class)->set(null);

        $found = $this->actingAs($super)->withSession(['active_complex_id' => $this->a->id])
            ->getJson('/api/v1/messenger/conversations?q='.urlencode('مالک واحد ۱۰'))->assertOk()->json('data');

        $this->assertSame([$this->threads['10']->id], array_column($found, 'id'));
    }

    /* ── کمک‌ها ─────────────────────────────────────────────────────────── */

    private function person(Complex $complex, string $name, string $phone, UserRole $role): User
    {
        return User::factory()->create(['complex_id' => $complex->id, 'role' => $role, 'is_active' => true, 'name' => $name, 'phone' => $phone, 'can_message' => true]);
    }

    /**
     * «پرویز n»، هر یک با واحد، رشته و یک پیام.
     *
     * @return list<int>
     */
    private function crowd(int $count, int $from = 1): array
    {
        $ids = [];
        foreach (range($from, $from + $count - 1) as $i) {
            $person = $this->person($this->a, 'پرویز '.$i, '0914'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), UserRole::Owner);
            $unit = Unit::factory()->create(['complex_id' => $this->a->id, 'unit_number' => 'P'.$i]);
            app(TenureService::class)->open($unit, $person, ResidentRelation::Owner, 100, now()->subYear());
            $thread = app(ConversationDirectory::class)->unitThread($this->a, $unit);
            app(TenantContext::class)->set(null);
            $this->actingAs($person);
            app(MessageSender::class)->send($this->a, $thread, $person, ['body' => 'سلام '.$i]);
            $ids[] = $thread->id;
        }

        return $ids;
    }

    private function queries(string $q): int
    {
        $this->as($this->managerA);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/messenger/conversations?q='.urlencode($q))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return list<int> */
    private function found(User $as, string $q): array
    {
        $response = $this->as($as)->getJson('/api/v1/messenger/conversations?q='.urlencode($q))->assertOk();

        return array_column($response->json('data'), 'id');
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
