<?php

namespace App\Models;

use App\Services\Blog\BlogCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * مقاله‌ی بلاگ (مرحلهٔ ۶ · فاز ۱۱).
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body
 * @property string $body_html
 * @property array<int, array{level: int, id: string, text: string}>|null $toc
 * @property string $search_text
 * @property int $reading_minutes
 * @property string|null $cover_path
 * @property int|null $category_id
 * @property int|null $author_id
 * @property string $status
 * @property Carbon|null $published_at
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BlogPost extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = [
        'title', 'slug', 'excerpt', 'body', 'body_html', 'toc', 'search_text', 'reading_minutes',
        'cover_path', 'category_id', 'status', 'published_at', 'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'toc' => 'array',
            'published_at' => 'datetime',
            'reading_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // هر تغییر نسخه‌ی کشِ بلاگ را جلو می‌برد؛ هیچ صفحه‌ای کهنه نمی‌ماند
        static::saved(fn () => BlogCache::bump());
        static::deleted(fn () => BlogCache::bump());
    }

    /**
     * فقط آنچه خواننده حق دارد ببیند: منتشرشده **و** زمانش رسیده.
     *
     * ⚠️ «منتشرشده» به‌تنهایی کافی نیست: مقاله‌ی زمان‌بندی‌شده برای فردا نباید
     * امروز با حدسِ آدرس خوانده شود.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isVisible(): bool
    {
        return $this->status === self::PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    /** @return BelongsTo<BlogCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<BlogTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag');
    }
}
