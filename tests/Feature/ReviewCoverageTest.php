<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * پوششِ کنترلرهایی که در بازبینیِ نهایی بی‌تست بودند (R48).
 *
 * ─── چطور پیدا شدند ────────────────────────────────────────────────────────
 * ⚠️ اولین سنجشِ من غلط بود: نامِ کلاسِ کنترلر را در متنِ تست‌ها جستجو کردم
 * و نتیجه گرفتم **۴۵ از ۵۱** کنترلر بی‌تست‌اند. ولی تست‌ها کنترلر را از راهِ
 * **مسیر** صدا می‌زنند (`getJson('/api/v1/bills')`)، نه با نامِ کلاس.
 *
 * سنجشِ درست: هر مسیر به کنترلرش نگاشت شد و بعد دیده شد که آیا مسیرش در
 * هیچ تستی زده می‌شود. عددِ واقعی **۵ از ۴۶** بود.
 *
 * ─── چرا همین پنج‌تا مهم بودند ──────────────────────────────────────────────
 * سه‌تایشان عملیاتِ پرخطر دارند: `plans/grant` اشتراک به کسی می‌بخشد،
 * `site-settings` محتوای عمومیِ سایت را عوض می‌کند، و `web-vitals` مسیرِ
 * **نوشتنیِ بدونِ احراز هویت** است.
 *
 * این تست‌ها عمداً CRUDِ کامل نیستند؛ روی مرزِ **مجوز** و **اعتبارسنجی**
 * تمرکز دارند — همان جایی که نبودِ تست واقعاً خطرناک است.
 */
class ReviewCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role->value,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  پلن‌های اشتراک — پرخطرترینِ این گروه
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ `plans/grant` به یک مجتمع اشتراک **می‌بخشد** — یعنی پول.
     *
     * بی‌تست‌بودنش یعنی اگر روزی نگهبانِ نقش از این مسیر بیفتد، هیچ چیزی
     * جلویش را نمی‌گیرد و هر کاربرِ واردشده‌ای می‌تواند برای خودش اشتراک
     * فعال کند.
     */
    public function test_only_a_super_admin_can_grant_a_plan(): void
    {
        $complex = Complex::factory()->create();

        $this->postJson('/api/v1/system/plans/grant', ['complex_id' => $complex->id])
            ->assertStatus(401);

        foreach ([UserRole::Tenant, UserRole::Owner, UserRole::ComplexAdmin] as $role) {
            $this->actingAs($this->user($role))
                ->postJson('/api/v1/system/plans/grant', ['complex_id' => $complex->id])
                ->assertForbidden();
        }
    }

    /** فهرستِ پلن‌ها هم فقط برای سوپرادمین است. */
    public function test_the_plan_list_is_closed_to_everyone_else(): void
    {
        $this->getJson('/api/v1/system/plans')->assertStatus(401);

        $this->actingAs($this->user(UserRole::ComplexAdmin))
            ->getJson('/api/v1/system/plans')
            ->assertForbidden();

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->getJson('/api/v1/system/plans')
            ->assertOk();
    }

    /**
     * ⚠️ حذف و تغییرِ پلن هم باید بسته باشد.
     *
     * حذفِ پلنی که مجتمع‌ها رویش اشتراک دارند، عملیاتی است که برگشتش
     * ساده نیست؛ نبودنِ تست روی مسیرهای مخرب بدترین حالتِ نبودِ تست است.
     */
    public function test_destructive_plan_routes_are_closed(): void
    {
        $admin = $this->user(UserRole::ComplexAdmin);

        $this->actingAs($admin)->deleteJson('/api/v1/system/plans/1')->assertForbidden();
        $this->actingAs($admin)->putJson('/api/v1/system/plans/1', [])->assertForbidden();
        $this->actingAs($admin)->patchJson('/api/v1/system/plans/1/toggle')->assertForbidden();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  تنظیماتِ سایت
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ نوشتنِ تنظیماتِ سایت باید فقط کارِ سوپرادمین باشد.
     *
     * این تنظیمات محتوای **صفحه‌ی عمومی** را عوض می‌کنند؛ دسترسیِ باز
     * یعنی هر کاربری بتواند شماره‌ی تماس یا لینک‌های سایت را جای دیگری
     * ببرد.
     */
    public function test_only_a_super_admin_can_change_site_settings(): void
    {
        $this->putJson('/api/v1/system/site-settings', [])->assertStatus(401);

        $this->actingAs($this->user(UserRole::ComplexAdmin))
            ->putJson('/api/v1/system/site-settings', [])
            ->assertForbidden();

        $this->actingAs($this->user(UserRole::SuperAdmin))
            ->getJson('/api/v1/system/site-settings')
            ->assertOk();
    }

    /**
     * ⚠️ نسخه‌ی عمومیِ همان تنظیمات نباید چیزی بیش از محتوای فوتر بدهد.
     *
     * ─── چرا این تست ──────────────────────────────────────────────────────
     * `/site-settings` بدونِ احراز هویت باز است چون صفحه‌ی فرود پیش از
     * ورودِ کاربر رندر می‌شود. اگر روزی کسی همان منبعِ داده‌ی پنلِ ادمین را
     * به این مسیر وصل کند، کلیدهای درگاه و پیامک عمومی می‌شوند.
     */
    public function test_the_public_site_settings_leak_nothing_sensitive(): void
    {
        $body = (string) $this->getJson('/api/v1/site-settings')->assertOk()->getContent();

        foreach ([
            'sms_config', 'gateway_config', 'api_key', 'password', 'secret',
            'terminal', 'merchant', 'token',
        ] as $sensitive) {
            $this->assertStringNotContainsString(
                $sensitive,
                strtolower($body),
                "مسیرِ عمومیِ تنظیماتِ سایت «{$sensitive}» را بیرون می‌دهد.",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  خوش‌حساب‌ها
    // ─────────────────────────────────────────────────────────────────────

    /** فهرستِ خوش‌حساب‌ها داده‌ی مالیِ ساکنین است و باید پشتِ احراز هویت بماند. */
    public function test_the_good_payer_list_needs_authentication(): void
    {
        $this->getJson('/api/v1/good-payers')->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  سنجه‌های کارایی — تنها مسیرِ نوشتنیِ بدونِ احراز هویت در این گروه
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مسیرِ نوشتنیِ باز باید ورودی را سخت‌گیرانه اعتبارسنجی کند.
     *
     * ─── چرا این مهم‌ترین تستِ این فایل است ──────────────────────────────────
     * `web-vitals` تنها مسیرِ این گروه است که هم **بدونِ احراز هویت** است و
     * هم در دیتابیس **می‌نویسد**. یعنی هر کسی روی اینترنت می‌تواند صدایش
     * بزند.
     *
     * بدونِ اعتبارسنجیِ سخت، همان مسیر تبدیل می‌شود به راهی برای پرکردنِ
     * جدول با ردیفِ بی‌معنی — و چون جدولِ سنجه‌ها هدفِ طبیعیِ کسی نیست،
     * ماه‌ها کسی نمی‌فهمد.
     */
    public function test_the_open_metrics_endpoint_rejects_junk(): void
    {
        $this->postJson('/api/v1/web-vitals', [])->assertStatus(422);

        // نامِ سنجه‌ی ناشناخته
        $this->postJson('/api/v1/web-vitals', [
            'metrics' => [['name' => 'NOT_A_REAL_METRIC', 'value' => 1, 'rating' => 'good']],
            'path' => '/',
            'device' => 'desktop',
        ])->assertStatus(422);

        /*
         * ⚠️ مقدارِ نجومی هم رد می‌شود.
         *
         * بدونِ سقف، یک درخواستِ ساده می‌توانست عددی بنویسد که میانگین‌های
         * پنل را برای همیشه بی‌معنی کند — دست‌کاریِ داده بدونِ نیاز به هیچ
         * دسترسی‌ای.
         */
        $this->postJson('/api/v1/web-vitals', [
            'metrics' => [['name' => 'LCP', 'value' => 999999999, 'rating' => 'good']],
            'path' => '/',
            'device' => 'desktop',
        ])->assertStatus(422);

        $this->assertDatabaseCount('web_vitals', 0);
    }

    /**
     * و ورودیِ درست باید پذیرفته شود، وگرنه سنجه‌ای جمع نمی‌شود.
     *
     * ⚠️ شکلِ ورودی را اول **حدس زدم** (`name`/`value` تخت) و تست شکست.
     * قرارداد در `StoreWebVitalsRequest` آرایه‌ی `metrics` است. حدس‌زدنِ
     * قرارداد به‌جای خواندنش، همان‌قدر تستِ بی‌ارزش می‌سازد که سنجیدنِ
     * پاسخِ اشتباه.
     */
    public function test_the_open_metrics_endpoint_accepts_a_valid_sample(): void
    {
        $this->postJson('/api/v1/web-vitals', [
            'metrics' => [
                ['name' => 'LCP', 'value' => 1234.5, 'rating' => 'good'],
                ['name' => 'CLS', 'value' => 0.05, 'rating' => 'good'],
            ],
            'path' => '/',
            'device' => 'desktop',
        ])->assertSuccessful();

        $this->assertDatabaseCount('web_vitals', 2);
    }
}
