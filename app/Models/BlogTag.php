<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * برچسبِ بلاگ (مرحلهٔ ۶ · فاز ۱۱). هنگامِ ذخیره‌ی مقاله از روی نام ساخته
 * می‌شود؛ صفحه‌ی مدیریتِ جدا ندارد.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class BlogTag extends Model
{
    protected $fillable = ['name', 'slug'];

    /** @return BelongsToMany<BlogPost, $this> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_tag');
    }
}
