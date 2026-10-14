<?php

namespace App\Support;

use App\Enums\ResidentRelation;

/**
 * عبارتِ جست‌وجوی مدیر، یکدست و خوانده (مرحلهٔ ۱۰ · فاز ۸؛ بندهای ۲۰ و ۴۱ ِ فایلِ معماری).
 *
 * فایلِ معماری چهار نمونه می‌آورد: «۱۰»، «مالک واحد ۱۰»، «علی احمدی» و «مستأجر واحد ۲۵». پیش از این فاز
 * دو تای آخر به `LIKE '%مالک واحد ۱۰%'` روی نام می‌رسیدند و هیچ‌چیز پیدا نمی‌شد؛ «۱۰» با ارقامِ فارسی هم
 * با شماره‌ی واحدِ لاتینِ «10» جور نمی‌شد. این‌جا عبارت یک بار یکدست می‌شود و اگر به شکلِ «[نقش] واحد N»
 * باشد، به جای متن، **واحد** و **نسبت** خوانده می‌شود — هر دو بعد درونِ همان مجتمع پرسیده می‌شوند.
 *
 * ⚠️ این کلاس هیچ پرسشی نمی‌زند و مرزِ مجتمع را نمی‌شناسد؛ آن کارِ پرسنده است.
 */
final class SearchTerm
{
    /** «مالک»، «مستأجر/مستاجر»، «ساکن» — با «ِ» ِ اضافه هم، چون پیش از خواندن حذف می‌شود. */
    private const PATTERN = '/^(?:(مالک|مستاجر|ساکن)\s*)?(واحد\s*)?([0-9A-Za-z][0-9A-Za-z\/-]{0,19})$/u';

    private function __construct(
        /** عبارتِ یکدست برای جست‌وجوی متنی (نام، عنوان، متن). */
        public readonly string $text,
        /** همان با ارقامِ لاتین — برای شماره‌ی واحد، تلفن و کدِ ملی. */
        public readonly string $digits,
        /** شماره‌ی واحدی که عبارت به آن اشاره کرد، یا null. */
        public readonly ?string $unit,
        /** «واحد N» یا «مالک واحد N»: فقط همان واحد. «N» ِ تنها: هر واحدی که N در شماره‌اش هست. */
        public readonly bool $exactUnit,
        /** «مالک» یا «مستأجر»؛ «ساکن» یعنی هر دو (null). */
        public readonly ?ResidentRelation $relation,
    ) {}

    public static function parse(string $raw): self
    {
        $text = self::normalize($raw);
        $digits = (string) Digits::toLatin($text);

        $matched = preg_match(self::PATTERN, str_replace('أ', 'ا', $digits), $m) === 1;
        $keyword = $matched && ($m[1] !== '' || $m[2] !== '');

        // «ali» ِ تنها شماره‌ی واحد نیست؛ بی کلیدواژه، شماره باید رقم داشته باشد
        if (! $matched || (! $keyword && preg_match('/\d/', $m[3]) !== 1)) {
            return new self($text, $digits, null, false, null);
        }

        $relation = match ($m[1]) {
            'مالک' => ResidentRelation::Owner,
            'مستاجر' => ResidentRelation::Tenant,
            default => null,
        };

        return new self($text, $digits, $m[3], $keyword, $relation);
    }

    /** دو نویسه کمینه است — همان قاعده‌ی پیشین؛ «۱۰» دو نویسه است. */
    public function isSearchable(): bool
    {
        return mb_strlen($this->text) >= 2;
    }

    /**
     * یکدست‌سازیِ امن برای `LIKE`.
     *
     * - `%`، `_` و `\` حذف می‌شوند (دلیل در `SearchController::stripLikeWildcards`)؛
     * - «ي/ى/ك» ِ عربی ← «ی/ک»: نام‌ها با همین شکل ذخیره می‌شوند (`PersianName`)؛
     * - اعرابِ کوتاه (مثلِ «ِ» در «مالکِ واحدِ») حذف می‌شود؛ در هیچ نام و شماره‌ای معنا ندارد؛
     * - فاصله‌های پیاپی یکی می‌شوند.
     *
     * ⚠️ نیم‌فاصله **حذف نمی‌شود**: نامِ ذخیره‌شده آن را دارد و حذفش «LIKE» را بی‌نتیجه می‌کرد.
     */
    public static function normalize(string $raw): string
    {
        $text = str_replace(['%', '_', '\\'], '', $raw);
        $text = strtr($text, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']);
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
