<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Complex;
use App\Models\User;
use App\Services\Blog\BlogWriter;
use App\Services\Features\FeatureFlags;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بلاگ — از نوشتن تا خواندنِ عمومی (مرحلهٔ ۶ · فاز ۱۱).
 *
 * صفحه‌ها Blade‌اند، پس ادعاها روی **HTMLِ واقعیِ سرور** است — همان چیزی که
 * خزنده و خواننده‌ی بی‌جاوااسکریپت می‌بینند.
 */
class BlogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'نویسنده‌ی سامانه', 'phone' => '09120000061',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function article(array $overrides = []): BlogPost
    {
        return app(BlogWriter::class)->save(null, [
            'title' => 'راهنمای محاسبه‌ی شارژ ساختمان',
            'slug' => null,
            'excerpt' => 'چطور شارژ را منصفانه تقسیم کنیم.',
            'body' => "## روش متراژی\n\nمتنِ بخشِ اول.\n\n## روش نفری\n\nمتنِ بخشِ دوم.",
            'status' => BlogPost::PUBLISHED,
            'published_at' => now()->subHour()->toDateTimeString(),
            ...$overrides,
        ], $this->admin);
    }

    /* ─────────────────────────── دیده‌شدن ─────────────────────────── */

    public function test_a_published_post_is_served_as_real_html(): void
    {
        $post = $this->article();

        $this->get('/blog/'.rawurlencode($post->slug))
            ->assertOk()
            ->assertSee('<h1>راهنمای محاسبه‌ی شارژ ساختمان</h1>', false)
            ->assertSee('متنِ بخشِ اول.')
            ->assertHeaderMissing('X-Robots-Tag');
    }

    /** 🔴 پیش‌نویس با حدسِ آدرس خوانده نمی‌شود — و ۴۰۴، نه ۴۰۳، تا وجودش لو نرود. */
    public function test_a_draft_is_invisible_to_the_public(): void
    {
        $post = $this->article(['status' => BlogPost::DRAFT, 'published_at' => null]);

        $this->get('/blog/'.rawurlencode($post->slug))->assertNotFound();
        $this->get('/blog')->assertDontSee($post->title);
    }

    /**
     * 🔴 مقاله‌ای که منتشر شده بود و به پیش‌نویس برگشت، ناپدید می‌شود —
     * هرچند تاریخِ انتشارِ قدیمی‌اش هنوز در ردیف هست.
     *
     * ⚠️ خرابکاری نشان داد تستِ پیش‌نویسِ بی‌تاریخ این را نمی‌دید: قاعده‌ی
     * «وضعیت» را برداشتم و آن تست سبز ماند، چون تاریخِ تهی خودش فیلتر می‌کرد.
     */
    public function test_an_unpublished_post_disappears_despite_its_old_date(): void
    {
        $post = $this->article();
        $this->get('/blog/'.rawurlencode($post->slug))->assertOk();

        app(BlogWriter::class)->save($post, [
            'title' => $post->title, 'slug' => $post->slug, 'body' => $post->body,
            'status' => BlogPost::DRAFT, 'published_at' => $post->published_at?->toDateTimeString(),
        ], $this->admin);

        $this->assertNotNull($post->fresh()->published_at);
        $this->get('/blog/'.rawurlencode($post->slug))->assertNotFound();
        $this->get('/blog')->assertDontSee($post->title);
        $this->get('/sitemap.xml')->assertDontSee(rawurlencode($post->slug), false);
    }

    /** 🔴 «منتشرشده» برای فردا هنوز منتشر نشده است. */
    public function test_a_scheduled_post_waits_for_its_time(): void
    {
        $post = $this->article(['published_at' => now()->addDay()->toDateTimeString()]);

        $this->get('/blog/'.rawurlencode($post->slug))->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee(rawurlencode($post->slug), false);

        $this->travel(2)->days();
        $this->get('/blog/'.rawurlencode($post->slug))->assertOk();
    }

    public function test_the_super_admin_previews_a_draft_that_is_never_indexed(): void
    {
        $post = $this->article(['status' => BlogPost::DRAFT, 'published_at' => null]);

        $this->actingAs($this->admin)
            ->get('/blog/'.rawurlencode($post->slug))
            ->assertOk()
            ->assertSee('پیش‌نمایشِ پیش‌نویس')
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /* ─────────────────────────── امنیتِ محتوا ─────────────────────────── */

    /** 🔴 مرزِ امنیتی: هر چیزی در متن، متن می‌ماند. */
    public function test_raw_html_and_dangerous_links_never_execute(): void
    {
        $post = $this->article(['body' => "سلام <script>alert(1)</script> <img src=x onerror=alert(2)>\n\n[کلیک](javascript:alert(3))"]);

        $html = $this->get('/blog/'.rawurlencode($post->slug))->assertOk()->getContent();
        $prose = substr($html, strpos($html, 'class="blog-prose"'));

        $this->assertStringNotContainsString('<script>alert(1)', $prose);
        $this->assertStringNotContainsString('onerror=alert', str_replace('&lt;', '', $prose) === $prose ? $prose : '');
        $this->assertStringContainsString('&lt;script&gt;', $prose);
        $this->assertStringNotContainsString('javascript:', $prose);
    }

    public function test_external_links_and_images_are_handled_safely(): void
    {
        $post = $this->article(['body' => "[منبع](https://example.com/x)\n\n![ردیاب](https://tracker.test/p.png)\n\n![خودمان](/blog/media/abc.webp)"]);

        $html = $this->get('/blog/'.rawurlencode($post->slug))->getContent();

        $this->assertMatchesRegularExpression('/rel="nofollow noopener noreferrer" target="_blank" href="https:\/\/example.com\/x"/', $html);
        $this->assertStringNotContainsString('tracker.test', $html, '🔴 تصویرِ بیرونی (ردیاب) در صفحه ماند.');
        $this->assertStringContainsString('src="/blog/media/abc.webp"', $html);
    }

    /* ─────────────────────────── فهرستِ مطالب و SEO ─────────────────────────── */

    public function test_the_table_of_contents_points_at_real_headings(): void
    {
        $post = $this->article();
        $html = $this->get('/blog/'.rawurlencode($post->slug))->getContent();

        $this->assertSame(['روش متراژی', 'روش نفری'], array_column($post->toc, 'text'));

        foreach ($post->toc as $entry) {
            $this->assertStringContainsString('href="#'.$entry['id'].'"', $html);
            $this->assertStringContainsString('<h2 id="'.$entry['id'].'">', $html, 'پیوندِ فهرست به عنوانی بی‌شناسه اشاره می‌کند.');
        }
    }

    /**
     * JSON-LD و مسیرِ راهنما یکی‌اند؛ داده‌ی ساخت‌یافته‌ای که چیزی جز صفحه
     * بگوید از دیدِ گوگل تخلف است.
     */
    public function test_structured_data_matches_the_visible_page(): void
    {
        $category = BlogCategory::create(['name' => 'مالی', 'slug' => 'مالی']);
        $post = $this->article(['category_id' => $category->id]);

        $html = $this->get('/blog/'.rawurlencode($post->slug))->getContent();

        $this->assertStringContainsString('"@type":"BlogPosting"', $html);
        $this->assertStringContainsString('"headline":"راهنمای محاسبه‌ی شارژ ساختمان"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('<meta property="article:section" content="مالی">', $html);

        // مسیرِ دیدنی: خانه › بلاگ › مالی › مقاله
        preg_match('#<nav aria-label="مسیر راهنما".*?</nav>#s', $html, $nav);
        $this->assertStringContainsString('>بلاگ</a>', $nav[0]);
        $this->assertStringContainsString('>مالی</a>', $nav[0]);
        $this->assertStringContainsString('aria-current="page">راهنمای محاسبه‌ی شارژ ساختمان', $nav[0]);
    }

    /**
     * 🔴 آدرسِ متعارف از APP_URL است، نه از هدرِ Host (همان حمله‌ی فاز ۷).
     *
     * ⚠️ `withHeader('Host', …)` این‌جا **پوچ** بود: کلاینتِ تستِ لاراول
     * میزبان را از URI می‌گیرد (همان درسی که `SeoTest` ثبت کرده بود و نسخه‌ی
     * اولِ این تست تکرارش کرد — خرابکاری لو داد). آدرسِ کامل میزبان را واقعاً
     * عوض می‌کند و `evil.example.com` در خودِ درخواست سنجیده می‌شود.
     */
    public function test_the_canonical_url_ignores_a_forged_host(): void
    {
        $post = $this->article();

        $response = $this->get('http://evil.example.com/blog/'.rawurlencode($post->slug));
        $this->assertSame('evil.example.com', request()->getHost(), 'ابزارِ تست میزبان را عوض نکرد؛ این تست پوچ است.');

        $html = $response->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/blog/'.rawurlencode($post->slug).'">', $html);

        /*
         * ⚠️ فقط نشانی‌هایی که به موتورِ جستجو و پیام‌رسان می‌گویند «این صفحه
         * کجاست». آدرسِ فایل‌های `/build` را خودِ Vite از درخواست می‌سازد (در
         * همه‌ی صفحه‌ها، نه فقط بلاگ) و در محصول `trustHosts` میزبانِ ناشناس
         * را پیش از رسیدن به برنامه رد می‌کند — در تست آن میان‌افزار خاموش است.
         */
        preg_match_all('/<link rel="(?:canonical|prev|next)"[^>]*>|<meta property="og:url"[^>]*>|"(?:url|item|@id|mainEntityOfPage)":"[^"]*"|<nav aria-label="مسیر راهنما".*?<\/nav>/su', $html, $seo);
        $this->assertNotEmpty($seo[0]);
        $this->assertStringNotContainsString('evil.example.com', implode("\n", $seo[0]));
    }

    /* ─────────────────────────── فهرست، دسته، برچسب، جست‌وجو ─────────────────────────── */

    public function test_the_list_is_paginated_with_its_own_canonical_and_rel_links(): void
    {
        foreach (range(1, 11) as $i) {
            $this->article(['title' => "مقاله‌ی {$i}", 'published_at' => now()->subHours($i)->toDateTimeString()]);
        }

        $first = $this->get('/blog')->assertOk()->getContent();
        $this->assertStringContainsString('مقاله‌ی 1', $first);
        $this->assertStringNotContainsString('مقاله‌ی 11<', $first);
        $this->assertStringContainsString('<link rel="next" href="'.rtrim(config('app.url'), '/').'/blog?page=2">', $first);

        $second = $this->get('/blog?page=2')->assertOk()->getContent();
        $this->assertStringContainsString('مقاله‌ی 11', $second);
        $this->assertStringContainsString('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/blog?page=2">', $second);
        $this->assertStringContainsString('<link rel="prev"', $second);
    }

    public function test_category_and_tag_pages_show_only_their_posts(): void
    {
        $finance = BlogCategory::create(['name' => 'مالی', 'slug' => 'مالی']);
        $inCategory = $this->article(['title' => 'درباره‌ی صندوق', 'category_id' => $finance->id, 'tags' => ['حسابداری']]);
        $other = $this->article(['title' => 'درباره‌ی آسانسور', 'tags' => ['نگهداری']]);

        $this->get('/blog/category/'.rawurlencode('مالی'))
            ->assertOk()->assertSee($inCategory->title)->assertDontSee($other->title);

        $this->get('/blog/tag/'.rawurlencode('نگهداری'))
            ->assertOk()->assertSee($other->title)->assertDontSee($inCategory->title);

        $this->get('/blog/category/ناموجود')->assertNotFound();
    }

    /** «ي» و «ك»ِ عربی همان «ی» و «ک»ِ فارسی‌اند؛ نتیجه‌ی جست‌وجو ایندکس نمی‌شود. */
    public function test_search_understands_arabic_letters_and_is_not_indexed(): void
    {
        $this->article(['title' => 'نکته‌های نگهداری آسانسور', 'body' => 'سرویس دوره‌ای آسانسور یک ضرورت است.']);
        $this->article(['title' => 'پارکینگ مشاع']);

        $response = $this->get('/blog?q='.rawurlencode('دوره‌اي'))->assertOk();

        $response->assertSee('نکته‌های نگهداری آسانسور')
            ->assertDontSee('پارکینگ مشاع')
            ->assertSee('<meta name="robots" content="noindex,follow">', false);

        // نویسه‌ی ویژه‌ی LIKE همه‌چیز را برنمی‌گرداند
        $this->get('/blog?q=%25')->assertOk()->assertSee('مقاله‌ای با این عبارت پیدا نشد');
    }

    /* ─────────────────────────── نقشه‌ی سایت و پرچم ─────────────────────────── */

    public function test_the_sitemap_lists_only_visible_posts(): void
    {
        $visible = $this->article();
        $draft = $this->article(['title' => 'پیش‌نویسِ مخفی', 'status' => BlogPost::DRAFT, 'published_at' => null]);

        $this->get('/sitemap.xml')
            ->assertSee('/blog/'.rawurlencode($visible->slug), false)
            ->assertDontSee(rawurlencode($draft->slug), false);

        $this->get('/llms.txt')->assertSee($visible->title);
    }

    public function test_turning_the_flag_off_hides_the_whole_blog(): void
    {
        $post = $this->article();
        app(FeatureFlags::class)->set('blog', false);

        $this->get('/blog')->assertNotFound();
        $this->get('/blog/'.rawurlencode($post->slug))->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/blog', false);
        $this->get('/')->assertDontSee('name="blog-enabled"', false);
    }

    /* ─────────────────────────── نوشتن ─────────────────────────── */

    public function test_only_the_super_admin_writes(): void
    {
        $complex = Complex::create(['type' => 'residential', 'name' => 'مجتمع', 'slug' => 'blog-'.uniqid(),
            'currency' => 'toman', 'charge_due_day' => 10, 'payment_gateway' => 'none']);
        $manager = User::create([
            'complex_id' => $complex->id, 'name' => 'مدیر', 'phone' => '09120000062',
            'role' => UserRole::ComplexAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);

        $payload = ['title' => 'x', 'body' => 'y', 'status' => 'draft'];

        $this->postJson('/api/v1/system/blog/posts', $payload)->assertUnauthorized();
        $this->actingAs($manager)->postJson('/api/v1/system/blog/posts', $payload)->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/v1/system/blog/posts', $payload)->assertCreated();
    }

    /** نامکِ فارسی از عنوان، یکتا، و هنگامِ ویرایش ثابت. */
    public function test_slugs_are_persian_unique_and_stable(): void
    {
        $this->actingAs($this->admin);

        $first = $this->postJson('/api/v1/system/blog/posts', [
            'title' => 'شارژ ساختمان ۱۴۰۵', 'body' => 'متن', 'status' => 'draft',
        ])->assertCreated()->json('slug');

        $second = $this->postJson('/api/v1/system/blog/posts', [
            'title' => 'شارژ ساختمان ۱۴۰۵', 'body' => 'متن', 'status' => 'draft',
        ])->json('slug');

        $this->assertSame('شارژ-ساختمان-۱۴۰۵', $first);
        $this->assertSame('شارژ-ساختمان-۱۴۰۵-2', $second);

        $post = BlogPost::where('slug', $first)->firstOrFail();
        $this->putJson("/api/v1/system/blog/posts/{$post->id}", [
            'title' => 'عنوانِ تازه', 'slug' => $first, 'body' => 'متن', 'status' => 'draft',
        ])->assertOk();

        $this->assertSame($first, $post->fresh()->slug);
    }

    /** 🔴 ویرایش بلافاصله دیده می‌شود؛ کش صفحه‌ی کهنه نشان نمی‌دهد. */
    public function test_an_edit_is_visible_immediately_despite_the_cache(): void
    {
        $post = $this->article();
        $this->get('/blog/'.rawurlencode($post->slug))->assertSee('متنِ بخشِ اول.');
        $this->get('/blog')->assertSee($post->title);

        $this->actingAs($this->admin)->putJson("/api/v1/system/blog/posts/{$post->id}", [
            'title' => 'عنوانِ ویرایش‌شده', 'slug' => $post->slug, 'body' => 'متنِ تازه.', 'status' => 'published',
        ])->assertOk();

        auth()->logout();
        $this->get('/blog/'.rawurlencode($post->slug))->assertSee('متنِ تازه.')->assertDontSee('متنِ بخشِ اول.');
        $this->get('/blog')->assertSee('عنوانِ ویرایش‌شده');
    }

    /** خلاصه‌ی خودکار اولین بندِ متن است و نیم‌فاصله‌اش را نگه می‌دارد. */
    public function test_the_automatic_excerpt_is_the_first_paragraph_as_written(): void
    {
        $this->article(['excerpt' => null, 'body' => "## بخش\n\nشارژ هر ماه تقسیم می‌شود.\n\nبندِ دوم."]);

        $this->get('/blog')
            ->assertSee('شارژ هر ماه تقسیم می‌شود.')
            ->assertDontSee('بندِ دوم.');
    }

    public function test_reading_time_follows_the_length(): void
    {
        $short = $this->article(['body' => 'چند واژه.']);
        $long = $this->article(['title' => 'بلند', 'body' => str_repeat('واژه ', 1000)]);

        $this->assertSame(1, $short->reading_minutes);
        $this->assertSame(5, $long->reading_minutes);
    }

    /* ─────────────────────────── تصویر ─────────────────────────── */

    public function test_an_uploaded_image_is_served_and_nothing_else_is(): void
    {
        Storage::fake(PrivateFiles::name());

        $response = $this->actingAs($this->admin)
            ->post('/api/v1/system/blog/images', ['image' => UploadedFile::fake()->image('cover.png', 800, 450)], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->get($response->json('url'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        // فقط نامِ تولیدشده؛ مسیرِ دلخواه یا فایلِ غیرتصویری نه
        $this->get('/blog/media/..%2F..%2F.env')->assertNotFound();
        $this->get('/blog/media/'.str_repeat('a', 40).'.php')->assertNotFound();
        $this->actingAs($this->admin)
            ->post('/api/v1/system/blog/images', ['image' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }
}
