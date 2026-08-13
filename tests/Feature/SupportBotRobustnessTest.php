<?php

namespace Tests\Feature;

use App\Services\Support\KnowledgeBase;
use App\Services\Support\Lexicon;
use App\Services\Support\SupportBot;
use App\Support\PersianText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * استواریِ دستیار در برابرِ پرسشِ بیرون از مجموعه‌ی آماده (R34).
 *
 * ─── چرا جدا از `SupportChatTest` ───────────────────────────────────────────
 * آن تست می‌سنجد که پرسشِ **درست‌نوشته** به موضوعِ درست برود. اینجا سه چیزِ
 * دیگر سنجیده می‌شود: غلطِ املایی، واژه‌ی مترادف، و — مهم‌تر از هر دو —
 * پرسشی که اصلاً ربطی به این سامانه ندارد.
 *
 * ─── چرا موردِ سوم مهم‌ترین است ─────────────────────────────────────────────
 * دو مورد اول فقط «کمک‌نکردن»‌اند. موردِ سوم **آسیب‌زدن** است: پیش از این
 * «قیمت دلار چند شد» با اطمینانِ کامل پاسخِ **تعرفه‌های اشتراک** می‌گرفت و
 * «نتیجه بازی پرسپولیس چی شد» پاسخِ **پشتیبان‌گیری**. کاربر نتیجه می‌گیرد
 * سامانه‌ای که این را نمی‌فهمد، حرفِ درستی هم درباره‌ی پولش نمی‌زند.
 */
class SupportBotRobustnessTest extends TestCase
{
    /**
     * پرسش‌هایی که کاربر با غلطِ املایی می‌نویسد.
     *
     * همه‌ی این‌ها **یک** نویسه غلط دارند — همان چیزی که با لغزشِ انگشت روی
     * کیبورد رخ می‌دهد («ج» کنارِ «ژ»، «ح» کنارِ «خ»، «ن» کنارِ «م»).
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function misspelled(): array
    {
        return [
            'ثبت‌نام با ن به‌جای م' => ['چطور ثبت نان کنم؟', 'signup'],
            'گردم به‌جای کردم' => ['رمزمو فراموش گردم چیکار کنم', 'signup'],
            'پرداحت به‌جای پرداخت' => ['پرداحت انلاین چطوریه', 'payment'],
            'شارج به‌جای شارژ' => ['شارج ساختمان چطور حساب میشه', 'charge'],
            'نظرسنجی با رای‌گیری' => ['نظرسنجی رای گیری داریم؟', 'poll'],
        ];
    }

    /**
     * واژه‌ای دیگر برای همان مفهوم.
     *
     * «صورتحساب» را حسابدارِ مجتمع می‌گوید و «قبض» را ساکن؛ هر دو یک چیزند.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function synonymous(): array
    {
        return [
            'صورتحساب به‌جای قبض' => ['صورتحساب ماهانه چطور صادر میشه', 'charge'],
            'عضو شدن به‌جای ثبت‌نام' => ['میخوام عضو بشم', 'signup'],
            'محفوظ به‌جای امن' => ['اطلاعات شخصی ما محفوظه؟', 'security'],
            'لوله ترکیده' => ['لوله ترکیده به کی بگم', 'requests'],
            'چت گروهی' => ['چت گروهی با ساکنین دارید؟', 'messenger'],
        ];
    }

    /**
     * پرسش‌هایی که باید **همان پاسخِ پیش‌فرض** را بگیرند.
     *
     * دو تای اول عمداً واژه‌ی مشترک با دامنه دارند («قیمت»)؛ بدونِ وتوی
     * دامنه، امتیازدهی آن‌ها را پرسشِ کاملاً معتبری درباره‌ی تعرفه می‌دید.
     *
     * @return array<string,array{0:string}>
     */
    public static function offTopic(): array
    {
        return [
            'قیمت دلار' => ['قیمت دلار چند شد'],
            'قیمت طلا' => ['قیمت طلا امروز چند'],
            'فوتبال' => ['نتیجه بازی پرسپولیس چی شد'],
            'آب‌وهوا' => ['هوا امروز چطوره'],
            'فیلم' => ['بهترین فیلم سال چیه'],
            'توهین' => ['شما احمقید'],
            'واژه‌ی تنها' => ['بازی'],
        ];
    }

    #[DataProvider('misspelled')]
    public function test_one_wrong_letter_does_not_break_recognition(string $question, string $expected): void
    {
        $reply = app(SupportBot::class)->reply($question);

        $this->assertSame($expected, $reply['intent'], "پرسشِ «{$question}» باید به «{$expected}» برود.");
        $this->assertTrue($reply['confident'], "پاسخِ «{$question}» باید قطعی باشد.");
    }

    #[DataProvider('synonymous')]
    public function test_a_different_word_for_the_same_thing_is_understood(string $question, string $expected): void
    {
        $reply = app(SupportBot::class)->reply($question);

        $this->assertSame($expected, $reply['intent'], "پرسشِ «{$question}» باید به «{$expected}» برود.");
        $this->assertTrue($reply['confident']);
    }

    #[DataProvider('offTopic')]
    public function test_an_off_topic_question_gets_the_default_answer(string $question): void
    {
        $reply = app(SupportBot::class)->reply($question);

        $this->assertSame('unknown', $reply['intent'], "پرسشِ پرتِ «{$question}» نباید پاسخِ موضوعی بگیرد.");
        $this->assertFalse($reply['confident']);

        // پاسخِ پیش‌فرض باید راهِ ادامه بدهد، نه بن‌بست
        $this->assertNotEmpty($reply['followUps']);
        $this->assertNotEmpty($reply['links']);
    }

    /**
     * ⚠️ عصبانیت دلیلِ بی‌پاسخ‌گذاشتن نیست.
     *
     * اگر کاربر همراهِ گلایه پرسشِ واقعی هم بپرسد، باید پاسخش را بگیرد.
     * وگرنه دشنام‌یاب تبدیل می‌شود به راهی برای فرار از پاسخ‌دادن.
     */
    public function test_an_angry_user_with_a_real_question_still_gets_an_answer(): void
    {
        $reply = app(SupportBot::class)->reply('قبض شارژتون مزخرفه، چطور اصلاحش کنم؟');

        $this->assertSame('charge', $reply['intent']);
        $this->assertTrue($reply['confident']);
    }

    /**
     * ⚠️ واژه‌ی بیرون‌ازدامنه **دقیق** سنجیده می‌شود، نه پیشوندی.
     *
     * «هوا» در فهرستِ پرت نیست ولی «هواشناسی» هست؛ اگر تطبیق پیشوندی بود،
     * «هواکش» — که یک درخواستِ تعمیراتِ کاملاً واقعی است — وتو می‌شد.
     */
    public function test_the_out_of_domain_veto_does_not_swallow_a_real_request(): void
    {
        $this->assertFalse(Lexicon::isOutOfDomain('هواکش پارکینگ خراب شده'));
        $this->assertTrue(Lexicon::isOutOfDomain('هواشناسی امروز چی گفته'));
    }

    /**
     * ⚠️ «کدام‌یک را می‌پرسید؟» با یک گزینه بی‌معناست.
     *
     * پیش از این، پیامی که فقط یک نامزدِ ضعیف داشت همین جمله را می‌گرفت و
     * زیرش **یک** دکمه — یعنی سوالی که خودش جوابش را نشان می‌داد.
     */
    public function test_a_single_weak_candidate_falls_back_instead_of_asking_which_one(): void
    {
        $reply = app(SupportBot::class)->reply('بازی');

        $this->assertSame('unknown', $reply['intent']);
        $this->assertNotSame('ambiguous', $reply['intent']);
    }

    /* --------------------- شکلِ واژه‌نامه --------------------- */

    /**
     * هر مترادف باید به کلیدواژه‌ی **موجود** اشاره کند.
     *
     * سطری که مقصدش کلیدواژه نیست هیچ خطایی نمی‌دهد، فقط بی‌اثر است — و
     * بی‌اثر بودنش تا وقتی کسی دستی امتحان نکند معلوم نمی‌شود. شش سطر از
     * نسخه‌ی اولِ این فهرست دقیقاً همین بودند.
     */
    public function test_every_synonym_points_at_a_real_keyword(): void
    {
        $keywords = [];

        foreach (KnowledgeBase::intents() as $intent) {
            foreach (array_keys($intent['keywords']) as $keyword) {
                $keywords[(string) $keyword] = true;
            }
        }

        foreach (Lexicon::synonyms() as $source => $canonical) {
            $this->assertArrayHasKey(
                $canonical,
                $keywords,
                "مترادفِ «{$source}» به «{$canonical}» اشاره می‌کند که کلیدواژه نیست، پس بی‌اثر است.",
            );
        }
    }

    /**
     * ⚠️ هیچ مترادفی نباید خودش از قبل تطبیق بخورد.
     *
     * وگرنه یک مفهوم **دوبار** امتیاز می‌گیرد و موضوعی که واژه‌ی پرتکرارتری
     * دارد برنده می‌شود. در نسخه‌ی اول ۲۴ سطر این عیب را داشتند و یکی‌شان
     * («رمزعبور» → «پسورد») پرسشِ نگهداریِ رمز را از امنیت به ثبت‌نام برد.
     */
    public function test_no_synonym_double_counts_an_existing_keyword(): void
    {
        $keywords = [];

        foreach (KnowledgeBase::intents() as $intent) {
            foreach (array_keys($intent['keywords']) as $keyword) {
                $keywords[] = (string) $keyword;
            }
        }

        foreach (array_keys(Lexicon::synonyms()) as $source) {
            foreach ($keywords as $keyword) {
                $this->assertSame(
                    0.0,
                    PersianText::similarity((string) $source, $keyword),
                    "مترادفِ «{$source}» خودش با کلیدواژه‌ی «{$keyword}» تطبیق می‌خورد، پس امتیاز دوبار شمرده می‌شود.",
                );
            }
        }
    }

    /* --------------------- لایه‌ی متن --------------------- */

    /**
     * ⚠️ `levenshtein()`ِ خودِ PHP بایتی است و برای فارسی عددِ بی‌معنا می‌دهد.
     */
    public function test_edit_distance_counts_letters_not_bytes(): void
    {
        // یک حرف فرق، ولی دو بایت
        $this->assertSame(1, PersianText::distance('شارژ', 'شارج'));
        $this->assertSame(0, PersianText::distance('قبض', 'قبض'));
        $this->assertSame(2, PersianText::distance('اطلاعات', 'اطلاعیه'));

        // همان مقایسه با تابعِ بایتیِ PHP عددِ دیگری می‌دهد
        $this->assertNotSame(1, levenshtein('شارژ', 'شارج'));
    }

    /**
     * جنسِ تطبیق، نه فقط امتیازش.
     *
     * این تفکیک کلِ ستونِ فقراتِ R34 است: «بازی» و «هزینه‌ها» هر دو تطبیقِ
     * پیشوندی‌اند و امتیازِ یکسان می‌گیرند، ولی یکی شاهدِ ضعیف است و دیگری
     * محکم.
     */
    public function test_match_kind_separates_a_typo_from_a_coincidental_prefix(): void
    {
        // کاربر همین واژه را می‌خواسته، یک حرف اشتباه زده
        $this->assertSame('typo', PersianText::match('شارج', 'شارژ')['kind']);

        // واژه‌ی کاربر بلندتر است ⇒ همان کلیدواژه با پسوندِ صرفی
        $this->assertSame('inflection', PersianText::match('هزینهها', 'هزینه')['kind']);

        // واژه‌ی کاربر کوتاه‌تر و مستقل است ⇒ تصادفاً پیشوند شده
        $this->assertSame('stem', PersianText::match('بازی', 'بازیابی')['kind']);

        $this->assertSame('exact', PersianText::match('قبض', 'قبض')['kind']);
        $this->assertSame('none', PersianText::match('دلار', 'قبض')['kind']);
    }

    /**
     * ⚠️ فاصله‌ی **دو** عمداً پذیرفته نمی‌شود.
     *
     * «اطلاعات» و «اطلاعیه» هر دو هفت‌نویسه‌اند و دو ویرایش فاصله دارند، ولی
     * دو واژه‌ی متفاوتِ پرکاربردند. وقتی این ردیف را داشتم، «اطلاعات شخصی ما
     * محفوظه؟» از امنیت به پیام‌رسان می‌رفت.
     */
    public function test_two_edits_apart_is_not_treated_as_a_typo(): void
    {
        $this->assertSame(0.0, PersianText::similarity('اطلاعات', 'اطلاعیه'));
    }
}
