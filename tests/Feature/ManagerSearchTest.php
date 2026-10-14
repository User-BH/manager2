<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Search\CommunityLookup;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use App\Support\SearchTerm;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * جست‌وجوی مدیر — مرحلهٔ ۱۰ · فاز ۸ (بندهای ۲۰، ۸۲ و ۱۲۱ ِ فایلِ معماری).
 *
 * چهار نمونه‌ی خودِ فایل («۱۰»، «مالک واحد ۱۰»، «علی احمدی»، «مستأجر واحد ۲۵») روی دو مجتمع که هر دو «واحدِ
 * ۱۰»، «واحدِ ۲۵» و یک «علی احمدی» دارند: هر جوابی که از مجتمعِ دیگر بیاید شکست است، نه «نتیجه‌ی بیشتر».
 */
class ManagerSearchTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    private User $managerA;

    private User $managerB;

    /** مالکِ واحدِ ۱۰ ِ الف */
    private User $ali;

    /** همنامِ علی در ب، مالکِ واحدِ ۱۰ ِ ب */
    private User $aliB;

    /** مستأجرِ فعلیِ واحدِ ۲۵ ِ الف */
    private User $sara;

    /** مستأجرِ پیشینِ واحدِ ۲۵ ِ الف — دوره‌اش بسته است */
    private User $reza;

    /** مالکِ واحدِ ۱۰۰ ِ الف و مستأجرِ واحدِ ۲۵ ِ ب */
    private User $mina;

    /** @var array<string, Unit> */
    private array $unitsA = [];

    /** @var array<string, Unit> */
    private array $unitsB = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'مجتمع الف']);
        $this->b = Complex::factory()->create(['name' => 'مجتمع ب']);
        $this->managerA = User::factory()->create(['complex_id' => $this->a->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09127770001']);
        $this->managerB = User::factory()->create(['complex_id' => $this->b->id, 'role' => UserRole::ComplexAdmin, 'is_active' => true, 'phone' => '09127770002']);

        foreach (['10', '25', '100', '110'] as $number) {
            $this->unitsA[$number] = Unit::factory()->create(['complex_id' => $this->a->id, 'unit_number' => $number]);
        }
        foreach (['10', '25'] as $number) {
            $this->unitsB[$number] = Unit::factory()->create(['complex_id' => $this->b->id, 'unit_number' => $number]);
        }

        $tenures = app(TenureService::class);

        $this->ali = $this->person($this->a, 'علی احمدی', '09127770011', UserRole::Owner);
        $tenures->open($this->unitsA['10'], $this->ali, ResidentRelation::Owner, 100, now()->subYear());
        // ⚠️ یک دوره‌ی جاری برای هر حساب در هر مجتمع (`TenureService::open`)؛ ۱۱۰ مالکِ خودش را دارد
        $tenures->open($this->unitsA['110'], $this->person($this->a, 'حسن صدوده', '09127770016', UserRole::Owner), ResidentRelation::Owner, 100, now()->subYear());

        $this->aliB = $this->person($this->b, 'علی احمدی', '09127770012', UserRole::Owner);
        $tenures->open($this->unitsB['10'], $this->aliB, ResidentRelation::Owner, 100, now()->subYear());

        $this->reza = $this->person($this->a, 'رضا رفته', '09127770013', UserRole::Tenant);
        $old = $tenures->open($this->unitsA['25'], $this->reza, ResidentRelation::Tenant, 100, now()->subYears(2));
        $tenures->close($old, now()->subYear());

        $this->sara = $this->person($this->a, 'سارا تهرانی', '09127770014', UserRole::Tenant);
        $tenures->open($this->unitsA['25'], $this->sara, ResidentRelation::Tenant, 100, now()->subMonths(6));

        $this->mina = $this->person($this->a, 'مینا دوعضوی', '09127770015', UserRole::Owner);
        $tenures->open($this->unitsA['100'], $this->mina, ResidentRelation::Owner, 100, now()->subYear());
        app(MembershipLedger::class)->start($this->mina, $this->b, UserRole::Tenant, ComplexMembership::SOURCE_DIRECT, activate: false);
        $tenures->open($this->unitsB['25'], $this->mina, ResidentRelation::Tenant, 100, now()->subMonths(3));
    }

    /* ── چهار نمونه‌ی بندِ ۲۰ ─────────────────────────────────────────── */

    public function test_the_four_examples_of_the_spec_find_the_right_people_in_the_managers_own_community(): void
    {
        // «۱۰»: هر واحدی که ۱۰ دارد، و ساکنِ خودِ واحدِ ۱۰ — با ارقامِ فارسی هم
        foreach (['10', '۱۰'] as $q) {
            $groups = $this->search($this->managerA, $q);
            $this->assertEqualsCanonicalizing(['واحد 10', 'واحد 100', 'واحد 110'], $this->titles($groups, 'units'), $q);
            $this->assertContains($this->ali->id, $this->ids($groups, 'residents'), $q);
        }

        // «مالک واحد ۱۰»: فقط مالکِ فعلیِ واحدِ ۱۰ ِ همین مجتمع، و فقط خودِ واحدِ ۱۰
        foreach (['مالک واحد ۱۰', 'مالکِ واحدِ ۱۰', 'مالک واحد 10', 'مالک ۱۰'] as $q) {
            $groups = $this->search($this->managerA, $q);
            $this->assertSame([$this->ali->id], $this->ids($groups, 'residents'), $q);
            $this->assertSame(['واحد 10'], $this->titles($groups, 'units'), $q);
        }

        // «علی احمدی»: همنامِ مجتمعِ دیگر نیست؛ «ي» ِ عربی هم همان است
        foreach (['علی احمدی', 'علي احمدي', 'احمدی'] as $q) {
            $this->assertSame([$this->ali->id], $this->ids($this->search($this->managerA, $q), 'residents'), $q);
        }

        // «مستأجر واحد ۲۵»: مستأجرِ **فعلی** — نه مستأجرِ رفته، نه مستأجرِ ۲۵ ِ ب
        foreach (['مستأجر واحد ۲۵', 'مستاجر واحد 25', 'مستأجرِ واحدِ ۲۵'] as $q) {
            $this->assertSame([$this->sara->id], $this->ids($this->search($this->managerA, $q), 'residents'), $q);
        }

        // واحدِ ۱۰ مستأجر ندارد؛ «ساکن» یعنی هر دو نسبت
        $this->assertSame([], $this->ids($this->search($this->managerA, 'مستأجر واحد ۱۰'), 'residents'));
        $this->assertSame([$this->sara->id], $this->ids($this->search($this->managerA, 'ساکن واحد ۲۵'), 'residents'));
    }

    /* ── بندهای ۸۲ و ۱۲۱: مجتمعِ دیگر ─────────────────────────────────── */

    public function test_the_manager_of_another_community_never_receives_this_communitys_results(): void
    {
        $this->assertSame([$this->aliB->id], $this->ids($this->search($this->managerB, 'علی'), 'residents'));
        $this->assertSame([$this->aliB->id], $this->ids($this->search($this->managerB, 'مالک واحد ۱۰'), 'residents'));
        $this->assertSame([$this->mina->id], $this->ids($this->search($this->managerB, 'مستأجر واحد ۲۵'), 'residents'));
        $this->assertSame(['واحد 10'], $this->titles($this->search($this->managerB, '10'), 'units'));

        // ساکنِ فقط‌الف، با هر عبارتی، از ب پیدا نمی‌شود — پاسخ خالی است، نه خطا
        foreach (['سارا', 'تهرانی', '09127770014', 'مستأجر واحد 25', '110'] as $q) {
            $response = $this->as($this->managerB)->getJson('/api/search?q='.urlencode($q))->assertOk();
            $this->assertStringNotContainsString('سارا', $response->getContent(), $q);
            $this->assertStringNotContainsString('110', json_encode($response->json('groups')), $q);
        }

        // پارامترِ مجتمع از کاربر پذیرفته نمی‌شود؛ زمینه از عضویت است
        $forged = $this->as($this->managerB)->getJson('/api/search?q='.urlencode('سارا').'&complex_id='.$this->a->id.'&group=residents')->assertOk();
        $this->assertSame(0, $forged->json('total'));
    }

    /** عضوِ دو مجتمع: مدیرِ هر طرف فقط واحدِ همان طرف را کنارِ نامش می‌بیند. */
    public function test_a_member_of_two_communities_carries_only_this_communitys_units(): void
    {
        $rowA = $this->rows($this->search($this->managerA, 'مینا'), 'residents');
        $this->assertSame([$this->mina->id], array_column($rowA, 'id'));
        $this->assertSame('واحد 100', $rowA[0]['badge']);

        $rowB = $this->rows($this->search($this->managerB, 'مینا'), 'residents');
        $this->assertSame([$this->mina->id], array_column($rowB, 'id'));
        $this->assertSame('واحد 25', $rowB[0]['badge']);
    }

    public function test_a_resident_cannot_reach_units_or_residents_through_any_parameter(): void
    {
        foreach (['10', 'مالک واحد ۱۰', 'علی احمدی'] as $q) {
            foreach (['', '&group=residents&page=2', '&group=units'] as $extra) {
                $groups = $this->as($this->sara)->getJson('/api/search?q='.urlencode($q).$extra)->assertOk()->json('groups');
                $ids = array_column($groups, 'id');
                $this->assertNotContains('units', $ids, $q.$extra);
                $this->assertNotContains('residents', $ids, $q.$extra);
                $this->assertNotContains('bills', $ids, $q.$extra);
            }
        }
    }

    /* ── صفحه‌بندی ──────────────────────────────────────────────────────── */

    public function test_a_group_is_paginated_without_gaps_or_overlap(): void
    {
        $expected = $this->crowd($this->a, 25);

        $overview = $this->as($this->managerA)->getJson('/api/search?q='.urlencode('پرویز'))->assertOk();
        $residents = collect($overview->json('groups'))->firstWhere('id', 'residents');
        $this->assertSame(8, $residents['count']);
        $this->assertTrue($residents['hasMore']);
        $this->assertNull($residents['nextPage'], 'نمای کلی صفحه‌ی بعد ندارد؛ «همه» گروه را باز می‌کند.');

        $first = collect($this->as($this->managerA)->getJson('/api/search?q='.urlencode('پرویز').'&group=residents')->assertOk()->json('groups'))->sole();
        $this->assertCount(20, $first['items']);
        $this->assertSame(2, $first['nextPage']);

        $second = collect($this->as($this->managerA)->getJson('/api/search?q='.urlencode('پرویز').'&group=residents&page=2')->assertOk()->json('groups'))->sole();
        $this->assertCount(5, $second['items']);
        $this->assertFalse($second['hasMore']);
        $this->assertNull($second['nextPage']);

        $seen = [...array_column($first['items'], 'id'), ...array_column($second['items'], 'id')];
        $this->assertSame(count($seen), count(array_unique($seen)), 'صفحه‌ها هم‌پوشانی دارند.');
        $this->assertEqualsCanonicalizing($expected, $seen);

        // صفحه‌ی بی‌معنا خطا نیست؛ سقف دارد
        $this->as($this->managerA)->getJson('/api/search?q='.urlencode('پرویز').'&group=residents&page=99999')
            ->assertOk()->assertJsonPath('page', 100)->assertJsonPath('groups', []);

        // همان عبارت از مدیرِ ب هیچ‌کدام را نمی‌آورد
        $this->assertSame(0, $this->as($this->managerB)->getJson('/api/search?q='.urlencode('پرویز').'&group=residents&page=2')->json('total'));
    }

    /** شمارِ کوئری با اندازه‌ی مجتمع رشد نمی‌کند (بندِ ۱۲۴: N+1). */
    public function test_the_query_count_does_not_grow_with_the_community(): void
    {
        $this->crowd($this->a, 3);
        $small = $this->queries('/api/search?q='.urlencode('پرویز'));
        $smallStructured = $this->queries('/api/search?q='.urlencode('مالک واحد ۱۰'));

        $this->crowd($this->a, 30, 100);
        $this->assertLessThanOrEqual($small, $this->queries('/api/search?q='.urlencode('پرویز')));
        $this->assertLessThanOrEqual($smallStructured, $this->queries('/api/search?q='.urlencode('مالک واحد ۱۰')));
    }

    /** «واحد ۱۰» در قبوض هم فقط واحدِ ۱۰ است، نه ۱۰۰ و ۱۱۰؛ قبضِ واحدِ ۱۰ ِ ب هرگز. */
    public function test_bills_of_an_exact_unit_are_that_units_only(): void
    {
        $period = Jalali::shiftPeriod(Jalali::currentPeriod(), -1);
        $mine = Bill::factory()->create(['complex_id' => $this->a->id, 'unit_id' => $this->unitsA['10']->id, 'period' => $period]);
        Bill::factory()->create(['complex_id' => $this->a->id, 'unit_id' => $this->unitsA['100']->id, 'period' => $period]);
        Bill::factory()->create(['complex_id' => $this->b->id, 'unit_id' => $this->unitsB['10']->id, 'period' => $period]);

        $this->assertSame([$mine->id], $this->ids($this->search($this->managerA, 'واحد ۱۰'), 'bills'));
        $this->assertCount(2, $this->ids($this->search($this->managerA, '10'), 'bills'));
    }

    /**
     * مرزِ خودِ `CommunityLookup`، بی لایه‌های بیرونی.
     *
     * خرابکاریِ ۱۰/۸ نشان داد برداشتنِ شرطِ مجتمع از این کمک‌ها در HTTP دیده نمی‌شد: دامنه‌ی `Unit`، پیوندِ
     * عضویت در `ComplexMembers` و دامنه‌ی گفت‌وگو هر کدام جلویش را می‌گرفتند. خوب است — ولی سندِ این کلاس
     * می‌گوید مرز همین‌جا نوشته شده، پس همین‌جا هم سنجیده می‌شود.
     */
    public function test_the_lookup_itself_never_crosses_the_community(): void
    {
        $roles = [UserRole::Owner, UserRole::Tenant];
        $pluck = fn ($builder, string $column) => $builder->pluck($column)->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame([$this->unitsA['10']->id], $pluck(CommunityLookup::units($this->a->id, SearchTerm::parse('واحد ۱۰')), 'id'));
        $this->assertSame([$this->ali->id], $pluck(CommunityLookup::currentResidents($this->a->id, SearchTerm::parse('مالک واحد ۱۰')), 'user_id'));
        $this->assertSame([$this->unitsB['25']->id], $pluck(CommunityLookup::occupiedUnits($this->b->id, SearchTerm::parse('مستأجر واحد ۲۵')), 'unit_id'));
        $this->assertSame([$this->ali->id], $pluck(CommunityLookup::membersMatching($this->a->id, SearchTerm::parse('علی احمدی'), $roles), 'id'));
        $this->assertSame([$this->unitsA['100']->id], $pluck(CommunityLookup::unitsOf($this->a->id, DB::table('users')->select('id')->where('id', $this->mina->id)), 'unit_id'));
    }

    /* ── خواندنِ عبارت ─────────────────────────────────────────────────── */

    public function test_the_term_is_read_once_and_safely(): void
    {
        $owner = SearchTerm::parse(' مالکِ   واحدِ ۱۰ ');
        $this->assertSame(['10', true, ResidentRelation::Owner], [$owner->unit, $owner->exactUnit, $owner->relation]);

        $tenant = SearchTerm::parse('مستأجر واحد ۲۵');
        $this->assertSame(['25', true, ResidentRelation::Tenant], [$tenant->unit, $tenant->exactUnit, $tenant->relation]);

        $bare = SearchTerm::parse('۱۰');
        $this->assertSame(['10', false, null], [$bare->unit, $bare->exactUnit, $bare->relation]);

        $name = SearchTerm::parse('علي احمدي');
        $this->assertSame(['علی احمدی', null], [$name->text, $name->unit]);

        // واژه‌ی لاتینِ بی‌رقم شماره‌ی واحد نیست؛ نویسه‌های LIKE حذف می‌شوند
        $this->assertNull(SearchTerm::parse('ali')->unit);
        $this->assertSame('10', SearchTerm::parse('%1_0%')->text);
        $this->assertFalse(SearchTerm::parse('%_')->isSearchable());
    }

    /* ── کمک‌ها ─────────────────────────────────────────────────────────── */

    private function person(Complex $complex, string $name, string $phone, UserRole $role): User
    {
        return User::factory()->create(['complex_id' => $complex->id, 'role' => $role, 'is_active' => true, 'name' => $name, 'phone' => $phone]);
    }

    /**
     * ساکنانِ «پرویز n»، هر یک با واحدِ خودش.
     *
     * @return list<int>
     */
    private function crowd(Complex $complex, int $count, int $from = 1): array
    {
        $ids = [];
        foreach (range($from, $from + $count - 1) as $i) {
            $person = $this->person($complex, 'پرویز '.$i, '0913'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), UserRole::Owner);
            $unit = Unit::factory()->create(['complex_id' => $complex->id, 'unit_number' => 'P'.$i]);
            app(TenureService::class)->open($unit, $person, ResidentRelation::Owner, 100, now()->subYear());
            $ids[] = $person->id;
        }

        return $ids;
    }

    private function queries(string $url): int
    {
        $this->as($this->managerA);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<int, array<string, mixed>> */
    private function search(User $as, string $q): array
    {
        return $this->as($as)->getJson('/api/search?q='.urlencode($q))->assertOk()->json('groups');
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function rows(array $groups, string $group): array
    {
        return collect($groups)->firstWhere('id', $group)['items'] ?? [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return list<int>
     */
    private function ids(array $groups, string $group): array
    {
        return array_column($this->rows($groups, $group), 'id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return list<string>
     */
    private function titles(array $groups, string $group): array
    {
        return array_column($this->rows($groups, $group), 'title');
    }

    private function as(User $user): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();

        return $this->actingAs($user);
    }
}
