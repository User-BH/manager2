<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * صفحه‌بندیِ مبتنی بر نشانگر (R30).
 *
 * ─── چرا `offset` برای این فهرست‌ها **غلط** است، نه فقط کند ────────────────
 * `paginate()` با `LIMIT/OFFSET` کار می‌کند و فرض می‌گیرد فهرست بین دو
 * درخواست ثابت است. برای جدول‌های **افزایشی** — لاگِ فعالیت و رویدادهای
 * خطا — این فرض غلط است:
 *
 *   کاربر صفحه‌ی ۱ (۳۰ ردیفِ آخر) را می‌بیند. تا وقتی صفحه‌ی ۲ را بزند،
 *   ۵ رویدادِ تازه ثبت شده. حالا `OFFSET 30` پنج ردیفی را برمی‌گرداند که
 *   کاربر **همین الان در صفحه‌ی ۱ دیده بود** — و پنج ردیفِ قدیمی‌تر برای
 *   همیشه از دیدش پنهان می‌مانند.
 *
 * روی لاگِ امنیتی این فقط آزاردهنده نیست؛ یعنی رویدادی که باید بررسی
 * می‌شد هرگز دیده نشود.
 *
 * نشانگر به‌جای شماره‌ی صفحه، **آخرین کلیدی** را می‌فرستد که کاربر دیده و
 * کوئری از همان‌جا ادامه می‌دهد. ردیفِ تازه بالای فهرست اضافه می‌شود و
 * چیزی را جابه‌جا نمی‌کند.
 *
 * ─── چرا `cursorPaginate` خودِ لاراول استفاده نشد ──────────────────────────
 * آن هم درست است، ولی نشانگرش یک رشته‌ی base64ِ رمزنگاری‌شده است که در
 * تست و در لاگِ درخواست خوانا نیست، و شکلِ پاسخش با بقیه‌ی فهرست‌های این
 * پروژه فرق دارد. اینجا نشانگر همان شناسه‌ی عددی است: قابلِ خواندن، قابلِ
 * ساختن با دست، و سازگار با همان قراردادی که فرانت از قبل می‌شناسد.
 */
class CursorPage
{
    /**
     * یک صفحه از فهرستِ نزولی بر اساس کلیدِ اصلی.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  کوئریِ **بدونِ** `orderBy` — ترتیب اینجا اعمال می‌شود
     * @param  int|null  $cursor  آخرین شناسه‌ای که کلاینت دیده؛ `null` یعنی از ابتدا
     * @param  callable(TModel): array<string, mixed>  $present
     * @return array<string, mixed>
     */
    public static function descending(
        Builder $query,
        ?int $cursor,
        int $perPage,
        callable $present,
        string $column = 'id',
    ): array {
        $perPage = max(1, min($perPage, 100));

        if ($cursor !== null && $cursor > 0) {
            $query->where($query->getModel()->getTable().'.'.$column, '<', $cursor);
        }

        /*
         * یکی بیشتر می‌خوانیم تا بدانیم صفحه‌ی بعدی هست یا نه.
         *
         * جایگزینش یک `count()` جدا بود که روی جدولِ چند صد هزار ردیفی
         * گران است — و برای جوابِ «آیا دکمه‌ی ادامه را نشان بدهم؟» اصلاً
         * لازم نیست بدانیم دقیقاً چند ردیف مانده.
         */
        $rows = $query
            ->orderByDesc($query->getModel()->getTable().'.'.$column)
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $page = $hasMore ? $rows->take($perPage) : $rows;

        return [
            'data' => $page->map($present)->values()->all(),
            'hasMore' => $hasMore,
            /*
             * نشانگرِ بعدی از **آخرین ردیفِ همین صفحه** می‌آید و نه از
             * شمارنده. اگر ردیفی بین دو درخواست حذف شود، شمارنده می‌لغزید
             * ولی این نمی‌لغزد.
             */
            'nextCursor' => $hasMore ? $page->last()?->getAttribute($column) : null,
        ];
    }

    /**
     * همان، برای وقتی که مدل‌ها از پیش خوانده شده‌اند.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $rows
     * @param  callable(TModel): array<string, mixed>  $present
     * @return array<string, mixed>
     */
    public static function fromCollection(
        Collection $rows,
        int $perPage,
        callable $present,
        string $column = 'id',
    ): array {
        $hasMore = $rows->count() > $perPage;
        $page = $hasMore ? $rows->take($perPage) : $rows;

        return [
            'data' => $page->map($present)->values()->all(),
            'hasMore' => $hasMore,
            'nextCursor' => $hasMore ? $page->last()?->getAttribute($column) : null,
        ];
    }
}
