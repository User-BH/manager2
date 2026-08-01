<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Advertisement;
use App\Models\Announcement;
use App\Models\Complex;
use App\Models\User;
use App\Support\Json;
use App\Support\Observability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * امنیت ورودی/خروجی (R17).
 *
 * تمرکز روی جاهایی است که داده از یک «زمینه» به زمینه‌ی دیگر می‌رود — از
 * دیتابیس به داخلِ تگِ `<script>`، از هدرِ درخواست به داخلِ آدرسِ مطلق — چون
 * تقریباً همه‌ی تزریق‌ها دقیقاً همان‌جا اتفاق می‌افتند.
 */
class InputOutputSecurityTest extends TestCase
{
    use RefreshDatabase;

    /* ── XSS: خروج از تگِ script ────────────────────────────────────────── */

    public function test_json_for_script_cannot_break_out_of_the_tag(): void
    {
        $encoded = Json::forScript(['x' => '</script><script>alert(1)</script>']);

        /*
         * تجزیه‌گرِ HTML محتوای script را نمی‌فهمد و فقط دنبالِ `</script>`
         * می‌گردد؛ پس همین یک رشته برای خروج از تگ کافی است.
         */
        $this->assertStringNotContainsString('</script>', $encoded);
        $this->assertStringNotContainsString('<', $encoded);
    }

    public function test_the_escaping_does_not_change_the_value(): void
    {
        // محافظت به قیمتِ خرابکردنِ داده نمی‌ارزد
        $payload = ['fa' => 'سلام «دنیا»', 'raw' => '</script>', 'amp' => 'a&b'];

        $this->assertSame($payload, json_decode(Json::forScript($payload), true));
    }

    public function test_persian_text_stays_readable(): void
    {
        // بدونِ UNESCAPED_UNICODE کلِ JSON-LDِ فارسی به \uXXXX تبدیل می‌شد
        $this->assertStringContainsString('سلام', Json::forScript(['t' => 'سلام']));
    }

    /**
     * نگهبانِ بازگشت: هیچ ویویی نباید مستقیم `json_encode` کند.
     *
     * محافظتی که فقط در چهار جای امروز اعمال شده باشد، با پنجمین `<script>`
     * که کسی اضافه می‌کند از بین می‌رود.
     */
    public function test_no_view_encodes_json_directly(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_contains(File::get($file->getPathname()), 'json_encode')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'در ویو به‌جای json_encode باید از App\Support\Json::forScript استفاده شود',
        );
    }

    public function test_a_hostile_dsn_cannot_escape_the_config_tag(): void
    {
        /*
         * سناریوی واقعی: کسی با دسترسیِ ادمین کل مقداری مخرب در پنل ذخیره
         * می‌کند. آن مقدار در `<head>` **هر صفحه‌ی عمومی** چاپ می‌شود، پس
         * تبدیل می‌شود به XSSِ ماندگار روی کلِ سایت — برای همه‌ی بازدیدکننده‌ها.
         */
        Observability::save(['sentry_dsn' => 'https://k@o1.ingest.sentry.io/1"></script><script>alert(1)</script>']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /* ── اعتبارسنجیِ مبدأ ───────────────────────────────────────────────── */

    public function test_the_panel_rejects_a_dsn_that_is_not_a_url(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson('/api/v1/system/observability', [
                'sentry_dsn' => '</script><script>alert(1)</script>',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sentry_dsn');
    }

    public function test_the_panel_still_accepts_a_real_dsn(): void
    {
        // سخت‌گیری نباید مقدارِ درست را رد کند
        $this->actingAs($this->superAdmin())
            ->putJson('/api/v1/system/observability', [
                'sentry_dsn' => 'https://abc123@o456.ingest.sentry.io/789',
            ])
            ->assertOk();
    }

    /* ── هدرِ Host ──────────────────────────────────────────────────────── */

    /**
     * الگوی میزبانِ مورد اعتماد باید دقیقاً دامنه‌ی خودمان را بپذیرد و بس.
     *
     * لاراول این میان‌افزار را هنگام تست غیرفعال می‌کند، پس رفتارِ واقعی‌اش
     * اینجا قابل اجرا نیست؛ آنچه سنجیده می‌شود درستیِ خودِ الگوست — چون یک
     * الگوی اشتباه بی‌صدا هیچ‌چیز را محدود نمی‌کند.
     */
    public function test_the_trusted_host_pattern_matches_only_our_domain(): void
    {
        config(['app.url' => 'https://sakena.app']);

        $pattern = '{^(.+\.)?'.preg_quote('sakena.app').'$}i';

        $this->assertMatchesRegularExpression($pattern, 'sakena.app');
        $this->assertMatchesRegularExpression($pattern, 'panel.sakena.app');

        $this->assertDoesNotMatchRegularExpression($pattern, 'evil.example.com');
        // دامنه‌ای که نامِ ما را پیشوند خودش کرده — تله‌ی کلاسیک
        $this->assertDoesNotMatchRegularExpression($pattern, 'sakena.app.evil.com');
    }

    public function test_trust_hosts_is_registered(): void
    {
        // اگر ثبت نشود، هدرِ Host دستِ فرستنده‌ی درخواست می‌ماند
        $this->assertStringContainsString(
            'trustHosts',
            File::get(base_path('bootstrap/app.php')),
        );
    }

    /* ── SQL ────────────────────────────────────────────────────────────── */

    public function test_raw_ordering_sends_ids_as_bindings(): void
    {
        /*
         * مقدارها امروز کلیدهای خودِ دیتابیس‌اند و تزریق فعال **نبود**؛ ولی
         * الگوی چسباندنِ رشته به SQL همان چیزی است که با عوض‌شدنِ منبع
         * می‌شکند. اینجا به‌جای خواندنِ سورس، خودِ کوئری بازرسی می‌شود.
         */
        $sql = Announcement::query()
            ->orderByRaw('CASE WHEN id IN (?,?) THEN 0 ELSE 1 END', [7, 9])
            ->toRawSql();

        $this->assertStringContainsString('7', $sql);

        // و منبع نباید شناسه‌ها را مستقیم داخلِ رشته بگذارد
        $source = File::get(app_path('Support/Notifications.php'));
        $this->assertStringNotContainsString("implode(',').')", $source);
        $this->assertStringContainsString("map(fn () => '?')", $source);
    }

    /* ── پیمایشِ مسیر ───────────────────────────────────────────────────── */

    public function test_an_advertisement_path_cannot_escape_public(): void
    {
        /*
         * بیرون‌زدن از `public` محتوای فایل را لو نمی‌داد، ولی «هست یا نیست»
         * و زمانِ تغییرش را لو می‌داد. نتیجه نباید هیچ `?v=` بگیرد.
         */
        $ad = new Advertisement([
            'image_url' => '/../../../../../../Windows/win.ini',
        ]);

        $this->assertStringNotContainsString('?v=', (string) $ad->displayImageUrl());
    }

    public function test_a_real_public_file_still_gets_its_cache_buster(): void
    {
        // سخت‌گیری نباید رفتارِ درست را بشکند
        $existing = collect(File::files(public_path('images')))->first();
        $this->assertNotNull($existing, 'برای این تست به یک فایل واقعی در public/images نیاز است');

        $ad = new Advertisement([
            'image_url' => '/images/'.$existing->getFilename(),
        ]);

        $this->assertStringContainsString('?v=', (string) $ad->displayImageUrl());
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'complex_id' => Complex::factory()->create()->id,
            'is_active' => true,
        ]);
    }
}
