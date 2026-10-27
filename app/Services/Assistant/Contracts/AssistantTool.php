<?php

namespace App\Services\Assistant\Contracts;

use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Question;
use App\Services\Assistant\ToolDescriptor;
use App\Services\Assistant\ToolResult;

/**
 * یک ماژولِ داده‌ایِ متصل به دستیار (بندهای ۴.۵ تا ۴.۷).
 *
 * سه لایه‌ی جدا برای هر ماژول: کشف (`score`)، مجوز (`authorize`) و بازیابی/محاسبه (`answer`). `answer` فقط پس از
 * `authorize` ِ مثبت صدا زده می‌شود، و خودش هم دامنه را از زمینه‌ی سرور می‌گیرد — نه از متن.
 */
interface AssistantTool
{
    public function descriptor(): ToolDescriptor;

    /** امتیازِ ارتباطِ پرسش با این ماژول؛ صفر یعنی بی‌ربط */
    public function score(Question $question): int;

    /** این زمینه اصلاً به این ماژول راه دارد؟ (نقش، داشبورد، قابلیتِ روشن) */
    public function authorize(AssistantContext $context): bool;

    public function answer(AssistantContext $context, Question $question): ToolResult;
}
