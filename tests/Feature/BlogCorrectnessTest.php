<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use App\Services\Blog\BlogDeleter;
use App\Services\Blog\BlogImporter;
use App\Services\Blog\BlogWriter;
use App\Services\Blog\PublicBlog;
use App\Services\Health\HealthReport;
use App\Support\PrivateFiles;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * درستیِ بلاگ (مرحلهٔ ۸ · فاز ۳): حذفِ کامل، ریشه‌ی تصویرهای ۴۰۴، ترتیبِ
 * قطعی، مرتبط‌های واقعی و آمادگی برای ده‌ها مقاله.
 */
class BlogCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const IMG_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp';

    private const IMG_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PrivateFiles::name());
        $this->admin = User::create([
            'name' => 'مدیر سیستم', 'phone' => '09120000081',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function article(array $overrides = []): BlogPost
    {
        static $n = 0;
        $n++;

        return app(BlogWriter::class)->save(null, [
            'title' => "مقاله‌ی آزمایشیِ {$n}",
            'slug' => null,
            'excerpt' => "خلاصه‌ی {$n}",
            'body' => "## بخش\n\nمتنِ {$n}.",
            'status' => BlogPost::PUBLISHED,
            'published_at' => now()->subDays($n)->toDateTimeString(),
            ...$overrides,
        ], $this->admin);
    }

    /* ───────────────────────── بندِ ۱۵: حذفِ کامل ───────────────────────── */

    public function test_deleting_removes_the_row_its_unused_images_and_tags_but_keeps_shared_ones(): void
    {
        $disk = PrivateFiles::disk();
        $disk->put('blog/'.self::IMG_A, 'a');
        $disk->put('blog/'.self::IMG_B, 'b');

        $doomed = $this->article([
            'title' => 'تست توسط علی',
            'cover_path' => 'blog/'.self::IMG_A,
            'body' => "متن\n\n![نمودار](/blog/media/".self::IMG_B.')',
            'tags' => ['برچسبِ تنها', 'برچسبِ مشترک'],
        ]);
        $keeper = $this->article(['body' => '![همان نمودار](/blog/media/'.self::IMG_B.')', 'tags' => ['برچسبِ مشترک']]);

        $report = app(BlogDeleter::class)->delete($doomed);

        $this->assertModelMissing($doomed);
        $this->assertDatabaseMissing('blog_post_tag', ['blog_post_id' => $doomed->id]);
        $this->assertSame(['images' => 1, 'tags' => 1], $report);
        $this->assertFalse($disk->exists('blog/'.self::IMG_A), 'جلدِ بی‌استفاده ماند.');
        $this->assertTrue($disk->exists('blog/'.self::IMG_B), 'تصویری که مقاله‌ی دیگر هم دارد پاک شد.');
        $this->assertNull(BlogTag::where('name', 'برچسبِ تنها')->first());
        $this->assertNotNull(BlogTag::where('name', 'برچسبِ مشترک')->first());
        $this->assertModelExists($keeper);
        $this->assertDatabaseHas('audit_logs', ['action' => 'blog.deleted']);
    }

    public function test_a_deleted_post_is_gone_from_every_public_surface(): void
    {
        $doomed = $this->article(['title' => 'تست توسط علی', 'tags' => ['آزمون']]);
        $other = $this->article(['tags' => ['آزمون']]);
        // گرم‌کردنِ کش‌ها پیش از حذف
        $this->getJson('/api/blog')->assertOk();
        app(PublicBlog::class)->related($other->id, null);

        app(BlogDeleter::class)->delete($doomed);

        $this->getJson('/api/blog')->assertJsonMissing(['title' => 'تست توسط علی']);
        $this->getJson('/api/blog?q='.urlencode('تست توسط علی'))->assertJsonPath('data.total', 0);
        $this->getJson('/api/blog/posts/'.rawurlencode($doomed->slug))->assertNotFound();
        $this->get('/blog/'.rawurlencode($doomed->slug))->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee(rawurlencode($doomed->slug), false);
        $this->assertNotContains($doomed->id, array_column(app(PublicBlog::class)->related($other->id, null), 'id'));
    }

    public function test_the_panel_delete_is_the_same_complete_delete(): void
    {
        PrivateFiles::disk()->put('blog/'.self::IMG_A, 'a');
        $post = $this->article(['cover_path' => 'blog/'.self::IMG_A]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/system/blog/posts/{$post->id}")->assertOk();

        $this->assertModelMissing($post);
        $this->assertFalse(PrivateFiles::disk()->exists('blog/'.self::IMG_A));
        $this->assertContains($post->slug, BlogDeleter::deletedSlugs());
    }

    public function test_the_command_finds_drafts_asks_first_and_deletes_by_title(): void
    {
        $draft = $this->article(['title' => 'تست توسط علی', 'status' => BlogPost::DRAFT]);

        $this->artisan('blog:delete', ['--title' => 'تست توسط علی'])
            ->expectsConfirmation('این 1 مقاله برای همیشه حذف شود؟', 'no')
            ->assertSuccessful();
        $this->assertModelExists($draft);

        $this->artisan('blog:delete', ['--title' => 'تست توسط علی', '--force' => true])->assertSuccessful();
        $this->assertModelMissing($draft);

        $this->artisan('blog:delete', ['--title' => 'تست توسط علی', '--force' => true])
            ->expectsOutputToContain('در پایگاه‌داده نیست')
            ->assertSuccessful();
        $this->artisan('blog:delete')->assertExitCode(2);
    }

    public function test_an_imported_article_that_was_deleted_is_not_brought_back_by_the_next_deploy(): void
    {
        $importer = app(BlogImporter::class);
        $importer->import();
        $post = BlogPost::firstOrFail();

        app(BlogDeleter::class)->delete($post);
        $report = $importer->import(force: true);

        $this->assertSame([$post->slug], $report['deleted']);
        $this->assertNull(BlogPost::where('slug', $post->slug)->first(), 'blog:import مقاله‌ی حذف‌شده را دوباره ساخت.');
    }

    /* ─────────────────── بندِ ۱۶: ریشه‌ی تصویرهای ۴۰۴ ─────────────────── */

    public function test_the_next_import_restores_an_image_that_went_missing(): void
    {
        $importer = app(BlogImporter::class);
        $importer->import();
        $cover = (string) BlogPost::firstOrFail()->cover_path;
        PrivateFiles::disk()->delete($cover);
        $this->get('/blog/media/'.basename($cover))->assertNotFound();

        $report = $importer->import();

        $this->assertGreaterThanOrEqual(1, $report['restored']);
        $this->assertSame([], $report['created'], 'مقاله‌ی موجود دوباره ساخته شد.');
        $this->assertTrue(PrivateFiles::disk()->exists($cover));
        $this->get('/blog/media/'.basename($cover))->assertOk();
    }

    public function test_a_failed_image_write_is_loud_not_a_post_with_a_missing_cover(): void
    {
        // دیسکِ خصوصی `throw: false` است: نوشتنِ ناموفق فقط false برمی‌گرداند
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set(PrivateFiles::name(), $disk);

        try {
            app(BlogImporter::class)->import();
            $this->fail('نوشتنِ ناموفقِ تصویر بی‌صدا گذشت.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('دسترسیِ storage/app/private/blog', $e->getMessage());
        }

        $this->assertSame(0, BlogPost::count(), 'مقاله با جلدِ ناموجود ساخته شد.');
    }

    public function test_health_sees_covers_the_web_process_cannot_read(): void
    {
        PrivateFiles::disk()->put('blog/'.self::IMG_A, 'a');
        $this->article(['cover_path' => 'blog/'.self::IMG_A]);
        $this->article(['cover_path' => 'blog/'.self::IMG_B]); // روی دیسک نیست

        $check = app(HealthReport::class)->run()['checks']['blog_media'];
        $this->assertSame(HealthReport::DEGRADED, $check['status']);
        $this->assertStringContainsString('1 از 2', $check['detail']);

        PrivateFiles::disk()->put('blog/'.self::IMG_B, 'b');
        $this->assertSame(HealthReport::OK, app(HealthReport::class)->run()['checks']['blog_media']['status']);
    }

    public function test_every_image_has_a_text_alternative(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/system/blog/posts', [
            'title' => 'بی‌توضیح', 'body' => 'متن ![](/blog/media/'.self::IMG_A.')', 'status' => BlogPost::DRAFT,
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        $post = $this->article(['title' => 'جلدِ گویا', 'cover_path' => 'blog/'.self::IMG_A]);
        $html = $this->get('/blog/'.rawurlencode($post->slug))->getContent();
        $this->assertStringContainsString('alt="تصویرِ جلدِ مقاله: جلدِ گویا"', $html);
        $this->assertStringContainsString('data-fallback="جلدِ گویا"', $html);
        $this->assertStringContainsString('alt="جلدِ گویا"', $this->get('/blog')->getContent());
    }

    public function test_the_shipped_articles_give_every_inline_image_an_alt(): void
    {
        foreach (glob(resource_path('content/blog/*.md')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression('/!\[\s*\]\(/u', (string) file_get_contents($file), basename($file));
        }
    }

    /* ───────────────────────── بندِ ۱۷: ترتیب ───────────────────────── */

    public function test_posts_with_the_same_date_keep_one_order_everywhere(): void
    {
        $same = now()->subDay()->startOfHour()->toDateTimeString();
        $first = $this->article(['published_at' => $same]);
        $second = $this->article(['published_at' => $same]);
        $third = $this->article(['published_at' => $same]);
        $expected = [$third->id, $second->id, $first->id];

        $this->assertSame($expected, array_slice(array_column($this->getJson('/api/blog')->json('data.items'), 'id'), 0, 3));
        $this->assertSame(
            array_map(fn ($id) => '/blog/'.rawurlencode((string) BlogPost::find($id)?->slug), $expected),
            array_slice(array_column(app(PublicBlog::class)->sitemap(), 'loc'), 0, 3),
        );
    }

    /**
     * 🔴 خرابکاری نشان داد سنجشِ خروجی کافی نیست: SQLite برابرها را اتفاقاً به
     * همین ترتیب برمی‌گرداند و حذفِ ترتیبِ دوم سبز می‌ماند — MySQL چنین
     * قولی نمی‌دهد. پس خودِ پرس‌وجوها سنجیده می‌شوند.
     */
    public function test_every_public_list_orders_ties_by_id(): void
    {
        $post = $this->article();
        $this->article();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $blog = app(PublicBlog::class);
        $blog->sitemap();
        $blog->related($post->id, null);
        $blog->latest($post->id);
        $blog->page(1);

        $ordered = array_filter($queries, fn (string $sql) => str_contains($sql, 'order by') && str_contains($sql, 'blog_posts'));
        $this->assertNotEmpty($ordered);
        foreach ($ordered as $sql) {
            $this->assertMatchesRegularExpression('/order by "?published_at"? desc, "?(blog_posts"?\.)?"?id"? desc/', $sql, $sql);
        }
    }

    /* ─────────────────────── بندِ ۲۰: مرتبط‌ها ─────────────────────── */

    public function test_related_are_only_truly_related_and_title_keywords_count(): void
    {
        $post = $this->article(['title' => 'پیگیری بدهی واحدهای ساختمان']);
        $keyword = $this->article(['title' => 'پیامک یادآور بدهی']);
        $this->article(['title' => 'نظرسنجی آنلاین']);

        $related = app(PublicBlog::class)->related($post->id, null);

        $this->assertSame([$keyword->id], array_column($related, 'id'), 'مقاله‌ی بی‌ربط «مرتبط» نشان داده شد.');
        $this->assertContains('بدهی', PublicBlog::keywords('پیگیری بدهی واحدها'));
        $this->assertNotContains('برای', PublicBlog::keywords('چطور برای بدهی'));
    }

    public function test_with_nothing_related_the_article_shows_the_latest_under_its_own_title(): void
    {
        $lonely = $this->article(['title' => 'نظرسنجی آنلاین']);
        $this->article(['title' => 'گزارش مالی']);

        $html = $this->get('/blog/'.rawurlencode($lonely->slug))->getContent();

        $this->assertStringContainsString('تازه‌ترین مقاله‌ها', $html);
        $this->assertStringNotContainsString('مقاله‌های مرتبط', $html);
        $this->assertStringContainsString('گزارش مالی', $html);
    }

    /* ─────────────── بندِ ۲۱: آماده برای ده‌ها مقاله ─────────────── */

    public function test_fifty_five_articles_paginate_search_and_sitemap_without_any_code_change(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->article();
        }

        $pages = [];
        for ($page = 1; $page <= 6; $page++) {
            $data = $this->getJson("/api/blog?page={$page}")->assertOk()->json('data');
            $pages = [...$pages, ...array_column($data['items'], 'id')];
            $this->assertSame(6, $data['lastPage']);
        }

        $this->assertCount(55, $pages);
        $this->assertCount(55, array_unique($pages), 'مقاله‌ای در دو صفحه آمد.');
        $dates = BlogPost::whereIn('id', $pages)->get()->sortBy(fn ($p) => array_search($p->id, $pages))->pluck('published_at')->all();
        $this->assertEquals(collect($dates)->sortDesc()->values()->all(), $dates, 'آرشیو از جدید به قدیم نیست.');

        $this->assertCount(55, app(PublicBlog::class)->sitemap());
        $newest = BlogPost::orderByDesc('published_at')->firstOrFail();
        $this->getJson('/api/blog?q='.urlencode($newest->title))->assertJsonPath('data.items.0.id', $newest->id);
        $this->get('/blog?page=4')->assertOk()->assertSee('aria-current="page"', false);
    }
}
