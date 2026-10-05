<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use App\Services\Blog\BlogImporter;
use App\Services\Blog\BlogWriter;
use App\Services\Blog\PublicBlog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * جست‌وجو و SEO ِ بلاگ (مرحلهٔ ۸ · فاز ۴ · بندهای ۱۸ و ۱۹).
 */
class BlogSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'مدیر سیستم', 'phone' => '09120000091',
            'role' => UserRole::SuperAdmin, 'password' => Hash::make('secret123'), 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function article(array $overrides = []): BlogPost
    {
        static $n = 0;
        $n++;

        return app(BlogWriter::class)->save(null, [
            'title' => "نوشته‌ی {$n}",
            'slug' => null,
            'excerpt' => "خلاصه‌ی {$n}",
            'body' => "## بخش\n\nمتنِ ساده‌ی {$n}.",
            'status' => BlogPost::PUBLISHED,
            'published_at' => now()->subDays($n)->toDateTimeString(),
            ...$overrides,
        ], $this->admin);
    }

    /** @return list<int> */
    private function ids(string $q, int $page = 1): array
    {
        return array_column($this->getJson('/api/blog?q='.urlencode($q).'&page='.$page)->assertOk()->json('data.items'), 'id');
    }

    /* ───────────────────────── بندِ ۱۹: جست‌وجو ───────────────────────── */

    public function test_words_need_not_be_next_to_each_other(): void
    {
        $both = $this->article(['title' => 'محاسبه‌ی شارژ ماهانه', 'body' => "## روش\n\nسهمِ آسانسور جداست."]);
        $one = $this->article(['title' => 'شارژ پارکینگ']);

        $response = $this->getJson('/api/blog?q='.urlencode('شارژ آسانسور'))->assertOk();

        // پیش از این «شارژ آسانسور» فقط همین دو واژه را **پشتِ هم** می‌یافت: هیچ
        $this->assertSame([$both->id], array_column($response->json('data.items'), 'id'));
        $this->assertFalse($response->json('data.partial'));
        $this->assertNotContains($one->id, $this->ids('شارژ آسانسور'));
    }

    public function test_title_beats_tags_which_beat_the_body_and_the_whole_phrase_wins(): void
    {
        $body = $this->article(['title' => 'گزارش', 'body' => "## بخش\n\nدرباره‌ی پیامک یادآور."]);
        $tag = $this->article(['title' => 'اطلاع‌رسانی', 'tags' => ['پیامک']]);
        $title = $this->article(['title' => 'پیامک برای ساکنین']);
        $phrase = $this->article(['title' => 'پیامک یادآور بدهی', 'published_at' => now()->subYears(2)->toDateTimeString()]);

        // دو عنوان (هم‌امتیاز ← تازه‌تر اول)، بعد برچسب، بعد فقط متن
        $this->assertSame([$title->id, $phrase->id, $tag->id, $body->id], $this->ids('پیامک'));
        /*
         * کلِ عبارت در عنوان، حتی قدیمی‌ترین، اول. 🔴 خرابکاری نشان داد بی‌مقاله‌ی
         * زیر این ادعا پوچ بود: «عبارت» با واژه‌های عنوان هم جلو می‌افتاد. حالا
         * مقاله‌ای تازه‌تر هر دو واژه را در عنوان دارد، فقط نه پشتِ هم.
         */
        $this->article(['title' => 'یادآور ماهانه و پیامک گروهی', 'published_at' => now()->toDateTimeString()]);
        $this->assertSame($phrase->id, $this->ids('پیامک یادآور')[0]);
    }

    public function test_tags_and_category_are_searchable_and_a_rename_follows(): void
    {
        $category = BlogCategory::create(['name' => 'امنیت', 'slug' => 'امنیت']);
        $post = $this->article(['title' => 'رمز عبور', 'category_id' => $category->id, 'tags' => ['پشتیبان‌گیری']]);

        $this->assertSame([$post->id], $this->ids('پشتیبان‌گیری'));
        $this->assertSame([$post->id], $this->ids('امنیت'));

        $this->actingAs($this->admin)->putJson("/api/v1/system/blog/categories/{$category->id}", ['name' => 'حفاظت داده'])->assertOk();

        $this->assertSame([$post->id], $this->ids('حفاظت داده'));
        $this->assertSame([], $this->ids('امنیت'));
    }

    public function test_a_short_sentence_works_and_common_words_are_ignored(): void
    {
        $post = $this->article(['title' => 'تقسیم هزینه‌ی آسانسور', 'body' => "## بخش\n\nشارژ را منصفانه تقسیم کنید."]);
        $this->article(['title' => 'نظرسنجی آنلاین']);

        $this->assertSame([$post->id], $this->ids('چطور شارژ آسانسور را تقسیم کنیم؟'));
        $this->assertSame(['شارژ', 'آسانسور', 'تقسیم'], PublicBlog::searchTokens('چطور شارژ آسانسور را تقسیم کنیم'));
        // همه پرتکرار: خودِ عبارت یک واژه است، نه «هیچ»
        $this->assertSame(['برای چه'], PublicBlog::searchTokens('برای چه'));
    }

    public function test_no_full_match_shows_close_results_marked_as_such(): void
    {
        $charge = $this->article(['title' => 'شارژ ساختمان']);

        $response = $this->getJson('/api/blog?q='.urlencode('شارژ زیردریایی'))->assertOk();

        $this->assertTrue($response->json('data.partial'));
        $this->assertSame([$charge->id], array_column($response->json('data.items'), 'id'));
        $this->get('/blog?q='.urlencode('شارژ زیردریایی'))->assertSee('مقاله‌ی نزدیک', false);

        // یک واژه‌ی ناموجود «نزدیک» ندارد: خالی و صادق
        $this->getJson('/api/blog?q='.urlencode('زیردریایی'))->assertJsonPath('data.total', 0)->assertJsonPath('data.partial', false);
    }

    public function test_persian_spelling_variants_and_half_spaces_find_the_same_article(): void
    {
        $post = $this->article(['title' => 'پیام‌رسان ساختمان', 'tags' => ['پیامک']]);

        foreach (['پيام‌رسان', 'پیام رسان', 'پیامرسان', 'پيامك', 'كاربردي پیام'] as $q) {
            $this->assertContains($post->id, $this->ids($q), "«{$q}» پیدا نکرد.");
        }
    }

    public function test_search_results_are_paginated_by_relevance(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->article(['body' => "## بخش\n\nدرباره‌ی قبض {$i}."]);
        }
        $best = $this->article(['title' => 'قبض‌های دوره', 'published_at' => now()->subYears(3)->toDateTimeString()]);

        $first = $this->getJson('/api/blog?q='.urlencode('قبض'))->assertOk()->json('data');
        $second = $this->getJson('/api/blog?q='.urlencode('قبض').'&page=2')->assertOk()->json('data');

        $this->assertSame(13, $first['total']);
        $this->assertSame(2, $first['lastPage']);
        $this->assertSame($best->id, $first['items'][0]['id'], 'عنوانِ مرتبط، حتی قدیمی، باید اول باشد.');
        $this->assertCount(3, $second['items']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
    }

    /* ─────────────────────────── بندِ ۱۸: SEO ─────────────────────────── */

    public function test_every_listing_page_has_its_own_title_and_description(): void
    {
        $category = BlogCategory::create(['name' => 'مالی', 'slug' => 'مالی']);
        for ($i = 0; $i < 11; $i++) {
            $this->article(['category_id' => $category->id, 'tags' => ['شارژ']]);
        }

        $meta = function (string $path): array {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('/<title>(.*?)<\/title>/su', $html, $t);
            preg_match('/<meta name="description" content="([^"]*)"/u', $html, $d);

            return [html_entity_decode($t[1] ?? ''), html_entity_decode($d[1] ?? '')];
        };

        $pages = ['/blog', '/blog?page=2', '/blog/category/'.rawurlencode('مالی'), '/blog/tag/'.rawurlencode('شارژ')];
        $seen = array_map($meta, $pages);

        $this->assertCount(4, array_unique(array_column($seen, 0)), 'عنوانِ تکراری');
        $this->assertCount(4, array_unique(array_column($seen, 1)), 'توضیحِ تکراری');
        $this->assertStringContainsString('مدیریت ساختمان', $seen[0][0]);
        $this->assertStringNotContainsString('بلاگ ساکنا | ساکنا', $seen[0][0]);
    }

    public function test_an_article_has_its_own_image_alt_and_a_short_meta_description(): void
    {
        $post = $this->article([
            'title' => 'راهنمای کیف پول',
            'excerpt' => str_repeat('خلاصه‌ای بلند برای آزمودنِ کوتاه‌شدنِ توضیح ', 12),
            'cover_path' => 'blog/'.str_repeat('a', 40).'.webp',
        ]);

        $html = $this->get('/blog/'.rawurlencode($post->slug))->getContent();
        preg_match('/<meta name="description" content="([^"]*)"/u', $html, $d);
        $description = html_entity_decode($d[1]);

        $this->assertLessThanOrEqual(161, mb_strlen($description));
        $this->assertStringEndsWith('…', $description);
        $this->assertStringNotContainsString('آزمود…', $description, 'وسطِ واژه بریده شد.');
        $this->assertStringContainsString('property="og:image:alt" content="تصویرِ جلدِ مقاله: راهنمای کیف پول"', $html);
        $this->assertStringContainsString('name="twitter:image:alt" content="تصویرِ جلدِ مقاله: راهنمای کیف پول"', $html);
    }

    /**
     * 🔴 E2E گرفت، تستِ صفحه نه: کوتاه‌کردنِ توضیح با `rtrim` و نویسه‌های فارسی
     * UTF-8 را می‌شکست؛ HTML تحمل می‌کرد ولی JSON ِ API با «Malformed UTF-8»
     * ۵۰۰ می‌داد و مقاله بی‌بارگذاری باز نمی‌شد. روی هر ۱۵ مقاله‌ی واقعی.
     */
    public function test_every_shipped_article_opens_through_the_api_with_a_clean_description(): void
    {
        app(BlogImporter::class)->import();

        foreach (BlogPost::all() as $post) {
            $description = $this->getJson('/api/blog/posts/'.rawurlencode($post->slug))
                ->assertOk()
                ->json('data.metaDescription');

            $this->assertTrue(mb_check_encoding($description, 'UTF-8'), $post->slug);
            $this->assertLessThanOrEqual(161, mb_strlen($description), $post->slug);
        }
    }

    public function test_a_hash_heading_in_the_body_never_becomes_a_second_h1(): void
    {
        $post = $this->article(['body' => "# عنوانِ اشتباه\n\nمتن.\n\n## بخشِ درست\n\nمتن."]);

        $html = $this->get('/blog/'.rawurlencode($post->slug))->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), 'دو h1 روی یک صفحه.');
        $this->assertContains('عنوانِ اشتباه', array_column($post->fresh()->toc, 'text'), 'عنوانِ پایین‌آمده در فهرستِ مطالب نیست.');
    }

    public function test_the_sitemap_lists_categories_and_a_real_blog_lastmod(): void
    {
        $finance = BlogCategory::create(['name' => 'مالی', 'slug' => 'مالی']);
        BlogCategory::create(['name' => 'خالی', 'slug' => 'خالی']);
        $this->article(['category_id' => $finance->id]);
        BlogPost::query()->update(['updated_at' => '2026-01-15 10:00:00']);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/blog/category/'.rawurlencode('مالی').'</loc>', $xml);
        $this->assertStringNotContainsString(rawurlencode('خالی'), $xml, 'دسته‌ی بی‌مقاله در نقشه‌ی سایت.');
        $this->assertMatchesRegularExpression('#/blog</loc>\s*<lastmod>2026-01-15</lastmod>#', $xml);
    }
}
