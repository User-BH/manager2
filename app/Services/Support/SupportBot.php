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
        $tokens = PersianText::searchTerms($message);

        if ($tokens === []) {
            return $this->fallback();
        }

        $ranked = $this->rank($tokens);
        $best = $ranked[0] ?? null;

        if (! $best || $best['score'] < self::RELEVANT) {
            return $this->fallback();
        }

        // چند موضوع نزدیک‌به‌هم ⇒ به‌جای حدس‌زدن، از کاربر بپرس
        if ($best['score'] < self::CONFIDENT) {
            return $this->ambiguous($ranked);
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
     * امتیازدهی موضوع‌ها بر پایه‌ی واژه‌های پیام.
     *
     * @param  array<int,string>  $tokens
     * @return array<int,array{intent:array<string,mixed>,score:float}>
     */
    private function rank(array $tokens): array
    {
        $scored = [];

        foreach (KnowledgeBase::intents() as $intent) {
            $score = 0.0;
            $hits = 0;

            foreach ($intent['keywords'] as $keyword => $weight) {
                $best = 0.0;

                foreach ($tokens as $token) {
                    // بهترین تطبیقِ این کلیدواژه، نه اولین تطبیق: اگر پیام هم
                    // «پشتیبان» و هم «پشتیبانی» داشته باشد، باید امتیاز دقیق
                    // را بگیرد نه امتیاز هم‌ریشه.
                    $best = max($best, PersianText::similarity($token, (string) $keyword));

                    if ($best === 1.0) {
                        break;
                    }
                }

                if ($best > 0) {
                    $score += $weight * $best;
                    $hits++;
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
                $scored[] = ['intent' => $intent, 'score' => round($score, 2)];
            }
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * وقتی چند موضوع نزدیک‌اند.
     *
     * @param  array<int,array{intent:array<string,mixed>,score:float}>  $ranked
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
