<?php

namespace App\Services\Blog;

use App\Enums\UserRole;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use App\Support\PrivateFiles;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\CalendarUtils;
use RuntimeException;

/**
 * مقاله‌های همراهِ کد را به بلاگ می‌آورد (مرحلهٔ ۷ · فاز ۷).
 *
 * ─── چرا از فایل و نه دستیِ پنل ───────────────────────────────────────────
 * پانزده مقاله‌ی اول بخشی از تحویلِ همین فاز‌اند: باید در هر محیطی — استقرار،
 * نصبِ تازه، دیتابیسِ آزمایشی — یکسان و بی‌کارِ دستی ساخته شوند، و متنشان
 * در مخزن بازبینی‌شدنی باشد. پس از آن پنلِ ادمین همان مقاله‌ها را ویرایش
 * می‌کند؛ این‌جا فقط نقطه‌ی شروع است.
 *
 * ─── قالبِ فایل (`resources/content/blog/*.md`) ────────────────────────────
 *     ---
 *     title: …          slug: …          excerpt: …
 *     category: …       tags: الف، ب     published: 1404/05/12
 *     cover: dashboard  meta_description: … (اختیاری)
 *     ---
 *     مارک‌داون؛ تصویر با ![توضیح](image:messenger)
 *
 * `image:نام` به `resources/content/blog/images/نام.webp` اشاره می‌کند. فایل به
 * دیسکِ خصوصیِ بلاگ کپی می‌شود با نامِ **SHA-1 ِ محتوایش** — همان ۴۰ نویسه‌ای
 * که مسیرِ `/blog/media/` می‌پذیرد — پس اجرای دوباره فایلِ تکراری نمی‌سازد.
 *
 * ⚠️ متن از همان `BlogWriter` ِ پنل می‌گذرد: مارک‌داونِ امن، فهرستِ مطالب،
 * متنِ جست‌وجو و **زمانِ مطالعه از حجمِ واقعیِ متن** — نه عددِ دستی.
 *
 * ⚠️ مقاله‌ای که نامکش هست دست نمی‌خورد (ممکن است ادمین ویرایشش کرده باشد)،
 * مگر با `force`.
 */
class BlogImporter
{
    public function __construct(private readonly BlogWriter $writer) {}

    public function directory(): string
    {
        return resource_path('content/blog');
    }

    /** تصویرهایی که در همین اجرا دوباره روی دیسک نوشته شدند */
    private int $restored = 0;

    /**
     * @return array{created: list<string>, updated: list<string>, skipped: list<string>, deleted: list<string>, restored: int}
     */
    public function import(bool $force = false): array
    {
        $report = ['created' => [], 'updated' => [], 'skipped' => [], 'deleted' => [], 'restored' => 0];
        $this->restored = 0;
        $files = glob($this->directory().'/*.md') ?: [];
        sort($files);
        $deleted = BlogDeleter::deletedSlugs();

        foreach ($files as $file) {
            $article = $this->parse($file);

            // عمداً حذف‌شده (پنل یا `blog:delete`) — حتی با `--force` برنمی‌گردد
            if (in_array($article['slug'], $deleted, true)) {
                $report['deleted'][] = $article['slug'];

                continue;
            }

            $existing = BlogPost::where('slug', $article['slug'])->first();

            if ($existing !== null && ! $force) {
                /*
                 * 🔴 مرحلهٔ ۸ · فاز ۳: مقاله‌ی موجود دست نمی‌خورد، ولی تصویرهایش
                 * **بررسی و ترمیم** می‌شوند. پیش از این تصویر فقط هنگامِ ساختِ
                 * مقاله نوشته می‌شد؛ اگر آن نوشتن ناموفق بود، اجرای دوباره‌ی
                 * استقرار مقاله را «از قبل بود» می‌دانست و تصویر هرگز برنمی‌گشت.
                 */
                $this->storeImage($article['cover']);
                $this->withImages($article['body']);
                $report['skipped'][] = $article['slug'];

                continue;
            }

            DB::transaction(function () use ($article, $existing): void {
                $this->writer->save($existing, [
                    'title' => $article['title'],
                    'slug' => $article['slug'],
                    'excerpt' => $article['excerpt'],
                    'body' => $this->withImages($article['body']),
                    'status' => BlogPost::PUBLISHED,
                    'published_at' => $article['published']->toDateTimeString(),
                    'category_id' => $this->category($article['category'])->id,
                    'cover_path' => 'blog/'.$this->storeImage($article['cover']),
                    'tags' => $article['tags'],
                    'meta_title' => null,
                    'meta_description' => $article['meta_description'],
                ], $this->author());
            });

            $report[$existing === null ? 'created' : 'updated'][] = $article['slug'];
        }

        $report['restored'] = $this->restored;

        if ($report['created'] !== [] || $report['updated'] !== [] || $this->restored > 0) {
            BlogCache::bump();
        }

        return $report;
    }

    /**
     * @return array{title: string, slug: string, excerpt: string, category: string, tags: list<string>, published: Carbon, cover: string, meta_description: ?string, body: string}
     */
    public function parse(string $file): array
    {
        $text = str_replace("\r\n", "\n", (string) file_get_contents($file));

        if (! preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $text, $parts)) {
            throw new RuntimeException('سرآیند ندارد: '.basename($file));
        }

        $head = [];
        foreach (explode("\n", $parts[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m)) {
                $head[$m[1]] = trim($m[2]);
            }
        }

        // همان قاعده‌ی پنل: تصویرِ بی‌توضیح پذیرفته نیست (مرحلهٔ ۸ · فاز ۳)
        if (preg_match('/!\[\s*\]\(/u', $parts[2])) {
            throw new RuntimeException(basename($file).': تصویری بی‌توضیح (alt) دارد.');
        }

        foreach (['title', 'slug', 'excerpt', 'category', 'published', 'cover'] as $required) {
            if (($head[$required] ?? '') === '') {
                throw new RuntimeException("«{$required}» ندارد: ".basename($file));
            }
        }

        return [
            'title' => $head['title'],
            // همان شکلی که پنل ذخیره می‌کند؛ وگرنه «هست؟» با نامکِ خام نمی‌خواند
            'slug' => $this->writer->slugify($head['slug']),
            'excerpt' => $head['excerpt'],
            'category' => $head['category'],
            'tags' => array_values(array_filter(array_map('trim', preg_split('/[،,]/u', $head['tags'] ?? '') ?: []))),
            'published' => $this->jalali($head['published']),
            'cover' => $head['cover'],
            'meta_description' => ($head['meta_description'] ?? '') !== '' ? $head['meta_description'] : null,
            'body' => trim($parts[2])."\n",
        ];
    }

    /** `1404/05/12` → ساعتِ ۹ صبحِ همان روز به وقتِ تهران. */
    private function jalali(string $date): Carbon
    {
        if (! preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $date, $m)) {
            throw new RuntimeException("تاریخِ شمسیِ نامعتبر: {$date}");
        }

        [$gy, $gm, $gd] = CalendarUtils::toGregorian((int) $m[1], (int) $m[2], (int) $m[3]);

        return Carbon::create($gy, $gm, $gd, 9, 0, 0, 'Asia/Tehran')->utc();
    }

    /** @phpstan-impure تصویرهای متن را هم می‌نویسد (`storeImage`) */
    private function withImages(string $markdown): string
    {
        return (string) preg_replace_callback(
            '/\]\(image:([a-z0-9-]+)\)/',
            fn (array $m) => ']('.BlogRenderer::MEDIA_PREFIX.$this->storeImage($m[1]).')',
            $markdown,
        );
    }

    /**
     * نامِ فایلِ ذخیره‌شده (بی‌پوشه) را برمی‌گرداند؛ تصویرِ نبوده را می‌نویسد و می‌شمارد.
     *
     * @phpstan-impure
     */
    private function storeImage(string $name): string
    {
        $source = $this->directory().'/images/'.$name.'.webp';

        if (! is_file($source)) {
            throw new RuntimeException("تصویر نیست: images/{$name}.webp");
        }

        $file = sha1_file($source).'.webp';
        $disk = PrivateFiles::disk();

        if (! $disk->exists('blog/'.$file)) {
            /*
             * ⚠️ دیسکِ خصوصی `throw: false` است: نوشتنِ ناموفق فقط `false`
             * برمی‌گرداند. پیش از این نادیده می‌ماند و مقاله با جلدی ساخته
             * می‌شد که روی دیسک نبود — صفحه ۴۰۴ ِ تصویر می‌گرفت و هیچ‌جا
             * خطایی ثبت نمی‌شد (مرحلهٔ ۸ · فاز ۳).
             */
            if (! $disk->put('blog/'.$file, (string) file_get_contents($source))) {
                throw new RuntimeException("نوشتنِ تصویرِ {$name} روی دیسک ناموفق بود (دسترسیِ storage/app/private/blog؟)");
            }
            $this->restored++;
        }

        return $file;
    }

    private function category(string $name): BlogCategory
    {
        return BlogCategory::firstOrCreate(
            ['slug' => $this->writer->slugify($name)],
            ['name' => $name],
        );
    }

    /**
     * نویسنده فقط برای سابقه‌ی پنل است؛ در صفحه و JSON-LD نامی نمی‌آید.
     * روی نصبِ تازه ممکن است هنوز ادمینی نباشد — آن‌وقت بی‌نویسنده.
     */
    private function author(): ?User
    {
        return User::where('role', UserRole::SuperAdmin)->orderBy('id')->first();
    }
}
