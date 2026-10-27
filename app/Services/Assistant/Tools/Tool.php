<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Contracts\AssistantTool;
use App\Services\Assistant\Period;
use App\Services\Assistant\PeriodResolver;
use App\Services\Assistant\Question;
use App\Services\Assistant\QuestionParser;
use App\Services\Assistant\ToolDescriptor;
use App\Support\Jalali;

/**
 * پایه‌ی مشترکِ ماژول‌ها: کشف با کلیدواژه‌های وزن‌دارِ توصیف، مجوزِ پیش‌فرض با داشبورد، و قالب‌بندیِ عدد و پول.
 */
abstract class Tool implements AssistantTool
{
    private ?ToolDescriptor $descriptor = null;

    abstract protected function describe(): ToolDescriptor;

    public function descriptor(): ToolDescriptor
    {
        return $this->descriptor ??= $this->describe();
    }

    public function score(Question $question): int
    {
        $score = 0;
        foreach ($this->descriptor()->keywords as $keyword => $weight) {
            if (str_contains($question->compact, QuestionParser::compact($keyword))) {
                $score += $weight;
            }
        }

        return $score;
    }

    public function authorize(AssistantContext $context): bool
    {
        return in_array(self::dashboardOf($context), $this->descriptor()->dashboards, true);
    }

    public static function dashboardOf(AssistantContext $context): string
    {
        return match (true) {
            $context->isPlatform() => 'platform',
            $context->isManager() => 'manager',
            default => 'unit',
        };
    }

    protected function money(AssistantContext $context, float|int|string|null $amount): string
    {
        return Jalali::money($amount).' '.$context->currency();
    }

    protected function count(int $value): string
    {
        return Jalali::digits($value);
    }

    /** بازه‌ی پرسش، یا ماهِ جاری (با یادداشتِ صریح) */
    protected function periodOf(Question $question): Period
    {
        return $question->period ?? app(PeriodResolver::class)->currentMonth();
    }
}
