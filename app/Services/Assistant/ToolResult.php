<?php

namespace App\Services\Assistant;

/**
 * نتیجه‌ی یک ابزار — لایه‌های بازیابی و محاسبه (بندِ ۴.۶، لایه‌های ۵ و ۶) و سه حالتِ پاسخ (بندِ ۴.۱۵).
 *
 * `headline` و `facts` فقط از داده‌ی همان بازیابی ساخته می‌شوند؛ هیچ ابزاری متنِ کلی و حدسی («احتمالاً…») نمی‌سازد.
 * حالتِ `forbidden` و `not_found` هرگز داده‌ای همراه ندارد.
 */
final class ToolResult
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const FORBIDDEN = 'forbidden';

    /** پرسش مبهم است (چند شخصِ هم‌نام، واحدِ ناگفته) — سؤالِ تکمیلی */
    public const CLARIFY = 'clarify';

    /**
     * @param  list<array{label: string, value: string}>  $facts
     * @param  list<list<string>>  $rows
     * @param  list<string>  $columns
     * @param  list<string>  $options  گزینه‌های سؤالِ تکمیلی
     */
    private function __construct(
        public readonly string $status,
        public readonly string $headline,
        public readonly array $facts = [],
        public readonly array $columns = [],
        public readonly array $rows = [],
        public readonly ?string $basis = null,
        public readonly ?Period $period = null,
        public readonly array $options = [],
        public readonly bool $more = false,
    ) {}

    /**
     * @param  list<array{label: string, value: string}>  $facts
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     */
    public static function found(string $headline, array $facts = [], ?string $basis = null, ?Period $period = null, array $columns = [], array $rows = [], bool $more = false): self
    {
        return new self(self::FOUND, $headline, $facts, $columns, $rows, $basis, $period, more: $more);
    }

    public static function notFound(string $headline, ?Period $period = null): self
    {
        return new self(self::NOT_FOUND, $headline, period: $period);
    }

    public static function forbidden(string $headline = 'اجازه‌ی دیدنِ این اطلاعات را در این داشبورد ندارید.'): self
    {
        return new self(self::FORBIDDEN, $headline);
    }

    /** @param  list<string>  $options */
    public static function clarify(string $headline, array $options = []): self
    {
        return new self(self::CLARIFY, $headline, options: $options);
    }

    /** @return array{label: string, value: string} */
    public static function fact(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }
}
