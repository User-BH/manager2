<?php

namespace Tests\Feature;

use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Account\PublicJoinLink;
use App\Services\Charge\BillGenerator;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * قیدهای دیتابیس و قواعدِ یکپارچگیِ Schema (R52).
 *
 * ─── چرا قید در دیتابیس، وقتی اعتبارسنجی داریم ──────────────────────────────
 * ⚠️ پیش از این **صفر** check constraint در کلِ ۴۷ مهاجرت بود.
 *
 * `FormRequest` فقط مسیرِ HTTP را می‌گیرد. مهاجرت‌ها، صف، دستورهای
 * کنسولی، بازیابیِ بکاپ و اصلاحِ دستی در phpMyAdmin همه از کنارش رد
 * می‌شوند. قید در دیتابیس **آخرین** خطِ دفاع است و تنها خطی که هیچ
 * مسیری دورش نمی‌زند.
 *
 * ─── و برخلافِ قفل، این یکی در CI اثبات‌پذیر است ────────────────────────────
 * ⚠️ فرضِ اولیه این بود که SQLite افزودنِ CHECK به جدولِ موجود را
 * پشتیبانی نمی‌کند. اندازه‌گیری خلافش را نشان داد: قید در
 * `sqlite_master` می‌نشیند و **واقعاً اعمال می‌شود**. پس این تست‌ها
 * رفتارِ واقعیِ محصول را می‌سنجند، نه یک تقریب.
 */
class SchemaConstraintTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unit;

    /** صاحبِ ردیف‌های دفترِ کیف — کیف مالِ حساب است (مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶) */
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();
        $this->unit = Unit::factory()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => '۱',
        ]);
        $this->owner = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner]);
    }

    /**
     * آیا قیدها روی این موتور واقعاً نصب شده‌اند؟
     *
     * ⚠️ این را **از خودِ دیتابیس** می‌پرسیم، نه از نامِ موتور. مهاجرت
     * وقتی `ALTER TABLE ... ADD CONSTRAINT` را نداشته باشد از خیرِ قیدها
     * می‌گذرد، و تنها راهِ مطمئن این است که ببینیم قید سرِ جایش هست یا نه.
     */
    private function constraintsAreInstalled(): bool
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $table = $connection->selectOne(
                "select sql from sqlite_master where type = 'table' and name = 'payments'",
            );

            return $table !== null
                && str_contains((string) $table->sql, 'chk_payments_amount_positive');
        }

        return $connection->table('information_schema.check_constraints')
            ->where('constraint_name', 'chk_payments_amount_positive')
            ->exists();
    }

    /**
     * ⚠️ دروازه‌ای که نمی‌گذارد نبودِ قید به «تستِ سبز» تبدیل شود.
     *
     * ─── چرا skip و نه pass ─────────────────────────────────────────────
     * روی SQLiteِ قدیمی (رانرِ CI) قیدها اصلاً نصب نمی‌شوند. اگر این
     * تست‌ها همان‌جا سبز می‌شدند، سبزیِ CI معنایش این می‌شد که «قیدها کار
     * می‌کنند» — در حالی که هیچ قیدی وجود ندارد. skip در گزارش دیده
     * می‌شود؛ pass دیده نمی‌شود.
     *
     * ─── چرا skip هم کافی نیست ──────────────────────────────────────────
     * skipِ همیشگی یعنی این محافظت **هرگز** سنجیده نمی‌شود. پس jobِ
     * «قیدهای دیتابیس روی MySQL» با `REQUIRE_DB_CONSTRAINTS=1` اجرا
     * می‌شود و در آن حالت نبودِ قید **شکست** است، نه skip — یعنی روی
     * موتورِ واقعیِ محصول این تست‌ها حتماً اجرا می‌شوند.
     */
    private function requireConstraints(): void
    {
        if ($this->constraintsAreInstalled()) {
            return;
        }

        $engine = DB::connection()->getDriverName();

        if (getenv('REQUIRE_DB_CONSTRAINTS') === '1') {
            $this->fail(
                "قیدهای CHECK روی «{$engine}» نصب نشده‌اند، ولی "
                .'REQUIRE_DB_CONSTRAINTS=1 است. مهاجرتِ قیدها اجرا نشده '
                .'یا موتور ALTER TABLE ... ADD CONSTRAINT را نپذیرفته.',
            );
        }

        $this->markTestSkipped(
            "موتورِ «{$engine}» دستورِ ALTER TABLE ... ADD CONSTRAINT را "
            .'نپذیرفت، پس قیدی نصب نشده و این تست چیزی برای سنجیدن ندارد. '
            .'سنجشِ واقعی در jobِ «قیدهای دیتابیس روی MySQL» انجام می‌شود.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  قیدها واقعاً اعمال می‌شوند
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ هر قید با **درجِ مستقیم در دیتابیس** سنجیده می‌شود، نه از راهِ مدل.
     *
     * ─── چرا `DB::table()` و نه Eloquent ────────────────────────────────────
     * اگر از مدل استفاده می‌کردیم، ممکن بود اعتبارسنجیِ لایه‌ی برنامه
     * جلویش را بگیرد و ما به‌غلط نتیجه بگیریم «قید کار می‌کند». درجِ
     * مستقیم دقیقاً همان مسیری است که مهاجرت، صف و phpMyAdmin از آن
     * می‌روند — یعنی همان جایی که قید تنها دفاع است.
     *
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('forbiddenRows')]
    public function test_the_database_rejects_invalid_values(string $table, array $row, string $why): void
    {
        $this->requireConstraints();

        $row = $this->withKeys($table, $row);

        try {
            DB::table($table)->insert($row);

            $this->fail("دیتابیس {$why} را پذیرفت؛ قید اعمال نمی‌شود.");
        } catch (QueryException $e) {
            /*
             * ⚠️ «check constraint»، نه فقط «constraint» (مرحلهٔ ۹ · اصلاحاتِ پس از
             * فاز ۶): SQLite ستونِ اجباریِ جاافتاده را با «NOT NULL constraint
             * failed» رد می‌کند؛ پس ردیفی که `user_id` ِ تازه‌ی دفترِ کیف را نداشت
             * این‌جا سبز بود و فقط MySQL ِ CI (خطای ۱۳۶۴) لو داد که قیدِ CHECK اصلاً
             * سنجیده نشده.
             */
            $this->assertStringContainsStringIgnoringCase(
                'check constraint',
                $e->getMessage(),
                "درج رد شد ولی نه به‌خاطرِ قیدِ CHECK: {$e->getMessage()}",
            );
        }
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function forbiddenRows(): array
    {
        return [
            'پرداختِ منفی' => ['payments',
                ['amount' => -500, 'method' => 'cash', 'status' => 'success'], 'پرداختِ منفی'],
            'پرداختِ صفر' => ['payments',
                ['amount' => 0, 'method' => 'cash', 'status' => 'success'], 'پرداختِ صفر'],
            'قبضِ منفی' => ['bills',
                ['period' => '1405-06', 'total_amount' => -1, 'paid_amount' => 0], 'قبضِ با مبلغِ منفی'],
            'پرداختیِ منفیِ قبض' => ['bills',
                ['period' => '1405-07', 'total_amount' => 100, 'paid_amount' => -1], 'قبضِ با پرداختیِ منفی'],
            'جریمهٔ منفی' => ['bills',
                ['period' => '1405-08', 'total_amount' => 100, 'paid_amount' => 0, 'penalty_amount' => -1],
                'جریمهٔ منفی'],
            'تخفیفِ منفی' => ['bills',
                ['period' => '1405-09', 'total_amount' => 100, 'paid_amount' => 0, 'discount_amount' => -1],
                'تخفیفِ منفی'],
            'هزینهٔ منفی' => ['expenses',
                ['title' => 'x', 'amount' => -1, 'category' => 'owner', 'period' => '1405-06',
                    'spend_date' => '2026-08-01'], 'هزینهٔ منفی'],
            'درآمدِ منفی' => ['incomes',
                ['title' => 'x', 'amount' => -1, 'period' => '1405-06',
                    'received_date' => '2026-08-01'], 'درآمدِ منفی'],
            'ردیفِ دفتر با مبلغِ صفر' => ['wallet_transactions',
                ['direction' => 'credit', 'amount' => 0, 'balance_after' => 0, 'source' => 'manual'],
                'ردیفِ دفتر با مبلغِ صفر'],
            'مانده‌ی منفیِ دفتر' => ['wallet_transactions',
                ['direction' => 'debit', 'amount' => 10, 'balance_after' => -10, 'source' => 'manual'],
                'مانده‌ی منفی در دفتر'],
            // مرحلهٔ ۱۰ · فاز ۲: چرخه‌ی عمرِ عضویت در خودِ پایگاه‌داده
            'وضعیتِ ناشناخته‌ی عضویت' => ['complex_memberships',
                ['role' => 'owner', 'status' => 'paused', 'source' => 'direct', 'started_at' => '2026-10-01 00:00:00',
                    'ended_at' => '2026-10-02 00:00:00'], 'عضویت با وضعیتِ بیرون از active/suspended/ended'],
            // مرحلهٔ ۱۰ · فاز ۶: تعلیق یعنی زمانِ تعلیق، و فعال یعنی بی آن
            'عضویتِ معلق بی زمانِ تعلیق' => ['complex_memberships',
                ['role' => 'owner', 'status' => 'suspended', 'source' => 'direct', 'started_at' => '2026-10-01 00:00:00',
                    'active_key' => '999997:999997'], 'عضویتِ معلق بی زمانِ تعلیق'],
            'عضویتِ فعال با زمانِ تعلیق' => ['complex_memberships',
                ['role' => 'owner', 'status' => 'active', 'source' => 'direct', 'started_at' => '2026-10-01 00:00:00',
                    'suspended_at' => '2026-10-02 00:00:00', 'active_key' => '999996:999996'], 'عضویتِ فعال که زمانِ تعلیق دارد'],
            'عضویتِ معلق بی کلیدِ یکتا' => ['complex_memberships',
                ['role' => 'owner', 'status' => 'suspended', 'source' => 'direct', 'started_at' => '2026-10-01 00:00:00',
                    'suspended_at' => '2026-10-02 00:00:00'], 'عضویتِ معلق که جای «عضویتِ زنده» را نگرفته'],
            'عضویتِ فعال با زمانِ پایان' => ['complex_memberships',
                ['role' => 'owner', 'status' => 'active', 'source' => 'direct', 'started_at' => '2026-10-01 00:00:00',
                    'ended_at' => '2026-10-02 00:00:00', 'active_key' => '999999:999999'], 'عضویتِ فعال که پایان هم دارد'],
            'عضویتِ پایان‌یافته با کلیدِ فعال' => ['complex_memberships',
                ['role' => 'owner', 'status' => 'ended', 'source' => 'direct', 'started_at' => '2026-10-01 00:00:00',
                    'ended_at' => '2026-10-02 00:00:00', 'active_key' => '999998:999998'], 'عضویتِ پایان‌یافته که هنوز کلیدِ یکتای فعال را گرفته'],
            'نقشِ سکو در عضویت' => ['complex_memberships',
                ['role' => 'super_admin', 'status' => 'ended', 'source' => 'direct', 'started_at' => '2026-10-01 00:00:00',
                    'ended_at' => '2026-10-02 00:00:00'], 'عضویتِ مجتمع با نقشِ ادمینِ کل'],
        ];
    }

    /**
     * کلیدهای خارجیِ لازم را اضافه می‌کند — ولی **فقط آن‌هایی که جدول دارد**.
     *
     * ⚠️ نسخه‌ی اول کورکورانه `unit_id` می‌گذاشت و روی `expenses` و
     * `incomes` (که چنین ستونی ندارند) شکست خورد. تست آن را **به‌درستی**
     * گرفت، چون ادعا می‌کند درج باید به‌خاطرِ **قید** رد شود نه به هر
     * دلیلی — وگرنه یک ستونِ اشتباه هم شبیهِ «قید کار می‌کند» به نظر
     * می‌رسید.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withKeys(string $table, array $row): array
    {
        $keys = [];

        foreach (['complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'user_id' => $this->owner->id] as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $keys[$column] = $value;
            }
        }

        return array_merge($keys, $row);
    }

    /** مقدارِ درست باید بی‌دردسر پذیرفته شود — وگرنه قید بیش‌ازحد سخت‌گیر است. */
    public function test_valid_values_are_still_accepted(): void
    {
        DB::table('payments')->insert($this->withKeys('payments', [
            'amount' => 1, 'method' => 'cash', 'status' => 'success',
        ]));

        DB::table('bills')->insert($this->withKeys('bills', [
            'period' => '1405-06', 'total_amount' => 0, 'paid_amount' => 0,
        ]));

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('bills')->count());
    }

    /**
     * ⚠️ سهمِ مالکیت باید در بازه‌ی ۰ تا ۱۰۰ بماند.
     *
     * سهمِ ۱۵۰٪ یعنی یک واحد بیش از خودش شارژ بپردازد، و سهمِ منفی یعنی
     * سامانه به مالک پول بدهکار شود — هر دو از یک غلطِ تایپیِ ساده
     * می‌آیند.
     */
    public function test_the_ownership_share_stays_within_range(): void
    {
        $this->requireConstraints();

        $user = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner->value,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        DB::table('unit_user')->insert([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'user_id' => $user->id,
            'relation' => 'owner',
            'share_percent' => 150,
            'is_current' => true,
        ]);
    }

    /**
     * متراژِ **منفی** بی‌معناست — ولی صفر مجاز است.
     *
     * ⚠️ تصحیحِ فرضِ اولیه: قید را `area > 0` گذاشته بودم و یک تستِ موجود
     * شکست. ستونِ `area` در schema پیش‌فرضِ صفر دارد، یعنی صفر یعنی
     * «هنوز ثبت نشده» و برنامه خودش مدیریتش می‌کند. قیدِ `> 0` با
     * پیش‌فرضِ خودِ schema در تناقض بود.
     */
    public function test_a_unit_cannot_have_negative_area(): void
    {
        $this->requireConstraints();

        $this->expectException(QueryException::class);

        DB::table('units')->insert([
            'complex_id' => $this->complex->id,
            'unit_number' => '۹۹',
            'area' => -1,
            'residents_count' => 1,
            'floor' => 1,
        ]);
    }

    /** و صفر باید بپذیرد — «متراژ هنوز ثبت نشده» حالتِ مجازِ محصول است. */
    public function test_a_unit_may_have_no_area_recorded_yet(): void
    {
        DB::table('units')->insert([
            'complex_id' => $this->complex->id,
            'unit_number' => '۱۰۰',
            'area' => 0,
            'residents_count' => 1,
            'floor' => 1,
        ]);

        $this->assertSame(1, DB::table('units')->where('unit_number', '۱۰۰')->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ناوردایی‌ای که در دیتابیس نیست ولی باید نگه داشته شود
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ تولیدِ دوباره‌ی قبض نباید پولِ پرداخت‌شده را ناپدید کند.
     *
     * ─── نقصی که تلاش برای افزودنِ قید پیدا کرد ─────────────────────────────
     * می‌خواستیم `paid_amount <= total_amount` را به دیتابیس اضافه کنیم.
     * سنجش نشان داد **همین جریان نقضش می‌کند**: قبضِ ۱۵۰٬۰۰۰ که ۱۰۰٬۰۰۰
     * آن پرداخت شده بود، پس از تغییرِ قواعدِ شارژ `total = 0` گرفت و
     * `paid = 100000` ماند.
     *
     * یعنی ساکن صد هزار تومان بیشتر از بدهی‌اش پرداخت کرده بود و سامانه نه
     * ثبتش می‌کرد نه برش می‌گرداند — **همان دسته‌ی گم‌شدنِ پولِ R51، از
     * راهی دیگر**.
     *
     * حالا مابه‌التفاوت به کیف می‌رود. این تست هم ناوردایی را نگه می‌دارد و
     * هم دلیلِ نبودنِ آن قید در دیتابیس را مستند می‌کند.
     */
    public function test_regenerating_a_bill_downward_credits_the_wallet(): void
    {
        $bill = Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => Jalali::currentPeriod(),
            'base_amount' => 150000,
            'total_amount' => 150000,
            'paid_amount' => 100000,
            'status' => BillStatus::Partial,
            'due_date' => now()->addDays(10),
        ]);

        /*
         * مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶: پولِ پرداخت‌شده صاحب دارد — همان کسی که
         * پرداخت کرد — و بازگشت به کیفِ **او** می‌رود، نه به «کیفِ واحد».
         */
        $payer = User::factory()->create(['complex_id' => $this->complex->id, 'role' => UserRole::Owner]);
        $payment = Payment::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'unit_id' => $this->unit->id, 'bill_id' => $bill->id,
            'user_id' => $payer->id, 'amount' => 100000, 'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Success, 'period' => $bill->period,
        ]);
        PaymentAllocation::withoutGlobalScopes()->create([
            'complex_id' => $this->complex->id, 'payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 100000,
        ]);

        app(BillGenerator::class)->generate($this->complex, Jalali::currentPeriod());

        $fresh = $bill->fresh();
        $wallet = app(WalletService::class)->balance($payer);

        // ⚠️ ناوردایی: هر ریالی که پرداخت شده یا روی قبض است یا در کیف
        $this->assertEqualsWithDelta(
            100000,
            (float) $fresh->paid_amount + $wallet,
            0.01,
            'پولِ پرداخت‌شده پس از کاهشِ قبض گم شد.',
        );

        $this->assertLessThanOrEqual(
            (float) $fresh->total_amount + 0.01,
            (float) $fresh->paid_amount,
            'قبض بیش از مبلغِ کلش پرداخت‌شده ماند.',
        );
    }

    /** و اگر مبلغ کاهش نیابد، ردیفِ مازادِ بی‌مورد ساخته نشود. */
    public function test_regenerating_without_a_drop_creates_no_surplus(): void
    {
        Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => Jalali::currentPeriod(),
            'base_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_date' => now()->addDays(10),
        ]);

        app(BillGenerator::class)->generate($this->complex, Jalali::currentPeriod());

        $this->assertSame(0, WalletTransaction::withoutGlobalScopes()->where('complex_id', $this->complex->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  قراردادِ ساختاری
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مهاجرتِ قیدها باید پیش از اعمال، داده‌ی موجود را بسنجد.
     *
     * ─── چرا این حیاتی است ──────────────────────────────────────────────────
     * افزودنِ قید به جدولی که ردیفِ ناقض دارد، وسطِ استقرار با خطای خام
     * می‌شکند — پس از اینکه بخشی از مهاجرت‌ها اجرا شده‌اند. این دقیقاً
     * هنگامِ نوشتنِ همین مهاجرت اتفاق افتاد: محافظ دو ردیفِ ناقض را گرفت
     * و پیامِ روشن داد به‌جای خطای SQL.
     */
    public function test_the_constraint_migration_checks_data_before_applying(): void
    {
        $source = collect(File::files(base_path('database/migrations')))
            ->first(fn ($f) => str_contains($f->getFilename(), 'check_constraints'));

        $this->assertNotNull($source, 'مهاجرتِ قیدها پیدا نشد.');

        $code = (string) file_get_contents($source->getPathname());

        // کامنت‌ها پاک می‌شوند تا توضیحِ خودِ فایل تست را سبز نکند
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        /*
         * ⚠️ روی خودِ **فراخوانی** لنگر می‌اندازیم، نه نامِ متد.
         *
         * نسخه‌ی اول فقط دنبالِ رشته‌ی `violations()` می‌گشت — که در
         * **تعریفِ متد** هم هست. خرابکاریِ «فراخوانی را با آرایه‌ی خالی
         * عوض کن» از دستش در رفت، چون تعریف سرِ جایش مانده بود.
         *
         * این همان تلهٔ آشنای این پروژه است، این بار در شکلِ تازه: نه
         * کامنت، بلکه **تعریفی که مثلِ استفاده به نظر می‌رسد**.
         */
        $this->assertMatchesRegularExpression(
            '/\$violations\s*=\s*\$this->violations\(\)\s*;/',
            $code,
            'مهاجرت پیش از افزودنِ قید، داده‌ی موجود را نمی‌سنجد.',
        );

        // و نتیجه‌اش باید واقعاً جلوی اجرا را بگیرد، نه فقط محاسبه شود
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$violations\s*!==\s*\[\]\s*\)/',
            $code,
            'نتیجهٔ سنجش بررسی نمی‌شود؛ مهاجرت با دادهٔ ناقض هم اجرا می‌شود.',
        );

        $this->assertStringContainsString('RuntimeException', $code);
    }

    /**
     * هر جدولِ مالی باید دستِ‌کم یک قید داشته باشد.
     *
     * اگر روزی جدولِ مالیِ تازه‌ای اضافه شود، این تست یادآوری می‌کند که
     * قیدش هم لازم است.
     */
    public function test_every_financial_table_carries_at_least_one_constraint(): void
    {
        $this->requireConstraints();

        $expected = [
            'payments', 'bills', 'wallet_transactions',
            'expenses', 'incomes', 'units', 'unit_user',
        ];

        $connection = DB::connection();
        $isSqlite = $connection->getDriverName() === 'sqlite';

        foreach ($expected as $table) {
            if ($isSqlite) {
                /*
                 * ⚠️ SQLite جدولِ `information_schema` ندارد؛ تنها منبعِ
                 * حقیقت، متنِ `CREATE TABLE` در `sqlite_master` است.
                 */
                $definition = $connection->selectOne(
                    "select sql from sqlite_master where type = 'table' and name = ?",
                    [$table],
                );

                $this->assertNotNull($definition, "جدولِ «{$table}» پیدا نشد.");

                $this->assertStringContainsStringIgnoringCase(
                    'check',
                    (string) $definition->sql,
                    "جدولِ «{$table}» هیچ قیدِ CHECK ندارد.",
                );

                continue;
            }

            $this->assertTrue(
                $connection->table('information_schema.table_constraints')
                    ->where('table_schema', $connection->getDatabaseName())
                    ->where('table_name', $table)
                    ->where('constraint_type', 'CHECK')
                    ->exists(),
                "جدولِ «{$table}» هیچ قیدِ CHECK ندارد.",
            );
        }
    }

    /**
     * کدِ QR ِ ورودِ عمومی با حروفِ عوض‌شده مجتمعی را باز نمی‌کند (مرحلهٔ ۱۰ · فاز ۵).
     *
     * ⚠️ هم‌ترازیِ پیش‌فرضِ MySQL حروفِ بزرگ و کوچک را یکی می‌گیرد و base64url نه؛ پس `where` به‌تنهایی کدِ
     * دیگری را می‌پذیرفت و `hash_equals` در `PublicJoinLink::resolve` تنها مانع است. SQLite خودش حساس است و
     * این آزمون آن‌جا چیزی نمی‌سنجد — برای همین در کارِ MySQL ِ CI است (خرابکاریِ ۱۰/۵ روی SQLite زنده ماند).
     */
    public function test_a_case_changed_join_code_does_not_resolve_even_under_a_case_insensitive_collation(): void
    {
        $code = app(PublicJoinLink::class)->rotate($this->complex);
        $flipped = strtoupper($code) !== $code ? strtoupper($code) : strtolower($code);

        if (DB::connection()->getDriverName() === 'mysql') {
            // آزمون فقط وقتی معنا دارد که خودِ پایگاه‌داده حروف را یکی بگیرد
            $this->assertTrue(Complex::where('join_code', $flipped)->exists(), 'هم‌ترازیِ ستون حساس به حروف است؛ این آزمون چیزی نمی‌سنجد.');
        }

        $this->assertNull(app(PublicJoinLink::class)->resolve($flipped));
        $this->assertSame($this->complex->id, app(PublicJoinLink::class)->resolve($code)?->id);
    }
}
