<?php

namespace App\Services\Blog;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * کشِ خواندنِ بلاگ با **نسخه** به‌جای پاک‌کردنِ کلید به کلید (مرحلهٔ ۶ · فاز ۱۱).
 *
 * هر ذخیره یا حذفِ مقاله و دسته نسخه را یکی جلو می‌برد؛ کلیدهای نسخه‌ی قبلی
 * دیگر خوانده نمی‌شوند و با TTL خودشان می‌میرند. پس هیچ صفحه‌ای پس از ویرایش
 * کهنه نمی‌ماند، بی‌آنکه فهرستِ همه‌ی کلیدها (صفحه‌ی ۱، ۲، دسته، برچسب…) لازم
 * باشد — کاری که کشِ `database` برچسب‌محور ندارد.
 *
 * ⚠️ TTL کوتاه است (۵ دقیقه) و دلیلش مقاله‌ی **زمان‌بندی‌شده** است: رسیدنِ
 * ساعتِ انتشار ذخیره‌ای نیست که نسخه را جلو ببرد. مقاله حداکثر پنج دقیقه
 * پس از زمانش دیده می‌شود.
 */
class BlogCache
{
    private const VERSION_KEY = 'blog:version';

    public const TTL_SECONDS = 300;

    public static function bump(): void
    {
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback): mixed
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        return Cache::remember("blog:v{$version}:{$key}", self::TTL_SECONDS, $callback);
    }
}
