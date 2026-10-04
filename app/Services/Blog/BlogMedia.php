<?php

namespace App\Services\Blog;

use App\Models\BlogPost;

/**
 * کدام فایل‌های دیسکِ خصوصی مالِ بلاگ‌اند (مرحلهٔ ۸ · فاز ۳).
 *
 * یک جا برای سه مصرف‌کننده: هرسِ فایل‌های یتیم (`uploads:prune`)، حذفِ کاملِ
 * مقاله (`BlogDeleter`) و سنجشِ سلامت. پیش از این قاعده فقط در هرس بود و حذفِ
 * مقاله تصویرهایش را جا می‌گذاشت.
 */
class BlogMedia
{
    /** @return list<string> مسیرِ دیسک (`blog/<sha1>.ext`) ِ جلد و تصویرهای درونِ متن */
    public static function pathsOf(?string $coverPath, ?string $body): array
    {
        $paths = $coverPath ? [$coverPath] : [];

        preg_match_all('#'.preg_quote(BlogRenderer::MEDIA_PREFIX, '#').'([A-Za-z0-9]{40}\.[a-z]+)#', (string) $body, $m);
        foreach ($m[1] as $file) {
            $paths[] = 'blog/'.$file;
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> همه‌ی تصویرهایی که هنوز مقاله‌ای (هر وضعیتی) به آن‌ها اشاره می‌کند */
    public static function referenced(?int $exceptPostId = null): array
    {
        $paths = [];

        BlogPost::query()
            ->when($exceptPostId !== null, fn ($q) => $q->whereKeyNot($exceptPostId))
            ->select(['id', 'cover_path', 'body'])
            ->chunkById(200, function ($posts) use (&$paths): void {
                foreach ($posts as $post) {
                    array_push($paths, ...self::pathsOf($post->cover_path, $post->body));
                }
            });

        return array_values(array_unique($paths));
    }
}
