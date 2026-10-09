<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use App\Services\Blog\BlogWriter;
use App\Services\Blog\PublicBlog;
use App\Services\Features\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * API ِ عمومیِ بلاگ — ناوبریِ بی‌بارگذاریِ دوباره (مرحلهٔ ۷ · فاز ۷).
 *
 * خواسته‌ی ممیزی صریح بود: «صرفاً پیاده‌سازیِ Frontend و فیلتر کردنِ داده‌های
 * Mock قابلِ قبول نیست». پس صفحه‌بندی و جست‌وجو این‌جا از پایگاه‌داده سنجیده
 * می‌شوند، با بیش از ۲۰ مقاله، صفحه‌ی آخر، کمتر از ۱۰، ورودیِ نامعتبر و سقفِ نرخ.
 */
class BlogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'نویسنده‌ی پنهان', 'phone' => '09120000071',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function article(array $overrides = []): BlogPost
    {
        static $n = 0;
        $n++;

        return app(BlogWriter::class)->save(null, [
            'title' => "مقاله‌ی شماره {$n}",
            'slug' => null,
            'excerpt' => "خلاصه‌ی مقاله‌ی {$n}",
            'body' => "## بخش\n\nمتنِ مقاله‌ی {$n}.",
            'status' => BlogPost::PUBLISHED,
            'published_at' => now()->subDays($n)->toDateTimeString(),
            ...$overrides,
        ], $this->admin);
    }

    private function many(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->article();
        }
    }

    /* ─────────────────────────── صفحه‌بندی ─────────────────────────── */

    public function test_a_page_holds_ten_articles_and_the_rest_come_from_the_database(): void
    {
        $this->many(23);

        $first = $this->getJson('/api/blog')->assertOk()->assertJsonPath('data.perPage', 10);
        $this->assertCount(10, $first->json('data.items'));
        $this->assertSame(23, $first->json('data.total'));
        $this->assertSame(3, $first->json('data.lastPage'));

        $second = $this->getJson('/api/blog?page=2')->assertOk();
        $this->assertCount(10, $second->json('data.items'));
        $this->assertSame([], array_intersect(
            array_column($first->json('data.items'), 'id'),
            array_column($second->json('data.items'), 'id'),
        ), 'صفحه‌ی دوم تکرارِ صفحه‌ی اول است');

        // آخرین صفحه: باقی‌مانده، نه ده تا
        $last = $this->getJson('/api/blog?page=3')->assertOk();
        $this->assertCount(3, $last->json('data.items'));
        $this->assertSame('/blog?page=3', $last->json('meta.url'));
    }

    public function test_fewer_than_ten_articles_is_one_page_without_a_pager(): void
    {
        $this->many(4);

        $response = $this->getJson('/api/blog')->assertOk();

        $this->assertCount(4, $response->json('data.items'));
        $this->assertSame(1, $response->json('data.lastPage'));
        $this->assertStringNotContainsString('blog-pager', $response->json('html'));
    }

    public function test_a_page_past_the_end_is_clamped_and_the_url_says_so(): void
    {
        $this->many(12);

        $response = $this->getJson('/api/blog?page=9')->assertOk();

        $this->assertSame(2, $response->json('data.page'));
        $this->assertSame('/blog?page=2', $response->json('meta.url'));
    }

    public function test_newest_first(): void
    {
        $old = $this->article(['published_at' => now()->subYear()->toDateTimeString()]);
        $new = $this->article(['published_at' => now()->subDay()->toDateTimeString()]);

        $ids = array_column($this->getJson('/api/blog')->json('data.items'), 'id');

        $this->assertSame([$new->id, $old->id], $ids);
    }

    /* ─────────────────────────── جست‌وجو ─────────────────────────── */

    public function test_search_is_real_paginated_and_keeps_the_query_in_the_url(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->article(['title' => "شارژ ماهانه {$i}"]);
        }
        $this->many(5);

        $first = $this->getJson('/api/blog?q='.rawurlencode('شارژ'))->assertOk();
        $this->assertSame(12, $first->json('data.total'));
        $this->assertSame('شارژ', $first->json('data.search'));
        $this->assertSame('noindex,follow', $first->json('meta.robots'));

        $second = $this->getJson('/api/blog?q='.rawurlencode('شارژ').'&page=2')->assertOk();
        $this->assertCount(2, $second->json('data.items'));
        $this->assertSame('/blog?q='.rawurlencode('شارژ').'&page=2', $second->json('meta.url'));
    }

    public function test_search_with_no_result_says_so_and_suggests_another_word(): void
    {
        $this->many(3);

        $response = $this->getJson('/api/blog?q='.rawurlencode('واژه‌ای‌که‌نیست'))->assertOk();

        $this->assertSame(0, $response->json('data.total'));
        $this->assertStringContainsString('مقاله‌ای برای جست‌وجوی شما پیدا نشد', $response->json('html'));
    }

    public function test_extra_spaces_are_collapsed_and_empty_means_all_articles(): void
    {
        $this->article(['title' => 'پرداخت آنلاین شارژ']);
        $this->many(2);

        $this->getJson('/api/blog?q='.rawurlencode('   پرداخت    آنلاین  '))
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.search', 'پرداخت آنلاین');

        // فقط فاصله = همه‌ی مقاله‌ها، نه جست‌وجوی تهی
        $this->getJson('/api/blog?q='.rawurlencode('    '))
            ->assertOk()->assertJsonPath('data.total', 3)->assertJsonPath('data.search', null);
    }

    public function test_invalid_search_input_is_rejected_by_the_server(): void
    {
        $this->getJson('/api/blog?q=ش')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->getJson('/api/blog?q='.str_repeat('ش', 81))->assertStatus(422)->assertJsonValidationErrors('q');
        $this->getJson('/api/blog?q[]=x')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->getJson('/api/blog?page=0')->assertStatus(422)->assertJsonValidationErrors('page');
        $this->getJson('/api/blog?page=abc')->assertStatus(422)->assertJsonValidationErrors('page');
        $this->getJson('/api/blog?category='.rawurlencode('<script>'))->assertStatus(422);
    }

    public function test_like_wildcards_do_not_match_everything(): void
    {
        $this->many(3);

        // پس از برداشتنِ % و _ چیزی نمی‌ماند → هیچ، نه همه
        $this->getJson('/api/blog?q='.rawurlencode('%%'))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/blog?q='.rawurlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('data.total', 0);
    }

    /* ─────────────────────────── مقاله ─────────────────────────── */

    public function test_an_article_comes_with_its_html_meta_and_no_author(): void
    {
        $post = $this->article(['title' => 'راهنمای کیف پول', 'body' => "## بخش اول\n\n**نکته** مهم.\n\n## بخش دوم\n\nمتن."]);

        $response = $this->getJson('/api/blog/posts/'.rawurlencode($post->slug))->assertOk();

        $response->assertJsonPath('data.title', 'راهنمای کیف پول')
            ->assertJsonPath('meta.view', 'article')
            ->assertJsonPath('meta.url', '/blog/'.rawurlencode($post->slug));
        $this->assertStringContainsString('<strong>نکته</strong>', $response->json('html'));
        $this->assertStringContainsString('دقیقه مطالعه', $response->json('html'));

        // نامِ نویسنده هیچ‌جا — نه در داده، نه در HTML
        $this->assertArrayNotHasKey('author', $response->json('data'));
        // ⚠️ متنِ خامِ JSON فرارِ یونیکد دارد؛ بی رمزگشایی این ادعا همیشه سبز بود (یافته‌ی مرحلهٔ ۹ · فاز ۶)
        $this->assertStringNotContainsString('نویسنده‌ی پنهان', (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->get('/blog/'.rawurlencode($post->slug))->assertOk()->assertDontSee('نویسنده‌ی پنهان');
    }

    public function test_the_breadcrumb_comes_along_and_matches_the_server_rendered_page(): void
    {
        $post = $this->article(['title' => 'راهنمای کیف پول']);

        $crumbs = $this->getJson('/api/blog/posts/'.rawurlencode($post->slug))->assertOk()->json('meta.breadcrumbs');

        // بیرونِ `<main>` است؛ بی این، پس از جابه‌جاییِ بی‌بارگذاری مسیرِ فهرست می‌ماند
        $this->assertStringContainsString('<span aria-current="page">راهنمای کیف پول</span>', $crumbs);
        $this->assertStringContainsString('>بلاگ</a>', $crumbs);
        $this->assertStringContainsString('>خانه</a>', $crumbs);

        $page = $this->get('/blog/'.rawurlencode($post->slug))->assertOk()->getContent();
        $this->assertStringContainsString(trim($crumbs), $page);

        $listing = $this->getJson('/api/blog')->assertOk()->json('meta.breadcrumbs');
        $this->assertStringContainsString('<span aria-current="page">بلاگ</span>', $listing);
    }

    public function test_drafts_and_scheduled_posts_never_leave_through_the_api(): void
    {
        $draft = $this->article(['status' => BlogPost::DRAFT, 'published_at' => null]);
        $future = $this->article(['published_at' => now()->addWeek()->toDateTimeString()]);

        // حتی برای سوپرادمین: پیش‌نمایش فقط از صفحه است
        $this->actingAs($this->admin);
        $this->getJson('/api/blog/posts/'.rawurlencode($draft->slug))->assertNotFound();
        $this->getJson('/api/blog/posts/'.rawurlencode($future->slug))->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/blog')->json('data.total'));
    }

    public function test_unknown_article_category_or_tag_is_404(): void
    {
        $this->getJson('/api/blog/posts/'.rawurlencode('نیست'))->assertNotFound();
        $this->getJson('/api/blog?category=nist')->assertNotFound();
        $this->getJson('/api/blog?tag=nist')->assertNotFound();
    }

    public function test_related_articles_follow_the_topic_not_the_date(): void
    {
        $finance = BlogCategory::create(['name' => 'مالی', 'slug' => 'mali']);
        $other = BlogCategory::create(['name' => 'ارتباط', 'slug' => 'ertebat']);

        $post = $this->article(['category_id' => $finance->id, 'tags' => ['شارژ', 'قبض']]);
        $sameTags = $this->article(['category_id' => $other->id, 'tags' => ['شارژ', 'قبض'], 'published_at' => now()->subYear()->toDateTimeString()]);
        $sameCategory = $this->article(['category_id' => $finance->id, 'published_at' => now()->subMonths(6)->toDateTimeString()]);
        // تازه‌ترین، ولی بی‌ربط
        $this->article(['category_id' => $other->id, 'tags' => ['پیام‌رسان'], 'published_at' => now()->subMinute()->toDateTimeString()]);
        $this->article(['category_id' => $other->id, 'tags' => ['نظرسنجی'], 'published_at' => now()->subMinutes(2)->toDateTimeString()]);

        $related = app(PublicBlog::class)->related($post->id, $finance->id);

        $this->assertSame([$sameTags->id, $sameCategory->id], array_slice(array_column($related, 'id'), 0, 2));
    }

    /* ─────────────────────────── محافظت ─────────────────────────── */

    public function test_the_api_is_rate_limited(): void
    {
        RateLimiter::clear('blog:127.0.0.1');

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/blog')->assertOk();
        }

        $this->getJson('/api/blog')->assertStatus(429);
    }

    public function test_turning_the_blog_off_closes_the_api_too(): void
    {
        $post = $this->article();
        app(FeatureFlags::class)->set('blog', false);

        // میان‌افزارِ مشترکِ `feature:` قابلیتِ خاموش را ۴۰۳ می‌گوید، مثلِ بقیه‌ی APIها
        $this->getJson('/api/blog')->assertForbidden();
        $this->getJson('/api/blog/posts/'.rawurlencode($post->slug))->assertForbidden();
    }

    public function test_a_word_only_in_the_title_is_found_even_with_arabic_or_alef_madda(): void
    {
        $this->article(['title' => 'آموزشِ کیف‌پول', 'body' => '## بخش

متنِ بی‌ربط.']);

        // «آ» در عنوان، «ا» در جست‌وجو — و برعکس با «ي»ِ عربی
        $this->getJson('/api/blog?q='.rawurlencode('اموزش'))->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/blog?q='.rawurlencode('كيف'))->assertOk()->assertJsonPath('data.total', 1);
    }
}
