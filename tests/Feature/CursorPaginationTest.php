<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\ErrorEvent;
use App\Models\Unit;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحه‌بندیِ نشانگری و سقفِ فهرست‌های بزرگ (R30).
 *
 * ─── چرا این تست‌ها بیش از «کار می‌کند» را می‌سنجند ─────────────────────────
 * ایرادِ `offset` روی جدولِ افزایشی **کندی نیست، غلط‌بودن است**: بین دو
 * درخواست ردیف‌های تازه بالای فهرست می‌نشینند و صفحه‌ی دوم ردیف‌هایی را
 * برمی‌گرداند که کاربر همین الان دیده — و به همان تعداد، ردیفِ قدیمی‌تر را
 * هرگز نمی‌بیند.
 *
 * پس محورِ آزمون‌ها این جمله است: **هیچ ردیفی دو بار دیده نمی‌شود و هیچ
 * ردیفی جا نمی‌افتد، حتی وقتی وسطِ کار داده‌ی تازه اضافه شود.**
 */
class CursorPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'complex_id' => null,
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    private function log(string $description): AuditLog
    {
        return AuditLog::create([
            'user_id' => $this->superAdmin->id,
            'action' => 'resident.deleted',
            'description' => $description,
        ]);
    }

    /** @return array<int, string> */
    private function descriptions(?int $cursor = null): array
    {
        $url = '/api/v1/system/audit-logs'.($cursor ? "?cursor={$cursor}" : '');

        return collect($this->actingAs($this->superAdmin)->getJson($url)->json('data'))
            ->pluck('description')
            ->all();
    }

    // ── درستیِ نشانگر ──────────────────────────────────────────────────────

    public function test_a_page_returns_the_newest_rows_first(): void
    {
        foreach (range(1, 5) as $i) {
            $this->log("رویداد {$i}");
        }

        $this->assertSame(
            ['رویداد 5', 'رویداد 4', 'رویداد 3', 'رویداد 2', 'رویداد 1'],
            $this->descriptions(),
        );
    }

    public function test_rows_added_between_requests_never_duplicate_or_hide_older_ones(): void
    {
        // ۳۵ رویداد: بیش از یک صفحه‌ی ۳۰تایی
        foreach (range(1, 35) as $i) {
            $this->log("رویداد {$i}");
        }

        $first = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/audit-logs');
        $firstPage = collect($first->json('data'))->pluck('description')->all();

        $this->assertCount(30, $firstPage);
        $this->assertTrue($first->json('hasMore'));

        /*
         * ⚠️ هسته‌ی این مرحله: بین دو درخواست، ۵ رویدادِ تازه ثبت می‌شود.
         *
         * با `OFFSET 30` صفحه‌ی دوم پنج ردیفی را برمی‌گرداند که در صفحه‌ی
         * اول دیده شده‌اند و پنج ردیفِ قدیمی‌ترِ واقعی را جا می‌اندازد.
         */
        foreach (range(36, 40) as $i) {
            $this->log("رویداد {$i}");
        }

        $secondPage = $this->descriptions($first->json('nextCursor'));

        $this->assertSame([], array_intersect($firstPage, $secondPage), 'ردیف تکراری بین دو صفحه');
        $this->assertSame(['رویداد 5', 'رویداد 4', 'رویداد 3', 'رویداد 2', 'رویداد 1'], $secondPage);
    }

    public function test_walking_the_whole_list_visits_every_row_exactly_once(): void
    {
        foreach (range(1, 75) as $i) {
            $this->log("رویداد {$i}");
        }

        $seen = [];
        $cursor = null;

        do {
            $url = '/api/v1/system/audit-logs'.($cursor ? "?cursor={$cursor}" : '');
            $response = $this->actingAs($this->superAdmin)->getJson($url);

            $seen = [...$seen, ...collect($response->json('data'))->pluck('description')->all()];
            $cursor = $response->json('nextCursor');
        } while ($response->json('hasMore'));

        $this->assertCount(75, $seen);
        $this->assertCount(75, array_unique($seen));
    }

    public function test_the_last_page_reports_no_more(): void
    {
        foreach (range(1, 3) as $i) {
            $this->log("رویداد {$i}");
        }

        $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/audit-logs');

        $this->assertFalse($response->json('hasMore'));
        $this->assertNull($response->json('nextCursor'));
    }

    public function test_an_empty_list_does_not_break(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/audit-logs');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertFalse($response->json('hasMore'));
    }

    public function test_a_filter_still_applies_across_pages(): void
    {
        foreach (range(1, 40) as $i) {
            $this->log("حذف {$i}");
        }

        AuditLog::create([
            'user_id' => $this->superAdmin->id,
            'action' => 'payment.settled',
            'description' => 'پرداخت',
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/system/audit-logs?action=resident');

        $descriptions = collect($response->json('data'))->pluck('description')->all();

        $this->assertNotContains('پرداخت', $descriptions);
        $this->assertTrue($response->json('hasMore'));
    }

    public function test_a_garbage_cursor_does_not_leak_the_whole_table(): void
    {
        foreach (range(1, 5) as $i) {
            $this->log("رویداد {$i}");
        }

        // نشانگرِ بی‌معنا: باید مثل «از ابتدا» رفتار کند، نه اینکه بترکد
        $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/system/audit-logs?cursor=abc');

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));
    }

    // ── رویدادهای خطا ──────────────────────────────────────────────────────

    public function test_the_error_list_can_now_be_walked_past_the_first_page(): void
    {
        foreach (range(1, 25) as $i) {
            ErrorEvent::create([
                'source' => 'server',
                'type' => 'RuntimeException',
                'message' => "خطا {$i}",
                'fingerprint' => 'fp-'.$i,
                'occurrences' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $first = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/system/observability/errors');

        /*
         * پیش از R30 این فهرست در رابط **هیچ** صفحه‌بندی‌ای نداشت: سرور
         * ۲۰ ردیفِ اول را می‌داد و بقیه اصلاً قابلِ دیدن نبودند.
         */
        $this->assertCount(20, $first->json('data'));
        $this->assertTrue($first->json('hasMore'));

        $second = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/system/observability/errors?cursor='.$first->json('nextCursor'));

        $this->assertCount(5, $second->json('data'));
        $this->assertFalse($second->json('hasMore'));
    }

    // ── فهرستِ قبض‌ها ──────────────────────────────────────────────────────

    public function test_bill_totals_come_from_the_whole_period_not_the_returned_rows(): void
    {
        $complex = Complex::factory()->create();
        $manager = User::factory()->create([
            'complex_id' => $complex->id,
            'role' => UserRole::ComplexAdmin,
            'is_active' => true,
        ]);

        $period = Jalali::currentPeriod();

        foreach (range(1, 3) as $i) {
            $unit = Unit::factory()->create(['complex_id' => $complex->id, 'unit_number' => (string) $i]);

            Bill::create([
                'complex_id' => $complex->id,
                'unit_id' => $unit->id,
                'period' => $period,
                'base_amount' => 100000,
                'total_amount' => 100000,
                'paid_amount' => 40000,
                'due_date' => now()->addDays(10),
            ]);
        }

        $response = $this->actingAs($manager)->getJson('/api/v1/bills');

        /*
         * جمع‌ها در SQL حساب می‌شوند و نه از ردیف‌های برگشته؛ وگرنه با
         * رسیدن به سقفِ ردیف، مبلغِ کل بی‌سروصدا کمتر از واقعیت می‌شد.
         */
        $this->assertEqualsWithDelta(300000.0, $response->json('total'), 0.01);
        $this->assertEqualsWithDelta(120000.0, $response->json('collected'), 0.01);
        $this->assertFalse($response->json('truncated'));

        /*
         * ⚠️ همان‌جا که محافظ واقعاً لازم می‌شود: با رسیدن به سقف، فقط دو
         * ردیف برمی‌گردد ولی جمع باید همچنان کلِ دوره را بگوید. پیش از
         * R30 جمع از ردیف‌های برگشته حساب می‌شد و بی‌سروصدا کمتر از
         * واقعیت نشان می‌داد.
         */
        config(['app.bills_max_rows' => 2]);

        $capped = $this->actingAs($manager)->getJson('/api/v1/bills');

        $this->assertCount(2, $capped->json('data'));
        $this->assertTrue($capped->json('truncated'));
        $this->assertEqualsWithDelta(300000.0, $capped->json('total'), 0.01);
    }
}
