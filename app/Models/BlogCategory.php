<?php

namespace App\Models;

use App\Services\Blog\BlogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دسته‌ی بلاگ (مرحلهٔ ۶ · فاز ۱۱).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 */
class BlogCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    protected static function booted(): void
    {
        static::saved(fn () => BlogCache::bump());
        static::deleted(fn () => BlogCache::bump());
    }

    /** @return HasMany<BlogPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'category_id');
    }
}
