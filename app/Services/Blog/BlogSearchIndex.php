<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Support\PersianText;

/**
 * ستون‌های جست‌وجوی مقاله (مرحلهٔ ۸ · فاز ۴ · بندِ ۱۹).
 *
 * سه ستون، هرکدام یک‌دست‌شده با همان `PersianText::normalize` که عبارتِ کاربر
 * هم با آن یک‌دست می‌شود («ي»/«ك»، «آ»، ارقام، نیم‌فاصله):
 *
 *   - `search_title` — فقط عنوان؛ بیشترین وزن
 *   - `search_meta`  — برچسب‌ها، دسته و خلاصه؛ وزنِ میانه
 *   - `search_text`  — عنوان، خلاصه و کلِ متن (از مرحلهٔ ۷ · فاز ۷)
 *
 * جدا بودنشان برای **رتبه** است: واژه‌ای که در عنوان آمده از واژه‌ای که فقط
 * یک بار در بدنه آمده جلوتر می‌نشیند. برچسب و دسته پیش از این اصلاً جست‌وجو
 * نمی‌شدند.
 */
class BlogSearchIndex
{
    public static function title(string $title): string
    {
        return mb_substr(PersianText::normalize($title), 0, 255);
    }

    /** @param  list<string>  $tags */
    public static function meta(?string $excerpt, ?string $category, array $tags): string
    {
        return trim(PersianText::normalize(implode(' ', array_filter([...$tags, $category, $excerpt]))));
    }

    /** پس از هر تغییرِ برچسب، دسته یا عنوان — بی‌رویداد و بی‌لمسِ updated_at */
    public static function refresh(BlogPost $post): void
    {
        $post->loadMissing(['category', 'tags']);

        BlogPost::whereKey($post->id)->toBase()->update([
            'search_title' => self::title($post->title),
            'search_meta' => self::meta($post->excerpt, $post->category?->name, $post->tags->pluck('name')->all()),
        ]);
    }
}
