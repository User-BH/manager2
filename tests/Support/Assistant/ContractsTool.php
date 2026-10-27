<?php

namespace Tests\Support\Assistant;

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;
use App\Services\Assistant\Tools\Tool;
use RuntimeException;

/**
 * ماژولِ آزمایشیِ «قراردادها» (ایرادات ۱۱، بندِ ۴.۷ و آزمونِ ۳۱) — فقط برای آزمون، در پیکربندیِ واقعی نیست.
 *
 * `$fail` شکستِ لایه‌ی داده را بازی می‌کند (آزمون‌های ۲۵ و ۲۶)؛ `$operations` قراردادِ نامعتبر را (عملیاتِ نوشتنی).
 */
final class ContractsTool extends Tool
{
    public static ?\Throwable $fail = null;

    /** @var list<string> */
    public static array $operations = ['read'];

    protected function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            id: 'contracts',
            title: 'قراردادها',
            dashboards: ['manager'],
            source: 'contracts (ماژولِ آزمایشی)',
            fields: ['title' => 'string', 'ends_at' => 'date'],
            relations: [],
            filters: [],
            operations: self::$operations,
            derivation: '—',
            access: 'فقط مدیر',
            privacy: 'مبلغِ قرارداد نه',
            time: 'تاریخِ پایان',
            limit: 5,
            presentation: 'فهرست',
            keywords: ['قرارداد' => 6],
        );
    }

    public function answer(AssistantContext $context, Question $question): ToolResult
    {
        if (self::$fail !== null) {
            throw self::$fail;
        }

        return ToolResult::found('قراردادهای «'.$context->complex?->name.'»: ۲ قرارداد.', [ToolResult::fact('تعداد', '۲')], basis: 'ماژولِ آزمایشی');
    }

    public static function reset(): void
    {
        self::$fail = null;
        self::$operations = ['read'];
    }

    public static function boom(): RuntimeException
    {
        return new RuntimeException('internal contracts service exploded at /var/www/secret.php:12');
    }
}
