<?php

namespace App\Services\Support;

use App\Support\PersianText;

/**
 * پاسخ‌گوی چت پشتیبانی صفحه‌ی فرود.
 *
 * موتور محلی است و عمداً: پروژه قید «عدم وابستگی به سرویس خارجی» دارد، این
 * نقطه عمومی و بدون احراز هویت است (یعنی هر پیام روی یک API پولی هزینه
 * می‌سازد)، و پاسخ باید آنی باشد. تطبیق روی پایگاه دانشِ خودمان انجام
 * می‌شود، پس هیچ‌وقت چیزی از خودش نمی‌سازد.
 *
 * جای اتصال به مدل زبانی باز گذاشته شده: اگر روزی کلید و دسترسی داشتید،
 * `config('support.driver')` را عوض کنید و `AiSupportDriver` را پر کنید.
 */
class SupportBot
{
    /**
     * کمینه‌ی امتیازی که یک موضوع باید بگیرد تا پاسخش قطعی حساب شود.
     *
     * پایین‌تر از این، به‌جای پاسخ اشتباهِ با اطمینان، چند گزینه پیشنهاد
     * می‌شود. پاسخ غلطِ مطمئن بدتر از «نفهمیدم» است.
     */
    private const CONFIDENT = 4.0;

    /** زیر این امتیاز اصلاً ربطی وجود ندارد. */
    private const RELEVANT = 1.5;

    /**
     * @return array<string,mixed>
     */
    public function reply(string $message): array
    {
        /*
         * ⚠️ وتوی دامنه، **پیش از** امتیازدهی.
         *
         * «قیمت دلار چند شد» از دیدِ امتیاز یک پرسشِ کاملاً معتبر درباره‌ی
         * هزینه‌هاست — «قیمت» با وزنِ ۶ کلیدواژه‌ی همان موضوع است. هیچ
         * آستانه‌ای این را نمی‌گرفت، چون مشکل امتیاز نبود. تنها چیزی که
         * فرقش را می‌گوید واژه‌ی «دلار» است.
         */
        if (Lexicon::isOutOfDomain($message)) {
            return $this->fallback();
        }

        $tokens = Lexicon::expand($message);

        if ($tokens === []) {
            return $this->fallback();
        }

        $ranked = $this->rank($tokens);
        $best = $ranked[0] ?? null;

        if (! $best || $best['score'] < self::RELEVANT) {
            return $this->fallback();
        }

        if ($best['score'] < self::CONFIDENT || ! $this->isSolid($best)) {
            /*
             * «کدام‌یک را می‌پرسید؟» فقط وقتی معنا دارد که واقعاً چند گزینه
             * باشد. با یک نامزدِ ضعیف، این جمله کاربر را سرگردان می‌کند
             * بی‌آنکه چیزی به او بدهد — همان‌جا پاسخِ پیش‌فرض صادق‌تر است.
             */
            return count($ranked) > 1
                ? $this->ambiguous($ranked)
                : $this->fallback();
        }

        $intent = $best['intent'];

        return [
            'intent' => $intent['id'],
            'title' => $intent['title'],
            'answer' => $intent['answer'],
            'links' => $intent['links'] ?? [],
            'followUps' => $intent['followUps'] ?? [],
            'confident' => true,
        ];
    }

    /**
     * آیا شواهدِ این موضوع به‌قدرِ کافی محکم است؟
     *
     * ─── باگی که این قاعده می‌گیرد ─────────────────────────────────────────
     * «نتیجه بازی پرسپولیس چی شد» با اطمینان به موضوعِ **بکاپ** می‌رفت. کلِ
     * شاهد این بود: «بازی» پیشوندِ «بازیابی» است ⇒ ‎۰٫۸ × وزنِ ۵ = ‎۴٫۰ ⇒
     * دقیقاً روی آستانه. یعنی **یک تطبیقِ پیشوندیِ تصادفی** به‌تنهایی
     * می‌توانست کل تشخیص را ببرد.
     *
     * قاعده: یک شاهدِ ضعیفِ تنها کافی نیست. یا باید تطبیق از جنسِ دقیق/غلط
     * املایی باشد (یعنی کاربر واقعاً همان واژه را می‌خواسته)، یا دستِ‌کم دو
     * کلیدواژه‌ی متفاوت خورده باشد.
     *
     * @param  array{intent:array<string,mixed>,score:float,solidHits:int,hits:int}  $candidate
     */
    private function isSolid(array $candidate): bool
    {
        return $candidate['solidHits'] > 0 || $candidate['hits'] > 1;
    }

    /**
     * امتیازدهی موضوع‌ها بر پایه‌ی واژه‌های پیام.
     *
     * @param  array<int,string>  $tokens
     * @return array<int,array{intent:array<string,mixed>,score:float,solidHits:int,hits:int}>
     */
    private function rank(array $tokens): array
    {
        $scored = [];

        foreach (KnowledgeBase::intents() as $intent) {
            $score = 0.0;
            $hits = 0;
            $solidHits = 0;

            foreach ($intent['keywords'] as $keyword => $weight) {
                $best = 0.0;
                $bestKind = 'none';

                foreach ($tokens as $token) {
                    // بهترین تطبیقِ این کلیدواژه، نه اولین تطبیق: اگر پیام هم
                    // «پشتیبان» و هم «پشتیبانی» داشته باشد، باید امتیاز دقیق
                    // را بگیرد نه امتیاز هم‌ریشه.
                    $match = PersianText::match($token, (string) $keyword);

                    if ($match['score'] > $best) {
                        $best = $match['score'];
                        $bestKind = $match['kind'];
                    }

                    if ($best === 1.0) {
                        break;
                    }
                }

                if ($best > 0) {
                    $score += $weight * $best;
                    $hits++;

                    if (in_array($bestKind, ['exact', 'typo', 'inflection'], true)) {
                        $solidHits++;
                    }
                }
            }

            /*
             * پاداش برای چند کلیدواژه‌ی متفاوت.
             *
             * پیامی که سه واژه‌ی مرتبط دارد، خیلی محتمل‌تر از پیامی است که
             * یک واژه‌ی پروزن دارد؛ بدون این پاداش، یک کلمه‌ی تصادفی
             * می‌توانست کل تشخیص را ببرد.
             */
            if ($hits > 1) {
                $score *= 1 + (min($hits, 4) - 1) * 0.25;
            }

            if ($score > 0) {
                $scored[] = [
                    'intent' => $intent,
                    'score' => round($score, 2),
                    'solidHits' => $solidHits,
                    'hits' => $hits,
                ];
            }
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * وقتی چند موضوع نزدیک‌اند.
     *
     * @param  array<int,array{intent:array<string,mixed>,score:float,solidHits:int,hits:int}>  $ranked
     * @return array<string,mixed>
     */
    private function ambiguous(array $ranked): array
    {
        $options = array_slice($ranked, 0, 3);

        return [
            'intent' => 'ambiguous',
            'title' => null,
            'answer' => 'مطمئن نیستم دقیقاً کدام را می‌پرسید. کدام‌یک به سوالتان نزدیک‌تر است؟',
            'links' => [],
            'followUps' => array_map(
                fn (array $o) => 'درباره‌ی '.$o['intent']['title'],
                $options,
            ),
            'confident' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fallback(): array
    {
        return [
            'intent' => 'unknown',
            'title' => null,
            'answer' => 'برای این سوال پاسخ آماده‌ای ندارم.'
                ."\n".'می‌توانید یکی از موضوع‌های زیر را انتخاب کنید، یا از بخش «تماس با ما» پیام بدهید تا همکاران ما پاسخ بدهند.',
            'links' => [['label' => 'تماس با ما', 'href' => '/support']],
            'followUps' => KnowledgeBase::starters(),
            'confident' => false,
        ];
    }
}
