<?php

namespace App\Services\Assistant;

/**
 * پاسخِ نهایی به کاربر. سه حالتِ بندِ ۴.۱۵ (`found`، `not_found`، `forbidden`) به‌علاوه‌ی `clarify` (پرسشِ مبهم)،
 * `unsupported` (هیچ ماژولی به پرسش نمی‌خورد) و `error` (داده در دسترس نیست — خطای واقعی و روشن، بندِ ۴.۱۲).
 */
final class Answer
{
    public const UNSUPPORTED = 'unsupported';

    public const ERROR = 'error';

    /**
     * @param  list<array{label: string, value: string}>  $facts
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>  $options
     */
    public function __construct(
        public readonly string $status,
        /** متنِ کاملِ پاسخ — همان که در تاریخچه می‌ماند (مگر حساس) */
        public readonly string $text,
        public readonly string $headline,
        public readonly array $facts = [],
        public readonly array $columns = [],
        public readonly array $rows = [],
        public readonly bool $more = false,
        public readonly ?string $basis = null,
        public readonly ?string $period = null,
        public readonly ?string $note = null,
        public readonly array $options = [],
        public readonly ?string $tool = null,
        public readonly ?string $source = null,
        public readonly bool $sensitive = false,
        /** «none» یا نامِ موتورِ محلی‌ای که متن را بازنویسی کرد */
        public readonly string $engine = 'none',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'text' => $this->text,
            'headline' => $this->headline,
            'facts' => $this->facts,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'more' => $this->more,
            'basis' => $this->basis,
            'period' => $this->period,
            'note' => $this->note,
            'options' => $this->options,
            'tool' => $this->tool,
            'source' => $this->source,
            'engine' => $this->engine,
        ];
    }
}
