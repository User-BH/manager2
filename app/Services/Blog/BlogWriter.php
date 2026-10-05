<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use App\Support\Audit;
use App\Support\PersianText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ساخت و ویرایشِ مقاله (مرحلهٔ ۶ · فاز ۱۱).
 *
 * تنها راهِ نوشتنِ `body_html` این‌جاست — همیشه از `BlogRenderer`، هرگز از
 * ورودیِ کاربر. پس HTMLِ صفحه‌ی عمومی نمی‌تواند از مسیرِ دیگری آلوده شود.
 */
class BlogWriter
{
    public function __construct(private readonly BlogRenderer $renderer) {}

    /**
     * @param  array{title: string, slug?: ?string, excerpt?: ?string, body: string, category_id?: ?int, tags?: array<int, string>, status: string, published_at?: ?string, cover_path?: ?string, meta_title?: ?string, meta_description?: ?string}  $data
     */
    public function save(?BlogPost $post, array $data, ?User $author): BlogPost
    {
        return DB::transaction(function () use ($post, $data, $author): BlogPost {
            $post ??= new BlogPost(['status' => BlogPost::DRAFT]);
            $wasVisible = $post->exists && $post->isVisible();

            $rendered = $this->renderer->render($data['body']);

            $post->fill([
                'title' => $data['title'],
                'slug' => $this->uniqueSlug($data['slug'] ?: $data['title'], $post->id),
                'excerpt' => $data['excerpt'] ?? null,
                'body' => $data['body'],
                'body_html' => $rendered['html'],
                'toc' => $rendered['toc'],
                /*
                 * 🔴 عنوان و خلاصه هم در متنِ جست‌وجو (مرحلهٔ ۷ · فاز ۷).
                 *
                 * جست‌وجو عبارت را یکدست می‌کند («آ» → «ا»، «ي» → «ی») ولی عنوان را
                 * خام می‌گشت و این ستون فقط متنِ بدنه را داشت؛ پس «پرداخت آنلاین»
                 * که فقط در عنوان آمده بود هرگز پیدا نمی‌شد.
                 */
                'search_text' => trim(PersianText::normalize($data['title'].' '.($data['excerpt'] ?? '')).' '.$rendered['text']),
                'reading_minutes' => $rendered['minutes'],
                'category_id' => $data['category_id'] ?? null,
                'cover_path' => $data['cover_path'] ?? null,
                'status' => $data['status'],
                // انتشار بی‌تاریخ یعنی «همین حالا»
                'published_at' => $data['status'] === BlogPost::PUBLISHED
                    ? ($data['published_at'] ?? $post->published_at ?? now())
                    : ($data['published_at'] ?? null),
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
            ]);

            // نویسنده فقط سابقه‌ی پنل است؛ واردکننده‌ی مقاله‌ها روی نصبِ تازه ممکن است ادمین نداشته باشد
            if (! $post->exists) {
                $post->author_id = $author?->id;
            }

            $post->save();
            $post->tags()->sync($this->tagIds($data['tags'] ?? []));
            // پس از برچسب‌ها: ستون‌های رتبه‌ی جست‌وجو نامِ برچسب و دسته را هم دارند
            BlogSearchIndex::refresh($post->unsetRelation('tags')->unsetRelation('category'));

            if (! $wasVisible && $post->status === BlogPost::PUBLISHED) {
                Audit::log('blog.published', 'انتشارِ مقاله‌ی بلاگ', null, ['post' => $post->id, 'slug' => $post->slug]);
            }

            return $post;
        });
    }

    /**
     * نامکِ یکتا. فارسی می‌ماند (`مدیریت-شارژ`)، چون جست‌وجوی فارسی با آدرسِ
     * فارسی بهتر دیده می‌شود؛ فقط حرف و عدد و خط‌تیره.
     */
    public function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = $this->slugify($source) ?: 'post';
        $slug = $base;

        for ($i = 2; BlogPost::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function slugify(string $text): string
    {
        $text = Str::lower(trim($text));
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);

        return Str::limit(trim($text, '-'), 180, '');
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    private function tagIds(array $names): array
    {
        return collect($names)
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => $this->slugify($name))
            ->take(10)
            ->map(fn (string $name) => BlogTag::firstOrCreate(
                ['slug' => $this->slugify($name)],
                ['name' => Str::limit($name, 50, '')],
            )->id)
            ->values()
            ->all();
    }
}
