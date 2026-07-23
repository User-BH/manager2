<?php

namespace Tests\Feature;

use App\Services\Support\SupportBot;
use App\Support\PersianText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * چت پشتیبانی صفحه‌ی فرود.
 *
 * تشخیص موضوع با پرسش‌هایی آزموده می‌شود که کاربر واقعی می‌نویسد: محاوره‌ای،
 * بدون نیم‌فاصله، با «ی» و «ک» عربی و گاهی غلط املایی. اگر روزی کلیدواژه‌ای
 * عوض شود و یکی از این‌ها به موضوع اشتباه برود، همین‌جا معلوم می‌شود.
 */
class SupportChatTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function realQuestions(): array
    {
        return [
            'ثبت‌نام جدانوشته' => ['چطور ثبت نام کنم؟', 'signup'],
            'فراموشی رمز' => ['رمز عبورم را فراموش کردم', 'signup'],
            'ورود با موبایل' => ['با شماره موبایل وارد میشم؟', 'signup'],

            'قیمت محاوره‌ای' => ['قیمتش چنده؟', 'pricing'],
            'تفاوت پلن' => ['پلن پرو چه فرقی داره', 'pricing'],
            'اشتراک سالانه' => ['اشتراک سالانه چقدره', 'pricing'],

            'محاسبه شارژ' => ['شارژ رو چطوری حساب میکنید', 'charge'],
            'شارژ بر اساس متراژ' => ['محاسبه بر اساس متراژ هست؟', 'charge'],
            'جریمه دیرکرد' => ['جریمه دیرکرد داره؟', 'charge'],

            'پرداخت ساکن' => ['ساکنین چطور پول بدن', 'payment'],
            'اتصال درگاه' => ['درگاه بانکی وصل میشه؟', 'payment'],
            'ارسال فیش' => ['میشه فیش واریزی بفرستن', 'payment'],

            'امنیت محاوره‌ای' => ['اطلاعاتمون امنه؟', 'security'],
            'اشتراک با ثالث' => ['با کسی داده ما رو شریک میشید', 'security'],
            'نگهداری رمز' => ['رمز عبور چطور نگهداری میشه', 'security'],

            'بکاپ' => ['بکاپ میگیرید؟', 'backup'],
            'از دست رفتن داده' => ['اگه اطلاعات از دست بره چی', 'backup'],

            'چند مدیر' => ['چند تا مدیر میشه داشت', 'roles'],
            'مالک و مستاجر' => ['مستاجر و مالک فرق دارن؟', 'roles'],

            'پیام‌رسان جدانوشته' => ['پیام رسان چیکار میکنه', 'messenger'],
            'سنجاق اطلاعیه' => ['اطلاعیه میشه سنجاق کرد؟', 'messenger'],

            'دیدن دمو' => ['دمو دارید ببینم', 'demo'],
            'درباره‌ی ما' => ['شما کی هستید؟ رزومه دارید', 'about'],

            'تماس با پشتیبانی' => ['با پشتیبانی چطور حرف بزنم', 'contact'],
            'گزارش باگ' => ['میخوام باگ گزارش بدم', 'contact'],

            'خروجی اکسل' => ['خروجی اکسل داره؟', 'export'],
            'فاکتور PDF' => ['فاکتور پی دی اف میده؟', 'export'],

            'قوانین' => ['قوانین چیه', 'terms'],
        ];
    }

    #[DataProvider('realQuestions')]
    public function test_it_recognises_what_a_visitor_actually_asks(string $question, string $expected): void
    {
        $reply = app(SupportBot::class)->reply($question);

        $this->assertSame(
            $expected,
            $reply['intent'],
            "پرسش «{$question}» باید به موضوع «{$expected}» برود.",
        );
        $this->assertTrue($reply['confident'], "پاسخ «{$question}» باید قطعی باشد.");
        $this->assertNotEmpty($reply['answer']);
    }

    public function test_arabic_letters_and_persian_digits_do_not_break_matching(): void
    {
        // کاربر ایرانی «ي» و «ك» عربی تایپ می‌کند بی‌آنکه بداند
        $reply = app(SupportBot::class)->reply('چطور ثبت نام كنم؟');

        $this->assertSame('signup', $reply['intent']);
    }

    public function test_an_unrelated_question_is_answered_honestly(): void
    {
        /*
         * پاسخ غلطِ با اطمینان از «نمی‌دانم» بدتر است: کاربر را به بخش اشتباه
         * می‌فرستد و اعتمادش را از دست می‌دهد.
         */
        $reply = app(SupportBot::class)->reply('هوا امروز چطوره');

        $this->assertSame('unknown', $reply['intent']);
        $this->assertFalse($reply['confident']);
        $this->assertNotEmpty($reply['followUps']);
    }

    public function test_an_empty_message_does_not_crash(): void
    {
        $reply = app(SupportBot::class)->reply('؟؟؟');

        $this->assertSame('unknown', $reply['intent']);
    }

    /* --------------------- نقطه‌ی HTTP --------------------- */

    public function test_a_guest_can_use_the_chat(): void
    {
        // مخاطب اصلی، بازدیدکننده‌ی هنوز ثبت‌نام‌نکرده است
        $this->postJson('/api/support/chat', ['message' => 'هزینه‌ها چقدر است؟'])
            ->assertOk()
            ->assertJsonPath('intent', 'pricing')
            ->assertJsonStructure(['intent', 'answer', 'links', 'followUps', 'confident']);
    }

    public function test_the_starters_endpoint_gives_opening_questions(): void
    {
        $this->getJson('/api/support/starters')
            ->assertOk()
            ->assertJsonCount(4, 'starters');
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $this->postJson('/api/support/chat', ['message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_a_very_long_message_is_rejected(): void
    {
        $this->postJson('/api/support/chat', ['message' => str_repeat('ا', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_the_chat_is_rate_limited(): void
    {
        // نقطه عمومی است، پس سقف جای احراز هویت را می‌گیرد
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/support/chat', ['message' => 'سلام'])->assertOk();
        }

        $this->postJson('/api/support/chat', ['message' => 'سلام'])->assertStatus(429);
    }

    /* --------------------- نرمال‌سازی متن --------------------- */

    public function test_normalisation_unifies_arabic_and_persian_forms(): void
    {
        $this->assertSame(
            PersianText::normalize('كاركرد'),
            PersianText::normalize('کارکرد'),
        );

        // نیم‌فاصله حذف می‌شود نه اینکه به فاصله تبدیل شود
        $this->assertSame('میشود', PersianText::normalize('می‌شود'));

        // ارقام فارسی و عربی به لاتین
        $this->assertSame('12 34', PersianText::normalize('۱۲ ٣٤'));
    }

    public function test_adjacent_words_are_joined_for_multiword_keywords(): void
    {
        $terms = PersianText::searchTerms('ثبت نام');

        $this->assertContains('ثبتنام', $terms, 'جفت واژه‌های مجاور باید ساخته شود.');
    }

    public function test_an_exact_match_outranks_a_shared_stem(): void
    {
        /*
         * «پشتیبانی» و «پشتیبان» هم‌ریشه‌اند؛ اگر امتیاز یکسان بگیرند، سوال
         * تماس با پشتیبانی به موضوع بکاپ می‌رفت — که واقعاً می‌رفت.
         */
        $this->assertSame(1.0, PersianText::similarity('پشتیبانی', 'پشتیبانی'));
        $this->assertLessThan(1.0, PersianText::similarity('پشتیبانی', 'پشتیبان'));
    }
}
