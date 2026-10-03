<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlogPost;
use App\Models\User;
use App\Services\Blog\BlogImporter;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * پانزده مقاله‌ی همراهِ کد (مرحلهٔ ۷ · فاز ۷).
 *
 * خواسته‌های ممیزی که این‌جا قفل می‌شوند: دستِ‌کم ۱۵ مقاله‌ی کامل، تاریخِ
 * واقعی و پخش‌شده در امسال و پارسال (نه امروز روی همه)، زمانِ مطالعه از حجمِ
 * واقعی (نه عددِ ثابت)، تصویرِ اختصاصی برای هر مقاله و تصویر داخلِ متن،
 * پیوندِ داخلیِ سالم، و بی‌نامِ نویسنده.
 */
class BlogImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PrivateFiles::name());
        User::create([
            'name' => 'نامِ نویسنده‌ای که نباید دیده شود', 'phone' => '09120000081',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    public function test_fifteen_real_articles_are_imported_and_a_second_run_changes_nothing(): void
    {
        $first = app(BlogImporter::class)->import();

        $this->assertGreaterThanOrEqual(15, count($first['created']));
        $this->assertSame(count($first['created']), BlogPost::visible()->count());

        $second = app(BlogImporter::class)->import();
        $this->assertSame([], $second['created']);
        $this->assertSame(count($first['created']), count($second['skipped']));
    }

    public function test_an_edited_article_is_left_alone_unless_forced(): void
    {
        app(BlogImporter::class)->import();
        $post = BlogPost::firstOrFail();
        $post->update(['title' => 'عنوانی که ادمین عوض کرده']);

        app(BlogImporter::class)->import();
        $this->assertSame('عنوانی که ادمین عوض کرده', $post->fresh()->title);

        app(BlogImporter::class)->import(force: true);
        $this->assertNotSame('عنوانی که ادمین عوض کرده', $post->fresh()->title);
        $this->assertSame(1, BlogPost::where('slug', $post->slug)->count(), 'بازنویسی مقاله‌ی تکراری ساخت');
    }

    public function test_dates_are_spread_over_this_year_and_last_and_none_is_in_the_future(): void
    {
        app(BlogImporter::class)->import();

        $dates = BlogPost::pluck('published_at');

        $this->assertSame($dates->count(), $dates->map->toDateString()->unique()->count(), 'دو مقاله یک تاریخ دارند');
        $this->assertTrue($dates->every(fn ($d) => $d->isPast()), 'تاریخِ آینده');
        // امسال و پارسالِ شمسی: ۱۴۰۴ از ‎۲۱ مارس ۲۰۲۵ شروع می‌شود
        $this->assertTrue($dates->every(fn ($d) => $d->gte('2025-03-21')));
        $this->assertTrue($dates->contains(fn ($d) => $d->gte('2026-03-21')), 'مقاله‌ای از امسال نیست');
        $this->assertTrue($dates->contains(fn ($d) => $d->lt('2026-03-21')), 'مقاله‌ای از پارسال نیست');
    }

    public function test_reading_time_follows_the_real_length(): void
    {
        app(BlogImporter::class)->import();

        $posts = BlogPost::all();

        $this->assertGreaterThan(1, $posts->pluck('reading_minutes')->unique()->count(), 'زمانِ مطالعه برای همه یکی است');

        $longest = $posts->sortByDesc(fn ($p) => mb_strlen($p->search_text))->first();
        $shortest = $posts->sortBy(fn ($p) => mb_strlen($p->search_text))->first();
        $this->assertGreaterThan($shortest->reading_minutes, $longest->reading_minutes);
    }

    public function test_every_article_has_its_own_cover_and_an_image_inside_and_files_exist(): void
    {
        app(BlogImporter::class)->import();
        $disk = Storage::disk(PrivateFiles::name());

        $covers = BlogPost::pluck('cover_path');
        $this->assertSame($covers->count(), $covers->unique()->count(), 'دو مقاله یک تصویرِ جلد دارند');

        foreach (BlogPost::all() as $post) {
            $this->assertMatchesRegularExpression('#^blog/[0-9a-f]{40}\.webp$#', $post->cover_path);
            $this->assertTrue($disk->exists($post->cover_path), "جلدِ {$post->slug} روی دیسک نیست");

            preg_match_all('#<img[^>]*\ssrc="/blog/media/([0-9a-f]{40}\.webp)"#', $post->body_html, $m);
            $this->assertNotEmpty($m[1], "مقاله‌ی {$post->slug} تصویرِ داخلِ متن ندارد");
            foreach ($m[1] as $file) {
                $this->assertTrue($disk->exists('blog/'.$file));
            }

            // تصویرِ بیرونی هرگز (BlogRenderer آن را به متن برمی‌گرداند — نباید پیش بیاید)
            $this->assertStringNotContainsString('image:', $post->body_html);
        }
    }

    public function test_internal_links_point_to_real_articles_and_real_pages(): void
    {
        app(BlogImporter::class)->import();

        $slugs = BlogPost::pluck('slug')->all();

        foreach (BlogPost::all() as $post) {
            preg_match_all('#<a href="(/[^"]*)"#', $post->body_html, $m);

            foreach ($m[1] as $href) {
                $path = rawurldecode(parse_url($href, PHP_URL_PATH) ?? '');

                if (str_starts_with($path, '/blog/')) {
                    $this->assertContains(substr($path, 6), $slugs, "پیوندِ شکسته در {$post->slug}: {$href}");
                } else {
                    $this->assertContains($path, ['/demo', '/support', '/auth'], "مقصدِ ناشناخته در {$post->slug}: {$href}");
                }
            }
        }
    }

    public function test_the_author_is_never_shown_and_each_article_has_a_cta(): void
    {
        app(BlogImporter::class)->import();
        $post = BlogPost::firstOrFail();

        $this->get('/blog/'.rawurlencode($post->slug))
            ->assertOk()
            ->assertDontSee('نامِ نویسنده‌ای که نباید دیده شود')
            ->assertSee('مشاهده‌ی دمو')
            ->assertSee('مقاله‌های مرتبط');
    }

    /**
     * 🔴 پوشه‌ی `blog/` در هرسِ فایل‌های یتیم بود ولی هیچ تصویرِ بلاگی را
     * «شناخته» نمی‌دانست؛ `--delete` همه‌ی جلدها و تصویرهای متن را پاک می‌کرد.
     */
    public function test_pruning_orphan_uploads_keeps_every_blog_image(): void
    {
        app(BlogImporter::class)->import();
        $disk = Storage::disk(PrivateFiles::name());
        $disk->put('blog/'.str_repeat('a', 40).'.webp', 'orphan');
        $this->travel(2)->hours(); // فایلِ تازه‌تر از یک ساعت عمداً رد می‌شود

        $images = collect($disk->allFiles('blog'))->reject(fn ($f) => str_contains($f, str_repeat('a', 40)));
        $this->artisan('uploads:prune-orphans', ['--delete' => true])->assertSuccessful();

        foreach ($images as $file) {
            $this->assertTrue($disk->exists($file), "تصویرِ بلاگ پاک شد: {$file}");
        }
        $this->assertFalse($disk->exists('blog/'.str_repeat('a', 40).'.webp'), 'یتیمِ واقعی پاک نشد');
    }

    public function test_the_listing_shows_ten_articles_and_a_second_page(): void
    {
        app(BlogImporter::class)->import();

        $html = $this->get('/blog')->assertOk()->getContent();
        $this->assertSame(10, substr_count($html, '<article class="blog-card'));
        $this->assertStringContainsString('aria-label="صفحه‌ی ۲"', $html);

        $second = $this->get('/blog?page=2')->assertOk()->getContent();
        $this->assertSame(BlogPost::count() - 10, substr_count($second, '<article class="blog-card'));
    }
}
