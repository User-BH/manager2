<?php

namespace Tests\Feature;

use App\Enums\ResidentRelation;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\Expense;
use App\Models\Unit;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use App\Support\SessionContext;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * جست‌وجوی نوارِ بالا در چهار داشبورد — مرحلهٔ ۱۳ · فاز ۴ (ایرادات ۱۰، بندهای ۵۲ و ۶۴؛ ایرادات ۱۱، بندهای ۲.۴ تا ۲.۷).
 *
 * آیکون مجوز نمی‌سازد: هر داشبورد همان مسیرِ `GET search` را دارد و دامنه را سرور از زمینه و نقش می‌دهد.
 *
 * 🔴 **یافته‌ی ۱۳/۴:** سوپر ادمینی که مجتمعی در پنلِ سیستم برنگزیده زمینه‌ی مستأجرِ `null` دارد، و `ComplexScope` برای
 * `null` فیلتری نمی‌گذارد؛ جست‌وجو واحد، قبض، هزینه و اطلاعیه‌ی **همه‌ی** مجتمع‌ها را برمی‌گرداند. جاروی `BolaSweepTest`
 * این را نمی‌دید چون فقط مسیرهای دارای `role:` را برای سوپر ادمینِ بی‌زمینه می‌پیماید و `search` چنین میان‌افزاری ندارد.
 */
class TopBarSearchTest extends TestCase
{
    use RefreshDatabase;

    private Complex $a;

    private Complex $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Complex::factory()->create(['name' => 'مجتمع آفتاب']);
        $this->b = Complex::factory()->create(['name' => 'مجتمع نفیس']);

        foreach ([[$this->a, '7701', 'الف'], [$this->b, '7702', 'ب']] as [$complex, $number, $tag]) {
            $unit = Unit::factory()->create(['complex_id' => $complex->id, 'unit_number' => $number]);
            Bill::factory()->create(['complex_id' => $complex->id, 'unit_id' => $unit->id, 'period' => Jalali::currentPeriod()]);
            Expense::factory()->create(['complex_id' => $complex->id, 'title' => "هزینه‌ی ۷۷۰ {$tag}"]);
            Announcement::factory()->create(['complex_id' => $complex->id, 'title' => "اطلاعیه‌ی ۷۷۰ {$tag}", 'body' => 'متن']);
        }
    }

    public function test_a_super_admin_without_a_selected_community_finds_no_community_data(): void
    {
        $super = $this->person(UserRole::SuperAdmin, null);

        $response = $this->as($super)->getJson('/api/v1/search?q=770')->assertOk();

        $this->assertSame([0, []], [$response->json('total'), $response->json('groups')]);
        $this->assertStringContainsString('مدیریت مجتمع‌ها', (string) $response->json('message'));
        $this->assertLeaksNothing($response, ['7701', '7702', 'هزینه‌ی ۷۷۰', 'اطلاعیه‌ی ۷۷۰']);
    }

    public function test_a_super_admin_searches_only_the_community_chosen_in_the_system_panel(): void
    {
        $super = $this->person(UserRole::SuperAdmin, null);
        $this->as($super)->postJson("/api/v1/system/complexes/{$this->a->id}/select")->assertOk();

        $response = $this->actingAs($super)->getJson('/api/v1/search?q=770')->assertOk();

        $this->assertContains('واحد 7701', $this->titles($response, 'units'));
        $this->assertLeaksNothing($response, ['7702', 'هزینه‌ی ۷۷۰ ب', 'اطلاعیه‌ی ۷۷۰ ب']);
    }

    public function test_a_manager_searches_only_their_community(): void
    {
        $manager = $this->person(UserRole::ComplexAdmin, $this->a);

        $response = $this->as($manager)->getJson('/api/v1/search?q=770')->assertOk();

        $this->assertSame(['واحد 7701'], $this->titles($response, 'units'));
        $this->assertLeaksNothing($response, ['7702', 'هزینه‌ی ۷۷۰ ب', 'اطلاعیه‌ی ۷۷۰ ب']);
    }

    public function test_a_unit_resident_gets_no_management_groups(): void
    {
        $resident = $this->person(UserRole::Owner, $this->a);
        app(TenureService::class)->open(Unit::where('unit_number', '7701')->sole(), $resident, ResidentRelation::Owner);

        $response = $this->as($resident)->getJson('/api/v1/search?q=770')->assertOk();

        $ids = collect($response->json('groups'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($ids, ['units', 'residents', 'bills', 'expenses']));
        $this->assertLeaksNothing($response, ['7702', 'هزینه‌ی ۷۷۰', 'اطلاعیه‌ی ۷۷۰ ب']);
    }

    /** داشبوردِ اولیه جست‌وجو ندارد؛ API هم برای حسابِ اولیه و دو زمینه‌ی بی‌مجتمعِ عضو (۱۳/۳) هیچ داده‌ی مجتمعی نمی‌دهد. */
    public function test_the_initial_dashboard_and_detached_contexts_find_no_community_data(): void
    {
        $initial = $this->person(UserRole::Owner, null);
        $this->assertLeaksNothing($this->as($initial)->getJson('/api/v1/search?q=770')->assertOk(), ['7701', '7702', 'هزینه‌ی ۷۷۰', 'اطلاعیه‌ی ۷۷۰']);

        $member = $this->person(UserRole::ComplexAdmin, $this->a);
        app(MembershipLedger::class)->start($member, $this->b, UserRole::Owner, ComplexMembership::SOURCE_DIRECT, activate: false);
        foreach ([SessionContext::DETACHED, SessionContext::CHOOSING] as $mode) {
            $response = $this->as($member, $member->id.':'.$mode)->getJson('/api/v1/search?q=770')->assertOk();
            $this->assertSame(0, $response->json('total'), $mode);
            $this->assertLeaksNothing($response, ['7701', '7702', 'هزینه‌ی ۷۷۰', 'اطلاعیه‌ی ۷۷۰']);
        }
    }

    private function person(UserRole $role, ?Complex $complex): User
    {
        static $n = 0;
        $n++;

        return User::factory()->create([
            'complex_id' => $complex?->id, 'role' => $role, 'is_active' => true, 'phone' => sprintf('0912777%04d', $n),
        ]);
    }

    private function as(User $user, ?string $pin = null): static
    {
        app(TenantContext::class)->set(null);
        $this->flushSession();
        $this->defaultCookies = [];
        $this->withCredentials();
        if ($pin !== null) {
            $this->withCookie(SessionContext::COOKIE, $pin);
        }

        return $this->actingAs(User::withoutGlobalScopes()->findOrFail($user->id));
    }

    /** @return list<string> */
    private function titles(TestResponse $response, string $group): array
    {
        $found = collect($response->json('groups'))->firstWhere('id', $group);

        return array_column($found['items'] ?? [], 'title');
    }

    /** @param  list<string>  $markers */
    private function assertLeaksNothing(TestResponse $response, array $markers): void
    {
        $body = (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        foreach ($markers as $marker) {
            $this->assertStringNotContainsString($marker, $body);
        }
    }
}
