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
 * ⚠️ طبقِ تصمیمِ ۳ قاعده‌محور می‌ماند؛ هیچ مدلِ زبانی یا سرویسِ بیرونی. (کامنتِ
 * قدیمیِ این‌جا به `AiSupportDriver` و `config('support.driver')` ارجاع می‌داد
 * که هیچ‌کدام هرگز وجود نداشتند — فاز ۱۵ پاکش کرد.) پیامِ کاربر هیچ‌جا ذخیره
 * نمی‌شود و در پاسخ بازتاب نمی‌یابد: هر پاسخ متنِ ثابتِ `KnowledgeBase` است.
 *
 * کیفیتِ تشخیص با `tests/fixtures/support-eval.json` سنجیده می‌شود
 * (`SupportBotEvaluationTest`)؛ هر تغییرِ کلیدواژه یا آستانه باید آن را سبز
 * نگه دارد.
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
     * دو موضوع که امتیازشان این‌قدر نزدیک است، «کدام را می‌پرسید؟» می‌گیرند.
     *
     * ⚠️ مجموعه‌ی ارزیابیِ فاز ۱۵ نشان داد بیشترِ پاسخ‌های «مطمئن ولی غلط»
     * از همین‌جا می‌آمدند: دو موضوعِ هم‌امتیاز و برنده‌ای که فقط یک واژه جلوتر
     * بود. وقتی فاصله این‌قدر کم است، پرسیدن صادقانه‌تر از حدس است.
     */
    private const CLOSE_CALL = 0.85;

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

        $runnerUp = $ranked[1] ?? null;
        $closeCall = $runnerUp !== null
            && $runnerUp['score'] >= $best['score'] * self::CLOSE_CALL
            && $this->isSolid($runnerUp);

        if ($best['score'] < self::CONFIDENT || ! $this->isSolid($best) || $closeCall) {
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
        $intents = KnowledgeBase::available();

        /*
         * واژه‌ای که خودش کلیدواژه‌ی شناخته‌شده است، غلطِ تایپیِ کلیدواژه‌ی
         * دیگر حساب نمی‌شود (مرحلهٔ ۶ · فاز ۱۵).
         *
         * «آنلاین» و «آفلاین» یک حرف فرق دارند؛ بی‌این قاعده «شارژمو آنلاین
         * بدم» با اطمینان به موضوعِ «نصب روی گوشی» (آفلاین) می‌رفت، و «واحد» به
         * «وارد» (ورود) امتیاز می‌داد. کاربری که واژه‌ی درستِ دیگری نوشته،
         * اشتباه تایپ نکرده.
         */
        $vocabulary = [];
        foreach ($intents as $intent) {
            foreach (array_keys($intent['keywords']) as $keyword) {
                $vocabulary[(string) $keyword] = true;
            }
        }

        foreach ($intents as $intent) {
            /*
             * 🔴 هر واژه‌ی پیام فقط **یک بار** شاهد است (مرحلهٔ ۶ · فاز ۱۵).
             *
             * پیش از این هر کلیدواژه جدا شمرده می‌شد: واژه‌ی تنهای «ساختمان» با
             * دو کلیدواژه‌ی «ساختمانم» و «ساختمانمرو» تطبیقِ پیشوندی می‌خورد و
             * **دو شاهد** حساب می‌شد — هم پاداشِ چندواژه‌ای می‌گرفت و هم «محکم»
             * به‌شمار می‌آمد، با اینکه هر دو شاهد ضعیف و از یک واژه بودند.
             * مجموعه‌ی ارزیابی نشان داد «شارژ ساختمان چطور حساب میشه» با همین با
             * اطمینان به موضوعِ پیوستن می‌رفت. حالا سهمِ هر واژه بهترین تطبیقِ
             * آن در همین موضوع است.
             *
             * @var array<string,array{score:float,kind:string}> $byToken
             */
            $byToken = [];

            foreach ($intent['keywords'] as $keyword => $weight) {
                $best = 0.0;
                $bestKind = 'none';
                $bestToken = null;

                foreach ($tokens as $token) {
                    // بهترین تطبیقِ این کلیدواژه، نه اولین تطبیق: اگر پیام هم
                    // «پشتیبان» و هم «پشتیبانی» داشته باشد، باید امتیاز دقیق
                    // را بگیرد نه امتیاز هم‌ریشه.
                    $match = PersianText::match($token, (string) $keyword);

                    if ($match['kind'] === 'typo' && isset($vocabulary[$token])) {
                        continue;
                    }

                    if ($match['score'] > $best) {
                        $best = $match['score'];
                        $bestKind = $match['kind'];
                        $bestToken = $token;
                    }

                    if ($best === 1.0) {
                        break;
                    }
                }

                if ($bestToken !== null && $weight * $best > ($byToken[$bestToken]['score'] ?? 0.0)) {
                    $byToken[$bestToken] = ['score' => $weight * $best, 'kind' => $bestKind];
                }
            }

            $score = array_sum(array_column($byToken, 'score'));
            $hits = count($byToken);
            $solidHits = count(array_filter(
                $byToken,
                fn (array $hit) => in_array($hit['kind'], ['exact', 'typo', 'inflection'], true),
            ));

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
