<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Bill;
use App\Models\Building;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * پروفایلِ تعدادِ کوئری روی داده‌ی انبوه (R13).
 *
 * ─── چرا این تست وجود دارد ─────────────────────────────────────────────────
 * N+1 با داده‌ی کم **دیده نمی‌شود**: با ۲ واحد، ۳ کوئری و ۵ کوئری هر دو سریع
 * به نظر می‌رسند. مشکل وقتی ظاهر می‌شود که مجتمع ۸۰ واحد دارد و همان صفحه
 * ۱۶۰ کوئری می‌زند.
 *
 * پس اینجا حجمِ واقعی ساخته می‌شود و **سقفِ تعدادِ کوئری** ثابت می‌ماند. نکته‌ی
 * کلیدی: سقف‌ها عمداً از تعدادِ ردیف‌ها مستقل‌اند. اگر کسی eager loading را
 * بردارد، تعداد با داده رشد می‌کند و از سقف رد می‌شود — همان چیزی که باید
 * گرفته شود.
 *
 * سقف‌ها «هدف» نیستند، «زنگِ خطر»اند: کمی بالاتر از عددِ فعلی‌اند تا تغییرِ
 * بی‌ضرر تست را نشکند، ولی آن‌قدر پایین که رشدِ خطیِ کوئری فوراً دیده شود.
 */
class QueryProfileTest extends TestCase
{
    use RefreshDatabase;

    /** آن‌قدر که N+1 خودش را نشان بدهد. */
    private const UNITS = 40;

    private Complex $complex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVolume();
    }

    /**
     * شمارشِ کوئری‌های یک درخواست.
     *
     * @return array{count: int, queries: list<string>}
     */
    private function profile(callable $request): array
    {
        /*
         * `enableQueryLog()` و نه `DB::listen()`.
         *
         * شنونده در این بستر هیچ کوئری‌ای نگرفت (شمارش صفر ماند) و تست با
         * سقفِ صفر هم سبز می‌شد — یعنی سنجه‌ای که هیچ‌چیز را نمی‌سنجید.
         * لاگِ کوئری روی همان اتصال ثبت می‌شود و قابلِ اتکاست.
         */
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $request();

        $queries = array_column(DB::connection()->getQueryLog(), 'query');
        DB::connection()->disableQueryLog();

        return ['count' => count($queries), 'queries' => $queries];
    }

    /**
     * پیامِ خطای مفید: کدام کوئری تکرار شده.
     *
     * بدونِ این، تستِ شکسته فقط می‌گوید «۹۰ > ۲۵» و برنامه‌نویس باید خودش
     * دنبالِ علت بگردد.
     */
    private function assertQueryBudget(array $profile, int $budget, string $endpoint): void
    {
        if ($profile['count'] <= $budget) {
            $this->assertTrue(true);

            return;
        }

        $repeated = collect($profile['queries'])
            ->countBy()
            ->sortDesc()
            ->take(3)
            ->map(fn (int $times, string $sql) => "{$times}× ".mb_substr($sql, 0, 90))
            ->implode("\n  ");

        $this->fail(
            "{$endpoint}: {$profile['count']} کوئری برای ".self::UNITS." واحد (سقف {$budget}).\n"
            ."پرتکرارترین‌ها:\n  {$repeated}"
        );
    }

    public function test_units_list_does_not_scale_queries_with_rows(): void
    {
        $profile = $this->profile(
            fn () => $this->actingAs($this->admin)->getJson('/api/v1/units')->assertOk(),
        );

        $this->assertQueryBudget($profile, 8, 'GET /units');
    }

    public function test_residents_list_does_not_scale_queries_with_rows(): void
    {
        $profile = $this->profile(
            fn () => $this->actingAs($this->admin)->getJson('/api/v1/residents')->assertOk(),
        );

        $this->assertQueryBudget($profile, 8, 'GET /residents');
    }

    public function test_bills_list_does_not_scale_queries_with_rows(): void
    {
        $profile = $this->profile(
            fn () => $this->actingAs($this->admin)->getJson('/api/v1/bills')->assertOk(),
        );

        $this->assertQueryBudget($profile, 6, 'GET /bills');
    }

    public function test_dashboard_does_not_scale_queries_with_rows(): void
    {
        $profile = $this->profile(
            fn () => $this->actingAs($this->admin)->getJson('/api/v1/dashboard')->assertOk(),
        );

        $this->assertQueryBudget($profile, 20, 'GET /dashboard');
    }

    public function test_announcements_list_does_not_scale_queries_with_rows(): void
    {
        $profile = $this->profile(
            fn () => $this->actingAs($this->admin)->getJson('/api/v1/announcements')->assertOk(),
        );

        $this->assertQueryBudget($profile, 8, 'GET /announcements');
    }

    public function test_payments_review_list_does_not_scale_queries_with_rows(): void
    {
        $profile = $this->profile(
            fn () => $this->actingAs($this->admin)->getJson('/api/v1/payments')->assertOk(),
        );

        $this->assertQueryBudget($profile, 9, 'GET /payments');
    }

    public function test_finance_summary_does_not_scale_queries_with_rows(): void
    {
        $profile = $this->profile(
            fn () => $this->actingAs($this->admin)->getJson('/api/v1/finance')->assertOk(),
        );

        $this->assertQueryBudget($profile, 6, 'GET /finance');
    }

    /* ── صفحه‌های عمومی: پرترافیک‌ترین مسیرها ──────────────────────────── */

    public function test_the_public_landing_page_is_cheap_to_render(): void
    {
        /*
         * صفحه‌ی فرود را مهمان می‌بیند و بیشترین بازدید را دارد. هر کوئریِ
         * اضافه اینجا در تمامِ ترافیکِ ورودی ضرب می‌شود، پس سقتش سخت‌گیرانه‌تر
         * از صفحه‌های داشبورد است.
         */
        $profile = $this->profile(fn () => $this->get('/')->assertOk());

        $this->assertQueryBudget($profile, 4, 'GET / (فرود)');
    }

    public function test_public_ads_endpoint_is_cheap(): void
    {
        $profile = $this->profile(fn () => $this->getJson('/api/v1/ads')->assertOk());

        $this->assertQueryBudget($profile, 4, 'GET /ads');
    }

    /** ساختِ حجمِ واقعی: واحد، ساکن، قبض، پرداخت، اطلاعیه. */
    private function seedVolume(): void
    {
        $this->complex = Complex::create([
            'name' => 'مجتمع پرحجم', 'slug' => 'perf-'.uniqid(), 'currency' => 'toman',
            'charge_due_day' => 10, 'payment_gateway' => 'none', 'messenger_enabled' => true,
        ]);

        $this->admin = User::factory()->create([
            'role' => UserRole::ComplexAdmin,
            'complex_id' => $this->complex->id,
            'is_active' => true,
        ]);

        $building = Building::create([
            'complex_id' => $this->complex->id,
            'name' => 'بلوک الف',
            'floors' => 10,
        ]);

        $period = Jalali::currentPeriod();

        for ($i = 1; $i <= self::UNITS; $i++) {
            $unit = Unit::create([
                'complex_id' => $this->complex->id,
                'building_id' => $building->id,
                'unit_number' => (string) $i,
                'floor' => (int) ceil($i / 4),
                'area' => 70 + $i,
            ]);

            $resident = User::factory()->create([
                'role' => UserRole::Owner,
                'complex_id' => $this->complex->id,
                'is_active' => true,
            ]);
            $unit->residents()->attach($resident->id, [
                'complex_id' => $this->complex->id,
                'relation' => 'owner',
                'share_percent' => 100,
                'is_current' => true,
                'start_date' => now()->subYear(),
            ]);

            $bill = Bill::create([
                'complex_id' => $this->complex->id,
                'unit_id' => $unit->id,
                'period' => $period,
                'total' => 500000 + $i,
                'status' => 'unpaid',
                'due_date' => now()->addDays(10),
            ]);

            Payment::create([
                'complex_id' => $this->complex->id,
                'bill_id' => $bill->id,
                'unit_id' => $unit->id,
                'user_id' => $resident->id,
                'amount' => 500000,
                'method' => 'receipt',
                'status' => PaymentStatus::Pending,
            ]);
        }

        for ($i = 1; $i <= 15; $i++) {
            Announcement::create([
                'complex_id' => $this->complex->id,
                'title' => "اطلاعیه {$i}",
                'body' => 'متن آزمایشی',
                'audience' => 'all',
                'is_pinned' => false,
                'is_active' => true,
                'published_at' => now(),
                'created_by' => $this->admin->id,
            ]);
        }
    }
}
