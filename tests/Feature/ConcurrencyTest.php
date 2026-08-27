<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payment\PaymentService;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * همزمانی و شرایطِ مسابقه در مسیرهای مالی (R50).
 *
 * ─── محدودیتی که باید پیش از هر ادعایی دانست ────────────────────────────────
 * ⚠️ **همزمانیِ واقعی در این تست‌ها ممکن نیست، و ادعا هم نمی‌شود.**
 *
 * تست‌ها روی SQLiteِ درون‌حافظه‌ای اجرا می‌شوند که تک‌اتصال است، و
 * `RefreshDatabase` همه‌چیز را در یک تراکنشِ باز می‌پیچد. دو «درخواستِ
 * هم‌زمان» اینجا در واقع دو فراخوانیِ پشتِ‌سرِهم روی یک اتصال‌اند.
 *
 * پس این فایل دو چیزِ **دیگر** را می‌سنجد که هر دو در CI اجراشدنی‌اند:
 *
 * ① **ناوردایی‌ها** — چیزهایی که اگر رقابتی رخ دهد حتماً می‌شکنند
 *    (موجودیِ منفی، واگراییِ دفتر، تسویه‌ی دوباره).
 * ② **قراردادهای ساختاری** — الگوهایی که در محصول جلوی رقابت را
 *    می‌گیرند: قفل، بازخوانیِ زیرِ قفل، و تلاشِ دوباره در **جای درست**.
 *
 * ─── همزمانیِ واقعی کجا سنجیده می‌شود ───────────────────────────────────────
 * با `php artisan race:check` که هر عملیات را در پردازه‌ای مستقل اجرا
 * می‌کند. اجرای واقعیِ آن روی SQLiteِ فایلی انجام شد و **هر پنج سناریو
 * یکپارچگیِ داده را حفظ کردند**؛ شرحش در `docs/WORK_PLAN.md` (R50).
 */
class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Complex $complex;

    private Unit $unit;

    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();

        $this->complex = Complex::factory()->create();

        $this->unit = Unit::factory()->create([
            'complex_id' => $this->complex->id,
            'unit_number' => '۱',
        ]);

        $this->resident = User::factory()->create([
            'complex_id' => $this->complex->id,
            'role' => UserRole::Owner->value,
            'is_active' => true,
        ]);
    }

    private function bill(float $total = 500000, float $paid = 0): Bill
    {
        return Bill::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'period' => Jalali::currentPeriod(),
            'base_amount' => $total,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'due_date' => now()->addDays(10),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ⚠️ واقعیتی که باید قفل بماند
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ مهم‌ترین تستِ این فایل، و عجیب‌ترینش.
     *
     * ─── چه چیزی اندازه‌گیری شد ────────────────────────────────────────────
     * `lockForUpdate()` روی MySQL بندِ `for update` تولید می‌کند، ولی روی
     * SQLite **هیچ چیز** — بند بی‌صدا حذف می‌شود.
     *
     * ─── چرا این را تست می‌کنیم ─────────────────────────────────────────────
     * چون یعنی **هر تستی در این پروژه، کدِ مالی را بدونِ قفل اجرا می‌کند**.
     * اگر روزی کسی ببیند تست‌های مالی سبزند و نتیجه بگیرد «پس قفل کار
     * می‌کند»، اشتباه کرده است.
     *
     * این تست آن واقعیت را صریح و ماندگار می‌کند. اگر روزی درایورِ تست به
     * MySQL عوض شود، همین تست قرمز می‌شود و توجه را جلب می‌کند — که همان
     * کارِ درست است.
     */
    public function test_sqlite_silently_drops_the_row_lock(): void
    {
        $sqlite = DB::connection('sqlite')->table('users')->where('id', 1)->lockForUpdate()->toSql();

        $this->assertStringNotContainsString(
            'for update',
            strtolower($sqlite),
            'SQLite حالا قفلِ ردیفی تولید می‌کند؛ فرضِ این مجموعه عوض شده است.',
        );

        $mysql = DB::connection('mysql')->table('users')->where('id', 1)->lockForUpdate()->toSql();

        $this->assertStringContainsString(
            'for update',
            strtolower($mysql),
            'MySQL دیگر قفلِ ردیفی تولید نمی‌کند؛ محافظتِ محصول از بین رفته.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ناوردایی‌های مالی
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ موجودی هرگز نباید منفی شود.
     *
     * بندِ صریحِ ممیزی: «سیستم نباید اجازه دهد `Balance = -100000`».
     */
    public function test_the_wallet_can_never_go_negative(): void
    {
        $wallet = app(WalletService::class);

        $wallet->credit($this->unit, 100000, 'manual', null, 'شارژ');

        $wallet->debit($this->unit, 100000, 'manual', null, 'برداشتِ کامل');

        $this->assertSame(0.0, $wallet->balance($this->unit));

        // برداشتِ دوم باید رد شود، نه اینکه موجودی را منفی کند
        $this->expectException(DomainException::class);

        $wallet->debit($this->unit, 1, 'manual', null, 'برداشتِ اضافه');
    }

    /**
     * ⚠️ عکسِ لحظه‌ای (`balance_after`) باید همیشه با جمعِ دفتر بخواند.
     *
     * ─── چرا این ناوردایی کلیدِ تشخیصِ رقابت است ─────────────────────────────
     * اگر دو نوشتنِ هم‌زمان هر دو مانده‌ی یکسانی بخوانند، هر دو
     * `balance_after` را بر همان مبنا می‌نویسند و عکسِ لحظه‌ای از دفتر
     * واگرا می‌شود — **بدونِ هیچ خطایی**. یعنی این تنها نشانه‌ی
     * قابلِ‌سنجشِ رقابتی است که رخ داده و کسی نفهمیده.
     */
    public function test_the_snapshot_always_matches_the_ledger(): void
    {
        $wallet = app(WalletService::class);

        $wallet->credit($this->unit, 500000, 'manual', null, 'شارژ');
        $wallet->debit($this->unit, 120000, 'manual', null, 'برداشت');
        $wallet->credit($this->unit, 30000, 'manual', null, 'شارژ دوم');
        $wallet->debit($this->unit, 10000, 'manual', null, 'برداشت دوم');

        $balance = $wallet->balance($this->unit);

        $last = WalletTransaction::withoutGlobalScopes()
            ->where('unit_id', $this->unit->id)
            ->latest('id')
            ->first();

        $this->assertEqualsWithDelta(
            $balance,
            (float) $last->balance_after,
            0.01,
            'عکسِ لحظه‌ای با دفتر نمی‌خواند — نشانه‌ی نوشتنِ هم‌زمان.',
        );

        // و جمعِ دستیِ دفتر هم باید همان باشد
        $rows = WalletTransaction::withoutGlobalScopes()->where('unit_id', $this->unit->id)->get();

        $sum = $rows->sum(fn (WalletTransaction $r) => $r->direction === WalletTransaction::CREDIT
            ? (float) $r->amount
            : -(float) $r->amount);

        $this->assertEqualsWithDelta($sum, $balance, 0.01);
    }

    /**
     * ⚠️ تسویه‌ی دوباره‌ی یک پرداخت نباید مبلغ را دوبار بنشاند.
     *
     * این همان «بازگشتِ دوباره‌ی درگاه» است: بانک هم بازگشتِ مرورگر
     * می‌فرستد هم فراخوانیِ سرور‌به‌سرور.
     */
    public function test_settling_the_same_payment_twice_is_a_no_op(): void
    {
        $bill = $this->bill();

        $payment = Payment::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'bill_id' => $bill->id,
            'user_id' => $this->resident->id,
            'amount' => 100000,
            'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Pending,
            'paid_at' => now(),
        ]);

        $payments = app(PaymentService::class);

        $payments->settle($payment);
        $afterFirst = (float) $bill->fresh()->paid_amount;

        $payments->settle($payment->fresh());
        $afterSecond = (float) $bill->fresh()->paid_amount;

        $this->assertEqualsWithDelta(100000, $afterFirst, 0.01);
        $this->assertEqualsWithDelta(
            $afterFirst,
            $afterSecond,
            0.01,
            'تسویه‌ی دوباره مبلغ را دوباره روی قبض نشاند.',
        );
    }

    /** هیچ قبضی نباید بیش از مبلغِ کلش پرداخت‌شده ثبت شود. */
    public function test_a_bill_is_never_overpaid_by_the_wallet(): void
    {
        $bill = $this->bill(total: 100000);

        $wallet = app(WalletService::class);
        $wallet->credit($this->unit, 500000, 'manual', null, 'شارژ زیاد');

        $wallet->payBill($this->unit, $bill);

        $fresh = $bill->fresh();

        $this->assertLessThanOrEqual(
            (float) $fresh->total_amount + 0.01,
            (float) $fresh->paid_amount,
            'قبض بیش از مبلغِ کلش پرداخت‌شده ثبت شد.',
        );

        // پرداختِ دوباره روی قبضِ تسویه‌شده باید رد شود
        $this->expectException(DomainException::class);

        $wallet->payBill($this->unit, $fresh);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  بازخوانیِ زیرِ قفل — «به مدلی که به تو داده‌اند اعتماد نکن»
    // ─────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ سرویس نباید به وضعیتِ مدلی که دریافت کرده اعتماد کند.
     *
     * ─── چرا این معادلِ رفتاریِ قفل است ─────────────────────────────────────
     * در محصول، بینِ لحظه‌ای که کنترلر مدل را می‌خواند و لحظه‌ای که سرویس
     * روی آن کار می‌کند، درخواستِ دیگری می‌تواند همان ردیف را عوض کرده
     * باشد. قفل جلوی هم‌زمانی را می‌گیرد، ولی **بازخوانی** آن چیزی است که
     * تصمیم را بر مبنای واقعیتِ تازه می‌گیرد.
     *
     * اینجا دقیقاً همان حالت ساخته می‌شود: ردیف در دیتابیس عوض می‌شود در
     * حالی که مدلِ در دستِ ما کهنه مانده. اگر سرویس بازخوانی کند، وضعیتِ
     * تازه را می‌بیند؛ اگر نه، روی داده‌ی کهنه عمل می‌کند.
     */
    public function test_the_payment_service_rereads_instead_of_trusting_a_stale_model(): void
    {
        $bill = $this->bill();

        $payment = Payment::create([
            'complex_id' => $this->complex->id,
            'unit_id' => $this->unit->id,
            'bill_id' => $bill->id,
            'user_id' => $this->resident->id,
            'amount' => 100000,
            'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Pending,
            'paid_at' => now(),
        ]);

        // مدلِ کهنه در دستِ ما می‌ماند…
        $stale = $payment->replicate();
        $stale->id = $payment->id;
        $stale->exists = true;
        $stale->status = PaymentStatus::Pending;

        // …ولی ردیفِ واقعی پشتِ سرِ ما تسویه می‌شود
        DB::table('payments')->where('id', $payment->id)->update([
            'status' => PaymentStatus::Success->value,
        ]);

        app(PaymentService::class)->settle($stale);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $bill->fresh()->paid_amount,
            0.01,
            'سرویس به مدلِ کهنه اعتماد کرد و پرداختِ تسویه‌شده را دوباره نشاند.',
        );
    }

    /** کیف پول هم باید مانده را از دفتر بخواند، نه از هر چیزی که به آن داده شده. */
    public function test_the_wallet_reads_the_balance_from_the_ledger_not_the_model(): void
    {
        $wallet = app(WalletService::class);

        $wallet->credit($this->unit, 50000, 'manual', null, 'شارژ');

        // نمونه‌ی کهنه‌ای که هیچ خبری از شارژ ندارد
        $stale = Unit::withoutGlobalScopes()->find($this->unit->id);

        $wallet->credit($this->unit, 20000, 'manual', null, 'شارژ دوم');

        // با نمونه‌ی کهنه برداشت می‌کنیم؛ باید مانده‌ی واقعی (۷۰٬۰۰۰) را ببیند
        $wallet->debit($stale, 70000, 'manual', null, 'برداشتِ کامل');

        $this->assertEqualsWithDelta(0.0, $wallet->balance($this->unit), 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  قراردادهای ساختاری
    // ─────────────────────────────────────────────────────────────────────

    /** متنِ یک فایل، بدونِ کامنت. */
    private function source(string $path): string
    {
        $src = (string) file_get_contents(base_path($path));

        /*
         * ⚠️ دو الگوی جدا. یکی‌کردنشان با پرچمِ `s` باعث می‌شود `//.*$` کلِ
         * فایل را از اولین `//` به بعد ببلعد — دامی که در این پروژه بارها
         * تکرار شده.
         */
        $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

        return (string) preg_replace('#^\s*//.*$#m', '', $src);
    }

    /**
     * بدنه‌ی هر `DB::transaction(` تا پرانتزِ بسته‌ی واقعیِ خودش.
     *
     * ⚠️ پنجره‌ی ثابت (مثلاً «۴۵ خطِ بعدی») جواب نمی‌دهد و این را با
     * اشتباه یاد گرفتم: `WalletService::payBill` بدنه‌ای بلندتر از آن دارد
     * و `attempts` آخرش بیرون از پنجره می‌افتاد، پس سالم را ناسالم اعلام
     * کردم.
     *
     * @return array<int, array{file: string, line: int, body: string}>
     */
    private function transactions(): array
    {
        $found = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
            $src = $this->source($relative);

            if (! str_contains($src, 'DB::transaction(')) {
                continue;
            }

            $offset = 0;

            while (($pos = strpos($src, 'DB::transaction(', $offset)) !== false) {
                $start = $pos + strlen('DB::transaction');
                $depth = 0;
                $i = $start;

                for (; $i < strlen($src); $i++) {
                    if ($src[$i] === '(') {
                        $depth++;
                    } elseif ($src[$i] === ')') {
                        $depth--;

                        if ($depth === 0) {
                            break;
                        }
                    }
                }

                $found[] = [
                    'file' => $relative,
                    'line' => substr_count(substr($src, 0, $pos), "\n") + 1,
                    'body' => substr($src, $start, $i - $start + 1),
                ];

                $offset = $pos + 1;
            }
        }

        return $found;
    }

    /**
     * ⚠️ هر تراکنشی که قفل می‌گیرد باید تلاشِ دوباره داشته باشد.
     *
     * ─── نقصی که این گرفت ───────────────────────────────────────────────────
     * `GatewayController::callback` — پرتراکم‌ترین مسیرِ مالیِ سامانه —
     * قفل می‌گرفت ولی `attempts` نداشت. با بن‌بستِ MySQL، بازگشتِ بانک
     * ۵۰۰ می‌گرفت و تراکنش برمی‌گشت: پولِ کاربر کم شده، قبض تسویه‌نشده.
     *
     * ⚠️ و `attempts: 3` روی `PaymentService::settle()` نجاتش نمی‌داد،
     * چون آنجا **تودرتو** صدا زده می‌شود. از سورسِ لاراول
     * (`ManagesTransactions::handleTransactionException`) خوانده شد: با
     * `transactions > 1` فریم‌ورک بی‌درنگ `DeadlockException` پرتاب می‌کند
     * و حلقه‌ی تلاش را اجرا **نمی‌کند**.
     */
    public function test_every_locking_transaction_retries_on_deadlock(): void
    {
        $offenders = [];

        foreach ($this->transactions() as $transaction) {
            if (! str_contains($transaction['body'], 'lockForUpdate')) {
                continue;
            }

            if (! str_contains($transaction['body'], 'attempts:')) {
                $offenders[] = $transaction['file'].':'.$transaction['line'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "این تراکنش‌ها قفل می‌گیرند ولی در بن‌بست تلاشِ دوباره نمی‌کنند:\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * ⚠️ ترتیبِ گرفتنِ قفل‌ها نباید چرخه بسازد.
     *
     * ─── چرا این تنها راهِ جلوگیری از بن‌بستِ واقعی است ──────────────────────
     * بن‌بست وقتی رخ می‌دهد که مسیرِ الف `A → B` بگیرد و مسیرِ ب
     * `B → A`. هیچ تلاشِ دوباره‌ای این را رفع نمی‌کند؛ فقط پنهانش می‌کند و
     * زیرِ بار دوباره برمی‌گردد.
     *
     * اندازه‌گیریِ امروز: زنجیره‌ها `ComplexInvitation → User` و
     * `Unit → Bill` هستند و هیچ مسیری برعکسشان را نمی‌گیرد. این تست همان
     * را نگه می‌دارد.
     */
    public function test_locks_are_always_acquired_in_the_same_order(): void
    {
        $chains = [];

        foreach ($this->transactions() as $transaction) {
            preg_match_all(
                '/(\w+)::(?:withoutGlobalScopes\(\)->)?lockForUpdate/',
                $transaction['body'],
                $matches,
            );

            $chain = array_values(array_unique($matches[1]));

            if (count($chain) > 1) {
                $chains[$transaction['file'].':'.$transaction['line']] = $chain;
            }
        }

        // هر جفتِ (قبلی، بعدی) در همه‌ی زنجیره‌ها باید یک‌طرفه باشد
        $seen = [];
        $conflicts = [];

        foreach ($chains as $where => $chain) {
            for ($i = 0; $i < count($chain) - 1; $i++) {
                for ($j = $i + 1; $j < count($chain); $j++) {
                    $pair = $chain[$i].'→'.$chain[$j];
                    $reverse = $chain[$j].'→'.$chain[$i];

                    if (isset($seen[$reverse])) {
                        $conflicts[] = "{$where} ترتیبِ «{$pair}» دارد ولی {$seen[$reverse]} ترتیبِ «{$reverse}»";
                    }

                    $seen[$pair] = $where;
                }
            }
        }

        $this->assertSame(
            [],
            $conflicts,
            "ترتیبِ قفل‌ها چرخه دارد و بن‌بست ممکن است:\n".implode("\n", $conflicts),
        );
    }

    /**
     * ⚠️ هر مسیری که وضعیت را زیرِ قفل می‌سنجد، باید ردیف را **بازخوانی** کند.
     *
     * قفل‌گرفتن بدونِ بازخوانی بی‌فایده است: مدلِ در دست همان مقدارِ کهنه
     * را دارد و شرط روی داده‌ی پیش از قفل سنجیده می‌شود — یعنی همان
     * «بررسی کن، بعد عمل کن» که قفل قرار بود جلویش را بگیرد.
     */
    public function test_locked_transactions_reread_the_row(): void
    {
        $offenders = [];

        foreach ($this->transactions() as $transaction) {
            if (! str_contains($transaction['body'], 'lockForUpdate')) {
                continue;
            }

            /*
             * دو الگوی معتبرِ بازخوانی وجود دارد و هر دو باید شناخته شوند:
             *
             * ① قفلِ **مدل**:      `Model::lockForUpdate()->find($id)`
             * ② قفلِ **رابطه**:    `$parent->children()->lockForUpdate()->get()`
             *
             * ⚠️ نسخه‌ی اولِ این تست فقط الگوی ① را می‌شناخت و
             * `PollService::castVote` را **به‌غلط** متخلف اعلام کرد. آن متد
             * کلِ رأی‌های نظرسنجی را با قفلِ رابطه می‌گیرد و بعد
             * `blockReason()` دوباره کوئری می‌زند — یعنی دقیقاً بازخوانی
             * می‌کند، فقط به شکلِ دیگری.
             *
             * یافته‌ی کاذب بدتر از نبودِ یافته است: کسی وقت می‌گذارد روی
             * کدی که سالم است، و بعد به بقیه‌ی گزارش هم شک می‌کند.
             */
            $rereadsModel = preg_match(
                '/lockForUpdate\(\)->(find|findOrFail|first|firstOrFail)\(/',
                $transaction['body'],
            ) === 1;

            $rereadsRelation = preg_match(
                '/->\w+\(\)->lockForUpdate\(\)->(get|first|exists|count)\(/',
                $transaction['body'],
            ) === 1;

            if (! $rereadsModel && ! $rereadsRelation) {
                $offenders[] = $transaction['file'].':'.$transaction['line'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "این تراکنش‌ها قفل می‌گیرند ولی ردیف را بازخوانی نمی‌کنند:\n".implode("\n", $offenders),
        );
    }

    /**
     * ابزارِ سنجشِ همزمانیِ واقعی باید وجود داشته باشد.
     *
     * ⚠️ چون CI نمی‌تواند همزمانیِ واقعی بسازد، تنها راهِ اثباتش اجرای
     * دستیِ `race:check` است. اگر آن دستور روزی حذف شود، این محدودیت
     * بی‌صدا تبدیل به «هیچ راهی نداریم» می‌شود.
     */
    public function test_the_manual_concurrency_tool_exists(): void
    {
        $commands = array_keys(app(Kernel::class)->all());

        $this->assertContains('race:check', $commands);
    }
}
