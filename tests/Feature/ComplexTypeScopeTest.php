<?php

namespace Tests\Feature;

use App\Enums\ComplexType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * دامنه‌ی نوعِ مجتمع: فقط مسکونی و تجاری — تصمیمِ نهاییِ کارفرما، ۲۰۲۶-۱۰-۲۶ (پاسخِ تعارضِ ت-۱ ِ «حفره ها۲»).
 *
 * Mixed-Use INTENTIONALLY NOT BUILT است. این آزمون abstraction ِ تازه‌ای نمی‌خواهد؛ فقط دو چیز را نگه می‌دارد:
 *   ① نوعِ سوم بی‌صدا اضافه نشود — افزودنش Specification ِ مستقل، بررسیِ اثر، مهاجرتِ امن و آزمونِ کامل می‌خواهد
 *      (چک‌لیستِ «افزودنِ نوعِ سوم» در `docs/COMPLEX_TYPES.md`)؛
 *   ② جاهایی که فهرستِ نوع‌ها تکرار شده (رابط و آمار) با `ComplexType` هم‌خوان بمانند، تا روزی که نوعِ تازه‌ای
 *      تصویب شد هیچ جایش جا نماند.
 */
class ComplexTypeScopeTest extends TestCase
{
    use RefreshDatabase;

    private const DECISION = 'دامنه‌ی نوعِ مجتمع فقط مسکونی و تجاری است (تصمیمِ ۲۰۲۶-۱۰-۲۶). نوعِ تازه Specification ِ مستقل و چک‌لیستِ docs/COMPLEX_TYPES.md را می‌خواهد.';

    public function test_only_residential_and_commercial_are_in_scope(): void
    {
        $this->assertSame(['residential', 'commercial'], ComplexType::values(), self::DECISION);
    }

    /** Mixed-Use نه در کد هست، نه در رابط، نه در مهاجرت یا seeder */
    public function test_no_mixed_use_type_exists_anywhere_in_the_product(): void
    {
        $hits = [];
        foreach (['app', 'database', 'resources/js', 'routes', 'config'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                if (preg_match('/[\'"](mixed[_-]?use|mixed)[\'"]/i', $file->getContents())) {
                    $hits[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $hits, self::DECISION);
    }

    /** هر جای رابط که یک نوع را نام می‌برد همه‌ی نوع‌ها را نام می‌برد — هیچ فهرستِ نیمه‌کاره‌ای نمی‌ماند */
    public function test_every_frontend_list_of_types_matches_the_enum(): void
    {
        $files = 0;
        foreach (File::allFiles(resource_path('js')) as $file) {
            $source = $file->getContents();
            if (str_contains($file->getFilename(), '.test.') || ! preg_match_all("/'(residential|commercial)'/", $source, $m)) {
                continue;
            }

            $files++;
            $this->assertEqualsCanonicalizing(ComplexType::values(), array_values(array_unique($m[1])),
                "«{$file->getRelativePathname()}» فقط بخشی از نوع‌های مجتمع را نام می‌برد.");
        }

        $this->assertGreaterThan(0, $files);
    }

    /** واژگان و آمار برای هر نوع تعریف شده‌اند: `match` ِ بی شاخه در نوعِ تازه به‌جای بی‌صدا ماندن می‌شکند */
    public function test_every_type_has_its_vocabulary_and_its_platform_stat(): void
    {
        foreach (ComplexType::cases() as $type) {
            $this->assertNotSame('', $type->label());
            $this->assertNotSame('', $type->occupantLabel());
            $this->assertNotSame('', $type->occupantPluralLabel());
            $this->assertNotSame('', $type->unitLabel());
        }

        $admin = User::factory()->create(['complex_id' => null, 'role' => UserRole::SuperAdmin, 'is_active' => true]);
        $complexes = $this->actingAs($admin)->getJson('/api/v1/system/stats')->assertOk()->json('complexes');
        foreach (ComplexType::values() as $value) {
            $this->assertArrayHasKey($value, $complexes, "آمارِ پلتفرم شمارِ نوعِ «{$value}» را ندارد.");
        }
    }
}
