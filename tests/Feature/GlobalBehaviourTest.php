<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * رفتارهای سراسری، سمتِ سرور (مرحلهٔ ۷ · فاز ۱).
 *
 * ─── سه چیز ────────────────────────────────────────────────────────────────
 * ① تم: هر سندِ HTMLِ سامانه تم را از **یک** اسکریپتِ درون‌خطی می‌گیرد که
 *    پیش‌فرضش روشن است — نه از سیستم‌عامل.
 * ② تاریخچه: کاربرِ واردشده‌ای که با «بازگشت» یا آدرسِ مستقیم به صفحه‌ی ورود
 *    برسد، سمتِ سرور به داشبورد می‌رود؛ و صفحه‌ی ورود در bfcache نمی‌ماند.
 * ③ ۴۰۴ِ سرورساید فارسی است و راهِ خروج دارد، نه قالبِ انگلیسیِ لاراول.
 *
 * بخشِ سمتِ کاربرِ همین‌ها در `tests/js/globalBehaviour.test.tsx` و آزمونِ
 * مرورگرِ `critical-flows` است.
 */
class GlobalBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private const THEME_READ = "localStorage.getItem('sakena:theme') === 'dark'";

    // ─── تم ──────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function htmlDocuments(): array
    {
        return [
            'صفحه‌ی اصلی' => ['/'],
            'ورود' => ['/auth'],
            'پوسته‌ی داشبورد' => ['/dashboard'],
            'آفلاین' => ['/offline'],
            'بلاگ' => ['/blog'],
            '۴۰۴' => ['/blog/no-such-post'],
        ];
    }

    #[DataProvider('htmlDocuments')]
    public function test_every_document_reads_the_theme_from_the_one_script(string $path): void
    {
        $html = $this->get($path)->getContent();

        $this->assertStringContainsString(self::THEME_READ, (string) $html);
        $this->assertStringNotContainsString('prefers-color-scheme', (string) $html);
    }

    /**
     * ⚠️ اسکریپتِ تم بی‌nonce را CSP می‌بندد و صفحه‌ی کاربرِ «تیره» روشن
     * می‌ماند — بی‌هیچ خطای دیدنی‌ای. همین سرنوشت را اسکریپتِ «تلاش دوباره»ِ
     * صفحه‌ی آفلاین داشت و دکمه‌اش هیچ کاری نمی‌کرد.
     */
    public function test_every_inline_script_on_the_offline_page_carries_the_nonce(): void
    {
        $response = $this->get('/offline');
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);

        preg_match_all('/<script\b(?![^>]*type="application\/ld\+json")[^>]*>/', (string) $response->getContent(), $tags);

        $this->assertNotEmpty($tags[0]);
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce[1].'"', $tag);
        }
    }

    public function test_the_browser_bar_colour_is_one_tag_the_script_can_follow(): void
    {
        $html = (string) $this->get('/')->getContent();

        // ⚠️ پیش از این دو تگ با `media` بود که از تمِ سیستم‌عامل پیروی می‌کردند
        $this->assertSame(1, substr_count($html, '<meta name="theme-color"'));
        // تگ پیش از اسکریپت است، وگرنه اسکریپت پیدایش نمی‌کند
        $this->assertLessThan(strpos($html, self::THEME_READ), strpos($html, '<meta name="theme-color"'));
    }

    // ─── تاریخچه‌ی مرورگر ────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function authPages(): array
    {
        return [
            'ورود' => ['/auth'],
            'کدِ تأیید' => ['/auth/verify'],
            // «فراموشیِ رمز» دیگر نیست (۲۰۲۶-۱۰-۳۰)؛ درِ مدیران جایش
            'مدیران' => ['/auth/admin'],
        ];
    }

    #[DataProvider('authPages')]
    public function test_a_signed_in_user_never_sees_an_auth_page(string $path): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get($path)->assertRedirect('/dashboard');
    }

    #[DataProvider('authPages')]
    public function test_auth_pages_are_never_kept_for_back_navigation(string $path): void
    {
        $response = $this->get($path)->assertOk();

        // ⚠️ نسخه‌ی bfcache پیش از ورود ساخته شده و هنوز کاربر را مهمان می‌داند
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ─── ۴۰۴ ─────────────────────────────────────────────────────────────

    public function test_a_missing_page_answers_in_persian_with_a_way_out(): void
    {
        $response = $this->get('/blog/no-such-post')->assertNotFound();

        $response->assertSee('این صفحه پیدا نشد');
        $response->assertSee('lang="fa"', false);
        $response->assertSee('<meta name="robots" content="noindex">', false);
        $response->assertSee('href="/"', false);
        $response->assertSee('href="/blog"', false);
        $response->assertDontSee('Not Found');
    }

    public function test_the_api_still_answers_missing_routes_in_json(): void
    {
        $this->getJson('/api/no-such-endpoint')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
