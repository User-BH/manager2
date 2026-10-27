<?php

namespace App\Services\Assistant;

use App\Support\Digits;
use App\Support\SearchTerm;

/**
 * لایه‌ی دریافت و فهمِ پرسش (بندِ ۴.۶، لایه‌های ۱ و ۴): متن یکدست می‌شود و واحد، بازه و نامِ شخص از آن خوانده
 * می‌شود. ⚠️ متن ورودیِ نامطمئن است (بندِ ۴.۱۱): این کلاس فقط **می‌خواند**؛ هیچ مجوز، شناسه یا زمینه‌ای از متن
 * ساخته نمی‌شود — آن‌ها از نشستِ سرور می‌آیند (`AssistantContext`).
 */
final class QuestionParser
{
    /** واژه‌هایی که پس از نامِ شخص می‌آیند و جزوِ نام نیستند */
    private const AFTER_NAME = 'چیست|چیه|کیست|کیه|را|رو|است|هست|چه|کجا|در|از|واحد|کدام|چند|بده|بگو';

    /** نسبت‌هایی که «شماره‌ی X» را پرسشِ واحد می‌کنند، نه پرسشِ شخص */
    private const ROLE_WORDS = ['مالک', 'مستاجر', 'ساکن', 'ساکنین', 'ساکنان', 'مدیر', 'مدیران', 'واحد', 'مجتمع', 'من', 'خودم'];

    public function __construct(private readonly PeriodResolver $periods) {}

    public function parse(string $raw): Question
    {
        $text = self::normalize($raw);

        return new Question(
            original: trim($raw),
            text: $text,
            compact: self::compact($text),
            unit: $this->unit($text),
            period: $this->periods->resolve($text),
            person: $this->person($text),
            wantsContact: preg_match('/شماره|تلفن|موبایل|تماس|همراه/u', $text) === 1,
        );
    }

    public static function normalize(string $raw): string
    {
        $text = SearchTerm::normalize($raw);
        $text = (string) Digits::toLatin(strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'ۀ' => 'ه', 'ة' => 'ه']));
        $text = (string) preg_replace('/[؟?!.,،:؛"\'«»()\[\]]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** بی فاصله و نیم‌فاصله؛ «مستأجر» = «مستاجر» */
    public static function compact(string $text): string
    {
        return (string) preg_replace('/[\s\x{200C}]+/u', '', strtr(self::normalize($text), ['أ' => 'ا']));
    }

    private function unit(string $text): ?string
    {
        return preg_match('/واحد\s*(?:شماره\s*)?([0-9][0-9A-Za-z\/-]{0,9}|[A-Za-z][0-9][0-9A-Za-z\/-]{0,8})(?![0-9A-Za-z])/u', $text, $m) === 1
            ? $m[1]
            : null;
    }

    private function person(string $text): ?string
    {
        $end = '(?=\s+(?:'.self::AFTER_NAME.')(?!\pL)|\s*$)';

        if (preg_match('/(?:آقای|اقای|خانم|جناب)\s+(.{2,40}?)'.$end.'/u', $text, $m) === 1) {
            return $this->cleanName($m[1]);
        }

        // «شماره‌ی موبایلِ عسگری موسوی چیست» — بی عنوان؛ «شماره‌ی مالکِ واحدِ ۳» پرسشِ واحد است
        if (preg_match('/(?:شماره|تلفن|موبایل)\s*(?:ی\s*)?(?:موبایل|تلفن|همراه|تماس)?\s+(.{2,40}?)'.$end.'/u', $text, $m) === 1) {
            $name = $this->cleanName($m[1]);
            $first = explode(' ', (string) $name)[0];

            return $name !== null && ! in_array($first, self::ROLE_WORDS, true) ? $name : null;
        }

        return null;
    }

    private function cleanName(string $name): ?string
    {
        // نیم‌فاصله می‌ماند: نامِ ذخیره‌شده آن را دارد (`SearchTerm::normalize`)
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        // نامِ واقعی حرف دارد؛ «۳» یا «را» نام نیست
        if (mb_strlen($name) < 2 || preg_match('/\pL{2,}/u', $name) !== 1 || preg_match('/\d/', $name) === 1) {
            return null;
        }

        return $name;
    }
}
