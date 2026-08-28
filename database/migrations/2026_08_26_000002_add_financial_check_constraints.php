<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قیدهای دیتابیسی روی مقادیرِ مالی (R52).
 *
 * ─── چرا لایه‌ی برنامه کافی نیست ────────────────────────────────────────────
 * ⚠️ پیش از این **صفر** check constraint در کلِ ۴۷ مهاجرت وجود داشت. یعنی
 * دیتابیس هیچ دفاعِ خودی نداشت: اگر باگی در کد مبلغِ منفی می‌نوشت، یا یک
 * دستورِ artisan، یا اصلاحی دستی در phpMyAdmin — دیتابیس بی‌چون‌وچرا
 * می‌پذیرفت.
 *
 * اعتبارسنجیِ `FormRequest` فقط مسیرِ HTTP را می‌گیرد. مهاجرت‌ها، صف،
 * دستورهای کنسولی و بازیابیِ بکاپ همه از کنارش رد می‌شوند. قید در
 * دیتابیس **آخرین** خطِ دفاع است و تنها خطی که هیچ مسیری دورش نمی‌زند.
 *
 * ─── سنجشِ پشتیبانی، نه فرض ────────────────────────────────────────────────
 * ⚠️ فرضِ اولیه این بود که SQLite افزودنِ CHECK به جدولِ موجود را
 * پشتیبانی نمی‌کند. **اندازه‌گیری خلافش را نشان داد:** دستور پذیرفته
 * می‌شود، قید در `sqlite_master` می‌نشیند و **واقعاً اعمال می‌شود**.
 *
 * پس برخلافِ `lockForUpdate` (که SQLite بی‌صدا حذفش می‌کند)، این محافظت
 * در CI هم **قابلِ اثبات** است — و تست‌های `SchemaConstraintTest` دقیقاً
 * همان را می‌سنجند.
 */
return new class extends Migration
{
    /**
     * قیدها: جدول ← [نام، شرط].
     *
     * ⚠️ هر شرط پیش از افزوده‌شدن روی داده‌ی موجود سنجیده می‌شود. قیدی که
     * روی داده‌ی امروز نقض شود، مهاجرت را وسطِ استقرار می‌ترکاند.
     *
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    private const CONSTRAINTS = [
        /*
         * مبلغِ پرداخت باید **اکیداً مثبت** باشد.
         *
         * پرداختِ صفر معنایی ندارد و فقط ردیفی می‌سازد که در گزارش‌ها
         * دیده می‌شود ولی هیچ پولی پشتش نیست.
         */
        'payments' => [
            ['chk_payments_amount_positive', 'amount > 0'],
        ],

        /*
         * ⚠️ در دفترِ کیف، **جهت** علامت را حمل می‌کند نه مبلغ.
         *
         * ردیفی با مبلغِ منفی یعنی دو جای متفاوت علامت را نگه می‌دارند و
         * جمعِ دفتر به عددی می‌رسد که هیچ‌کس نمی‌تواند توضیحش دهد.
         */
        'wallet_transactions' => [
            ['chk_wallet_amount_positive', 'amount > 0'],
            ['chk_wallet_balance_non_negative', 'balance_after >= 0'],
        ],

        'payment_allocations' => [
            ['chk_allocation_amount_positive', 'amount > 0'],
        ],

        /*
         * ⚠️ `paid_amount <= total_amount` عمداً **اینجا نیست**.
         *
         * سنجش نشان داد تولیدِ دوباره‌ی قبض می‌تواند نقضش کند: قبضِ
         * ۱۵۰٬۰۰۰ با ۱۰۰٬۰۰۰ پرداختی، پس از تغییرِ قواعدِ شارژ `total = 0`
         * می‌گرفت. آن رفتار در همین مرحله اصلاح شد (مازاد به کیف می‌رود)،
         * ولی افزودنِ قید پیش از اطمینان از داده‌ی محصول، ریسکِ شکستنِ
         * استقرار دارد. جایش `SchemaConstraintTest` این ناوردایی را در
         * سطحِ برنامه نگه می‌دارد.
         */
        'bills' => [
            ['chk_bills_total_non_negative', 'total_amount >= 0'],
            ['chk_bills_paid_non_negative', 'paid_amount >= 0'],
            ['chk_bills_base_non_negative', 'base_amount >= 0'],
            ['chk_bills_penalty_non_negative', 'penalty_amount >= 0'],
            ['chk_bills_discount_non_negative', 'discount_amount >= 0'],
        ],

        'expenses' => [
            ['chk_expenses_amount_non_negative', 'amount >= 0'],
        ],

        'incomes' => [
            ['chk_incomes_amount_non_negative', 'amount >= 0'],
        ],

        'discounts' => [
            ['chk_discounts_amount_non_negative', 'amount >= 0'],
        ],

        'subscriptions' => [
            ['chk_subscriptions_amount_non_negative', 'amount >= 0'],
        ],

        /*
         * ⚠️ `area >= 0` و نه `> 0` — و این تصحیحِ فرضِ خودم است.
         *
         * قیدِ اولیه `area > 0` بود، با این استدلال که تقسیم بر صفر
         * محاسبه‌ی شارژ را می‌ترکاند. یک تستِ موجود شکست و نشان داد
         * استدلال غلط است:
         *
         * ستونِ `area` در schema **پیش‌فرضِ صفر** دارد، یعنی صفر معنای
         * «هنوز ثبت نشده» را می‌دهد — و برنامه خودش مدیریتش می‌کند
         * (نظرسنجیِ وزنیِ متراژ `weightUnavailable` برمی‌گرداند به‌جای
         * اینکه بترکد).
         *
         * پس `> 0` با پیش‌فرضِ خودِ schema در تناقض بود و ساختنِ واحد
         * بدونِ متراژ را غیرممکن می‌کرد. آنچه واقعاً بی‌معناست، متراژِ
         * **منفی** است.
         */
        'units' => [
            ['chk_units_area_non_negative', 'area >= 0'],
        ],

        /*
         * سهمِ مالکیت خارج از بازه‌ی ۰ تا ۱۰۰، سهمِ شارژ را به عددی
         * می‌برد که با واقعیت نمی‌خواند.
         */
        'unit_user' => [
            ['chk_unit_user_share_range', 'share_percent >= 0 and share_percent <= 100'],
        ],
    ];

    public function up(): void
    {
        $violations = $this->violations();

        if ($violations !== []) {
            /*
             * ⚠️ مهاجرت **پیش از** تغییرِ چیزی می‌ایستد.
             *
             * افزودنِ قید به جدولی که ردیفِ ناقض دارد، در MySQL با خطای خام
             * می‌شکند — وسطِ استقرار، پس از اینکه بخشی از مهاجرت‌ها اجرا
             * شده‌اند. این پیام می‌گوید دقیقاً کدام ردیف‌ها مشکل دارند تا
             * پیش از استقرارِ دوباره اصلاح شوند.
             */
            throw new RuntimeException(
                "داده‌ی موجود این قیدها را نقض می‌کند و مهاجرت اجرا نشد:\n  "
                .implode("\n  ", $violations)
                ."\n\nابتدا این ردیف‌ها را اصلاح کنید، سپس دوباره مهاجرت بگیرید."
            );
        }

        foreach (self::CONSTRAINTS as $table => $rules) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($rules as [$name, $condition]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$condition})");
            }
        }
    }

    /**
     * ردیف‌هایی که قیدِ تازه را نقض می‌کنند.
     *
     * @return array<int, string>
     */
    private function violations(): array
    {
        $found = [];

        foreach (self::CONSTRAINTS as $table => $rules) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($rules as [$name, $condition]) {
                $bad = DB::table($table)->whereRaw("not ({$condition})")->count();

                if ($bad > 0) {
                    $found[] = "{$table}: {$bad} ردیف شرطِ «{$condition}» را ندارد";
                }
            }
        }

        return $found;
    }

    public function down(): void
    {
        foreach (self::CONSTRAINTS as $table => $rules) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($rules as [$name, $condition]) {
                try {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                } catch (Throwable) {
                    /*
                     * ⚠️ SQLite حذفِ قید را پشتیبانی نمی‌کند و MySQL هم اگر
                     * قید وجود نداشته باشد خطا می‌دهد. بازگشتِ مهاجرت نباید
                     * به‌خاطرِ قیدی که از قبل نیست بشکند.
                     */
                }
            }
        }
    }
};
