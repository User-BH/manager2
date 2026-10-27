<?php

namespace App\Services\Assistant;

use App\Models\Complex;
use App\Models\ComplexMembership;
use Illuminate\Support\Collection;

/**
 * «مجتمعِ X» در متنِ پرسش (بندِ ۴.۸).
 *
 * سیاستِ محصول: در داشبوردِ یک مجتمع دستیار **فقط** همان مجتمع را می‌خواند. پرسش درباره‌ی مجتمعِ دیگر — حتی
 * مجتمعِ دیگرِ همین حساب — پاسخِ داده نمی‌گیرد، بلکه راهنمایی: زمینه با تعویضِ صریحِ مجتمع (منوی آدمک) عوض می‌شود و
 * همه‌ی مجوزها از نو سنجیده می‌شوند. نامِ مجتمعی که کاربر عضوش نیست بازگو نمی‌شود (نه «هست» نه «نیست»).
 * فقط سوپر ادمین در زمینه‌ی سکو مجتمع را با نام می‌جوید — با audit.
 */
final class ComplexMention
{
    /** واژه‌هایی که پس از «مجتمع» نام نیستند */
    private const NOT_A_NAME = 'من|ما|خود|خودم|خودمان|فعلی|جاری|این|همین|را|رو|در|از|چه|چند|کجا|کجاست|کیست|چیست|چیه|است|هست|و|به|با|برای|که|تا';

    /** واژه‌هایی که پس از نام می‌آیند */
    private const AFTER = 'چه|کیست|کیه|چیست|چیه|کجاست|کجا|را|رو|است|هست|در|از|کدام|چند|مدیر|مدیران|آدرس|نوع|تلفن|شماره|واحد|اطلاعات|مشخصات|و';

    /** پیشوندهای عمومیِ نامِ مجتمع؛ «آفتاب» همان «مجتمع مسکونی آفتاب» است */
    private const GENERIC = ['مجتمع', 'مسکونی', 'تجاری', 'برج', 'ساختمان', 'شهرک', 'اداری'];

    public function phrase(Question $question): ?string
    {
        $pattern = '/مجتمع\s+(?!(?:'.self::NOT_A_NAME.')(?!\pL))(.{2,40}?)(?=\s+(?:'.self::AFTER.')(?!\pL)|\s*$)/u';

        return preg_match($pattern, $question->text, $m) === 1 ? trim($m[1]) : null;
    }

    /** پرسش در زمینه‌ی یک مجتمع، درباره‌ی مجتمعی دیگر است؟ پاسخِ «اجازه نیست» با راهنما، وگرنه null */
    public function crossContext(AssistantContext $context, Question $question): ?ToolResult
    {
        $phrase = $this->phrase($question);
        if ($context->complex === null || $phrase === null || $this->names($context->complex->name, $phrase)) {
            return null;
        }

        $active = $context->complex->name;
        // عضویت‌های خودِ حساب در **همه‌ی** مجتمع‌ها — دامنه‌ی مستأجر فقط مجتمعِ فعال را نشان می‌داد
        $own = ComplexMembership::withoutGlobalScopes()->where('user_id', $context->user->id)->whereIn('status', ComplexMembership::LIVE)
            ->join('complexes', 'complexes.id', '=', 'complex_memberships.complex_id')
            ->pluck('complexes.name')
            ->first(fn (string $name) => $this->names($name, $phrase));

        return ToolResult::forbidden($own !== null
            ? "این پرسش درباره‌ی «{$own}» است و دستیار در این داشبورد فقط داده‌ی «{$active}» را می‌خواند. برای پرسش درباره‌ی «{$own}»، از منوی حساب به همان مجتمع بروید."
            : "دستیار در این داشبورد فقط داده‌ی «{$active}» را می‌خواند؛ درباره‌ی مجتمعِ دیگری پاسخ نمی‌دهد.");
    }

    /**
     * مجتمع‌هایی که سوپر ادمین در زمینه‌ی سکو با نام پرسیده — داده‌ی آزمایشی فقط بیرون از Production و برای حسابِ
     * آزمایشی (`AssistantContext::includesDemoData`).
     *
     * @return Collection<int, Complex>
     */
    public function forPlatform(AssistantContext $context, Question $question): Collection
    {
        $phrase = $this->phrase($question);
        if ($phrase === null) {
            return new Collection;
        }

        return Complex::query()
            ->when(! $context->includesDemoData(), fn ($q) => $q->operational())
            ->where('name', 'like', '%'.$this->distinctive($phrase).'%')
            ->orderBy('name')
            ->limit(6)
            ->get();
    }

    /** «آفتاب» با «مجتمع مسکونی آفتاب» یکی است */
    private function names(string $complexName, string $phrase): bool
    {
        $name = QuestionParser::compact($this->distinctive($complexName));
        $said = QuestionParser::compact($this->distinctive($phrase));

        return $said !== '' && $name !== '' && (str_contains($name, $said) || str_contains($said, $name));
    }

    private function distinctive(string $name): string
    {
        $words = array_filter(explode(' ', QuestionParser::normalize($name)), fn (string $w) => ! in_array($w, self::GENERIC, true));

        return trim(implode(' ', $words));
    }
}
