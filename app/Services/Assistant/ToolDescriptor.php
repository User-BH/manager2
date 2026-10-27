<?php

namespace App\Services\Assistant;

use InvalidArgumentException;

/**
 * توصیفِ ساختاریافته‌ی یک ماژولِ داده‌ای برای دستیار (بندِ ۴.۷).
 *
 * ماژولِ تازه (مثلاً «قراردادها») با همین توصیف و یک کلاس وصل می‌شود، بی بازنویسیِ هسته. توصیف **قرارداد** است و
 * `ToolRegistry` آن را پیش از پذیرش می‌سنجد: عملیاتِ نوشتنی، سقفِ بی‌مرز، یا ماژولِ بی قاعده‌ی دسترسی و محرمانگی
 * پذیرفته نمی‌شود. توصیف به‌تنهایی دسترسی نمی‌دهد — `AssistantTool::authorize` در هر پرسش جدا سنجیده می‌شود.
 */
final class ToolDescriptor
{
    /** عملیاتِ مجاز: فقط خواندن و جمع/شمارش. دستیار هرگز نمی‌نویسد (بندِ ۴.۱۱) */
    public const OPERATIONS = ['read', 'aggregate'];

    public const DASHBOARDS = ['unit', 'manager', 'platform'];

    /**
     * @param  list<string>  $dashboards  داشبوردهایی که ماژول در آن‌ها معنا دارد
     * @param  array<string, string>  $fields  فیلدِ قابلِ پرس‌وجو ← نوعِ داده
     * @param  list<string>  $relations  روابطِ معتبر با موجودیت‌های دیگر
     * @param  list<string>  $filters  فیلترهای مجاز (unit، period، person…)
     * @param  list<string>  $operations
     * @param  array<string, int>  $keywords  کلیدواژه ← وزن، برای لایه‌ی کشفِ داده
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $dashboards,
        /** منبعِ canonical: همان سرویس یا مدلی که صفحه‌ی خودِ ماژول از آن می‌خواند */
        public readonly string $source,
        public readonly array $fields,
        public readonly array $relations,
        public readonly array $filters,
        public readonly array $operations,
        /** روشِ محاسبه‌ی مقادیرِ مشتق (جمع، مانده…) */
        public readonly string $derivation,
        /** قاعده‌ی دسترسی به زبانِ ساده؛ اجرای واقعی در `authorize` */
        public readonly string $access,
        /** محدودیتِ محرمانگی: چه چیزی هرگز برنمی‌گردد */
        public readonly string $privacy,
        /** تعریفِ تاریخ و بازه برای این ماژول */
        public readonly string $time,
        /** بیشینه‌ی ردیفِ برگشتی (صفحه‌بندی: فقط صفحه‌ی اول، با «بیشتر هست») */
        public readonly int $limit,
        public readonly string $presentation,
        public readonly array $keywords,
        /** پاسخِ پرحساسیت (تلفن، نام و نسبت) در تاریخچه ذخیره نمی‌شود و کش نمی‌شود */
        public readonly bool $sensitive = false,
        /** داده‌ی ساختاریِ کم‌تغییر — کشِ کوتاهِ وابسته به زمینه مجاز */
        public readonly bool $cacheable = false,
    ) {}

    /** @throws InvalidArgumentException */
    public function validate(): void
    {
        $missing = array_keys(array_filter([
            'id' => $this->id, 'title' => $this->title, 'source' => $this->source, 'access' => $this->access,
            'privacy' => $this->privacy, 'derivation' => $this->derivation, 'time' => $this->time,
            'presentation' => $this->presentation,
        ], fn (string $value) => trim($value) === ''));

        $problems = [
            ...array_map(fn (string $key) => "«{$key}» خالی است", $missing),
            ...(preg_match('/^[a-z]+(\.[a-z_]+)*$/', $this->id) === 1 ? [] : ['شناسه‌ی نامعتبر']),
            ...($this->dashboards !== [] && array_diff($this->dashboards, self::DASHBOARDS) === [] ? [] : ['داشبوردِ نامعتبر']),
            ...($this->operations !== [] && array_diff($this->operations, self::OPERATIONS) === [] ? [] : ['عملیاتِ غیرخواندنی']),
            ...($this->limit >= 1 && $this->limit <= 50 ? [] : ['سقفِ ردیف باید ۱ تا ۵۰ باشد']),
            ...($this->fields !== [] ? [] : ['فیلدی تعریف نشده']),
            ...($this->keywords !== [] ? [] : ['کلیدواژه‌ای تعریف نشده']),
            ...($this->sensitive && $this->cacheable ? ['دادهٔ حساس کش نمی‌شود'] : []),
        ];

        if ($problems !== []) {
            throw new InvalidArgumentException("ماژولِ دستیار «{$this->id}» پذیرفته نشد: ".implode('، ', $problems));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
