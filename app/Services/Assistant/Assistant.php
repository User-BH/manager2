<?php

namespace App\Services\Assistant;

use App\Services\Assistant\Phrasing\Phraser;
use App\Services\Assistant\Tools\Tool;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * هسته‌ی دستیار — زنجیره‌ی بندِ ۴.۶ با لایه‌های جدا:
 *
 *   پرسش ← (احراز و زمینه: `AssistantAccess`/`AssistantContext`) ← فهم (`QuestionParser`) ← سیاستِ زمینه
 *   (`ComplexMention`) ← کشفِ داده (`ToolRegistry`) ← مجوزِ ماژول (`authorize`) ← بازیابی و محاسبه (`answer`) ←
 *   تولیدِ پاسخ (`compose` + `Phraser` ِ اختیاری) ← اعتبارسنجی (`AnswerValidator`) ← پاسخ.
 *
 * هر پرسش مستقل است: مکالمه‌ی قبلی هیچ مجوز یا داده‌ای به پرسشِ تازه نمی‌دهد (بندِ ۴.۱۴). سرویس بی‌حالت است؛ دو
 * پرسشِ هم‌زمان از دو زمینه چیزی مشترک ندارند.
 */
final class Assistant
{
    /** دستورهایی که می‌خواهند مرزِ مجوز را بردارند — درخواست است، نه دستور (بندِ ۴.۱۱) */
    private const OVERRIDES = [
        '/نادیده\s*(?:بگیر|گرفته|بگیرید)/u',
        '/(?:بدون|بی)\s*(?:بررسی|مجوز|محدودیت|کنترل)/u',
        // «تمام اطلاعاتِ کاربران»، «همه‌ی حساب‌ها»، «همه‌ی مجتمع‌ها» — نه «تمامِ هزینه‌های مجتمع»
        '/(?:همه|تمام)\S*\s+(?:\S+\s+){0,3}(?:کاربر|حساب(?:\S*ها)?(?!\S)|مجتمع\S*ها)/u',
        '/اطلاعات\S*\s+(?:ی\s+)?(?:خصوصی|محرمانه)/u',
        '/دسترسی\S*\s+(?:ی\s+)?(?:کامل|ادمین|نامحدود)/u',
        '/محدودیت\S*\s+(?:\S+\s+)?(?:را|رو)\s+(?:بردار|حذف|دور)/u',
        '/\b(?:ignore|bypass|override|jailbreak|sql|select|drop|delete|update|insert)\b/iu',
    ];

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly QuestionParser $parser,
        private readonly ComplexMention $mentions,
        private readonly AssistantCache $cache,
        private readonly Phraser $phraser,
        private readonly AnswerValidator $validator,
    ) {}

    public function ask(AssistantContext $context, string $raw): Answer
    {
        $question = $this->parser->parse($raw);

        if ($this->overrides($question)) {
            return $this->respond(ToolResult::forbidden(
                'دستیار دستورِ تغییرِ دسترسی را از متنِ پرسش نمی‌پذیرد؛ فقط داده‌هایی را می‌خواند که همین حساب در همین داشبورد اجازه‌ی دیدنشان را دارد.',
            ));
        }

        if ($blocked = $this->mentions->crossContext($context, $question)) {
            return $this->respond($blocked);
        }

        $ranked = $this->tools->ranked($question);
        if ($ranked === []) {
            return $this->unsupported($context);
        }

        // دو ماژولِ هم‌امتیاز: پرسش مبهم است — حدس زده نمی‌شود
        if (isset($ranked[1]) && $ranked[1]['score'] === $ranked[0]['score']) {
            $titles = [$ranked[0]['tool']->descriptor()->title, $ranked[1]['tool']->descriptor()->title];

            return $this->respond(ToolResult::clarify('منظورتان کدام است؟ پرسش را دقیق‌تر بنویسید.', $titles));
        }

        $tool = $ranked[0]['tool'];
        if (! $tool->authorize($context)) {
            return $this->respond(ToolResult::forbidden($this->forbiddenFor($context)), $tool->descriptor());
        }

        try {
            $result = $this->cache->remember($context, $question, $tool, fn () => $tool->answer($context, $question));
        } catch (QueryException|PDOException $e) {
            report($e);

            return $this->failure('دسترسی به داده‌های ساکنا الان ممکن نشد؛ چند لحظه بعد دوباره بپرسید.', $tool->descriptor());
        } catch (Throwable $e) {
            report($e);

            return $this->failure('خواندنِ این داده با خطا روبه‌رو شد و پاسخی ساخته نشد؛ دوباره تلاش کنید.', $tool->descriptor());
        }

        if ($context->isSuperAdmin() && $tool->descriptor()->sensitive && $result->status === ToolResult::FOUND) {
            // نقشِ سوپر ادمین دیدنِ بی‌ردِ داده‌ی حساس نیست (بندِ ۴.۸؛ تصمیمِ ۲.۳-۷)
            Audit::log('assistant.sensitive_lookup', 'پرسشِ دستیار درباره‌ی اطلاعاتِ حساس', null, [
                'tool' => $tool->descriptor()->id,
                'question' => mb_substr($question->original, 0, 200),
            ], $context->complex?->id);
        }

        return $this->respond($result, $tool->descriptor());
    }

    /**
     * پرسش‌های نمونه برای هر داشبورد — همان چیزی که ماژول‌های مجازِ همین زمینه پاسخ می‌دهند.
     *
     * @return list<string>
     */
    public static function suggestions(AssistantContext $context): array
    {
        return match (Tool::dashboardOf($context)) {
            'platform' => ['مدیر مجتمع آفتاب کیست؟', 'اطلاعات مجتمع آفتاب', 'امروز چندم است؟'],
            'manager' => ['وضعیت صندوق چیست؟', 'مجموع هزینه‌های ماه گذشته', 'چه کسی مالک واحد ۳ است؟', 'درخواست‌های عضویت در انتظار'],
            default => ['بدهی من چقدر است؟', 'پرداخت‌های من در این ماه', 'کدام اعلان‌ها خوانده نشده‌اند؟', 'مشخصات واحد من'],
        };
    }

    private function respond(ToolResult $result, ?ToolDescriptor $tool = null): Answer
    {
        $composed = $this->compose($result);
        $text = $composed;
        $engine = 'none';

        // مدلِ محلی فقط پاسخِ یافته را بازنویسی می‌کند و فقط اگر عددی نسازد
        if ($result->status === ToolResult::FOUND && ! ($tool->sensitive ?? false)) {
            $rewritten = $this->phraser->rephrase($composed);
            if ($rewritten !== null && $this->validator->accepts($composed, $rewritten)) {
                $text = $rewritten;
                $engine = $this->phraser->name();
            }
        }

        return new Answer(
            status: $result->status,
            text: $text,
            headline: $result->headline,
            facts: $result->facts,
            columns: $result->columns,
            rows: $result->rows,
            more: $result->more,
            basis: $result->basis,
            period: $result->period?->describe(),
            note: $result->period?->note,
            options: $result->options,
            tool: $tool?->id,
            source: $tool?->source,
            sensitive: $tool->sensitive ?? false,
            engine: $engine,
        );
    }

    /** متنِ کاملِ پاسخ فقط از اجزای نتیجه — مرجعِ اعتبارسنجی */
    private function compose(ToolResult $result): string
    {
        $lines = [$result->headline];
        foreach ($result->facts as $fact) {
            $lines[] = $fact['label'].': '.$fact['value'];
        }
        foreach ($result->rows as $row) {
            $lines[] = '• '.implode(' · ', $row);
        }
        if ($result->more) {
            $lines[] = '(موارد بیشتری هم هست؛ فهرستِ کامل در صفحه‌ی همین بخش است.)';
        }
        if ($result->options !== []) {
            $lines[] = implode(' / ', $result->options);
        }
        if ($result->period !== null) {
            $lines[] = 'بازه: '.$result->period->describe();
            if ($result->period->note !== null) {
                $lines[] = $result->period->note;
            }
        }
        if ($result->basis !== null) {
            $lines[] = 'مبنا: '.$result->basis;
        }

        return implode("\n", $lines);
    }

    private function unsupported(AssistantContext $context): Answer
    {
        $headline = 'این پرسش به هیچ‌یک از داده‌هایی که دستیار می‌خواند مربوط نشد. نمونه‌هایی که می‌توانید بپرسید:';

        return new Answer(Answer::UNSUPPORTED, $headline."\n".implode("\n", self::suggestions($context)), $headline, options: self::suggestions($context));
    }

    private function failure(string $message, ToolDescriptor $tool): Answer
    {
        return new Answer(Answer::ERROR, $message, $message, tool: $tool->id, source: $tool->source);
    }

    private function overrides(Question $question): bool
    {
        foreach (self::OVERRIDES as $pattern) {
            if (preg_match($pattern, $question->text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function forbiddenFor(AssistantContext $context): string
    {
        return match (Tool::dashboardOf($context)) {
            'platform' => 'در زمینه‌ی سکو داده‌ی درونیِ مجتمع‌ها (مالی، واحد، پیام و درخواست) خوانده نمی‌شود؛ برای آن، ابتدا مجتمع را از «مدیریت مجتمع‌ها» برگزینید.',
            'unit' => 'این اطلاعات فقط در داشبوردِ مدیرِ مجتمع دیده می‌شود و در داشبوردِ واحد اجازه‌ی دیدنش را ندارید.',
            default => 'اجازه‌ی دیدنِ این اطلاعات را در این داشبورد ندارید.',
        };
    }
}
