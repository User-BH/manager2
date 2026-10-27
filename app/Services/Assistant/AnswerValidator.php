<?php

namespace App\Services\Assistant;

use App\Support\Digits;

/**
 * لایه‌ی اعتبارسنجیِ پاسخ (بندِ ۴.۶، لایه‌ی ۸).
 *
 * پاسخِ ساخته‌شده از داده (`AnswerComposer`) مرجع است. هر متنِ دیگری — بازنویسیِ مدلِ محلی — فقط وقتی پذیرفته
 * می‌شود که **هیچ عددی** نیاورد که در مرجع نیست (مبلغ، تاریخ، شماره‌ی تلفن یا واحد) و بی‌اندازه بلندتر نشود. عددِ
 * ساختگی یعنی پاسخِ ساختگی؛ آن‌وقت همان پاسخِ مرجع برمی‌گردد.
 */
final class AnswerValidator
{
    public function accepts(string $reference, string $candidate): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '' || mb_strlen($candidate) > mb_strlen($reference) * 2 + 200) {
            return false;
        }

        return array_diff(self::numbers($candidate), self::numbers($reference)) === [];
    }

    /** @return list<string> */
    public static function numbers(string $text): array
    {
        $latin = (string) Digits::toLatin($text);
        // جداکننده‌ی هزارگان («۱٬۲۰۰٬۰۰۰» یا «1,200,000») بخشی از همان عدد است
        $latin = (string) preg_replace('/(?<=\d)[,٬](?=\d{3})/u', '', $latin);
        preg_match_all('/\d+(?:\.\d+)?/', $latin, $m);

        return array_values(array_unique($m[0]));
    }
}
