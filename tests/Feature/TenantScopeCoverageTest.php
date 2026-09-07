<?php

namespace Tests\Feature;

use App\Models\Complex;
use App\Models\Concerns\BelongsToComplex;
use App\Models\Unit;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اسکوپِ مستأجری روی **هر** مدلِ مستأجری اثر می‌کند (فاز ۲).
 *
 * ─── 🔴 چرا این تست ساخته شد ───────────────────────────────────────────────
 * خرابکاریِ فاز ۲ چیزی را نشان داد که هیچ‌کس انتظارش را نداشت: با برداشتنِ
 * فیلترِ `complex_id` از `ComplexScope`، از **۱۳۴۸ تستِ پروژه فقط یکی**
 * قرمز شد — و آن هم دربارهٔ خروجیِ اسناد بود.
 *
 * ⚠️ یعنی محافظی که **کلِ** جداسازیِ مستأجرها روی آن سوار است، عملاً بدونِ
 * پوشش بود. `TenantIsolationTest` مسیرهای تک‌رکوردی را می‌سنجید و آن‌ها با
 * سیاست‌های دسترسی هم بسته می‌شوند؛ پس با اسکوپِ شکسته هم سبز می‌ماندند.
 *
 * 🔴 آنچه هیچ تستی نمی‌سنجید، مسیرهای **فهرستی** بود: `index`ها ردیف‌ها را
 * از همین اسکوپ می‌گیرند. بدونِ آن، فهرستِ قبض‌ها و اطلاعیه‌ها و هزینه‌های
 * **همه‌ی مجتمع‌ها** به هر مدیری داده می‌شد.
 *
 * ─── چرا از SQL پرسیده می‌شود ──────────────────────────────────────────────
 * ⚠️ ساختنِ رکوردِ نمونه برای هر چهارده مدل، هر کدام با ستون‌های اجباریِ
 * خودش، تست را شکننده می‌کند و اولین ستونِ تازه می‌شکندش.
 *
 * اینجا خودِ **کوئریِ تولیدشده** پرسیده می‌شود؛ همان چیزی که اسکوپ قرار
 * است بسازد. و برای اینکه این ادعا فقط متنی نماند، تستِ آخر با رکوردِ
 * واقعی رفتار را هم می‌سنجد.
 */
class TenantScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * هر مدلی که تریتِ مستأجری دارد — از خودِ کلاس، نه از متنِ فایل.
     *
     * @return list<class-string<Model>>
     */
    private function tenantModels(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (in_array(BelongsToComplex::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        $this->assertGreaterThanOrEqual(
            10,
            count($models),
            'مدل‌های مستأجری پیدا نشدند؛ این محافظ پوچ می‌شود.',
        );

        return $models;
    }

    /**
     * 🔴 با زمینهٔ مجتمع، هر کوئری باید به `complex_id` مقید شود.
     *
     * ⚠️ این همان چیزی است که خرابکاری برداشت و هیچ تستی نگرفت.
     */
    public function test_every_tenant_model_is_constrained_to_the_active_complex(): void
    {
        app(TenantContext::class)->set(4242);

        $unscoped = [];

        foreach ($this->tenantModels() as $model) {
            $sql = $model::query()->toSql();
            $bindings = $model::query()->getBindings();

            if (! str_contains($sql, 'complex_id') || ! in_array(4242, $bindings, true)) {
                $unscoped[] = class_basename($model);
            }
        }

        $this->assertSame(
            [],
            $unscoped,
            "کوئریِ این مدل‌ها به مجتمعِ فعال مقید نمی‌شود:\n  ".implode("\n  ", $unscoped)
            ."\n🔴 یعنی فهرست‌هایشان ردیفِ همه‌ی مجتمع‌ها را برمی‌گردانند.",
        );
    }

    /**
     * 🔴 و کاربرِ بی‌مجتمع باید **هیچ‌چیز** ببیند، نه همه‌چیز.
     *
     * ⚠️ `null` در این اسکوپ معنای خطرناکی دارد: «فیلتر نگذار». پس حالتِ
     * «محروم» باید صریح باشد و برای هر مدل کار کند.
     */
    public function test_a_denied_context_blocks_every_tenant_model(): void
    {
        app(TenantContext::class)->denyAll();

        $leaking = [];

        foreach ($this->tenantModels() as $model) {
            if (! str_contains($model::query()->toSql(), '0 = 1')) {
                $leaking[] = class_basename($model);
            }
        }

        $this->assertSame(
            [],
            $leaking,
            "این مدل‌ها برای کاربرِ بی‌مجتمع بسته نمی‌شوند:\n  ".implode("\n  ", $leaking),
        );
    }

    /**
     * ⚠️ و همان ادعا با رکوردِ واقعی، تا فقط متنِ SQL نسنجیده باشیم.
     *
     * 🔴 بدونِ این، محافظِ بالا می‌توانست با یک `whereRaw` بی‌ربط که کلمهٔ
     * `complex_id` در آن باشد فریب بخورد.
     */
    public function test_a_list_query_really_hides_the_other_complex(): void
    {
        $mine = Complex::factory()->create();
        $theirs = Complex::factory()->create();

        Unit::withoutGlobalScopes()->create([
            'complex_id' => $mine->id, 'unit_number' => 'من', 'floor' => 1, 'area' => 100,
            'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
        ]);

        Unit::withoutGlobalScopes()->create([
            'complex_id' => $theirs->id, 'unit_number' => 'آن‌ها', 'floor' => 1, 'area' => 100,
            'residents_count' => 1, 'coefficient' => 1, 'occupancy_status' => 'owner_occupied',
        ]);

        app(TenantContext::class)->set($mine->id);

        $numbers = Unit::query()->pluck('unit_number')->all();

        $this->assertSame(['من'], $numbers, '🔴 فهرست، واحدِ مجتمعِ دیگر را هم برگرداند.');

        app(TenantContext::class)->denyAll();

        $this->assertSame([], Unit::query()->pluck('unit_number')->all(), '🔴 کاربرِ بی‌مجتمع واحد دید.');
    }
}
