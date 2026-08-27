<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\WalletTransaction;
use App\Services\Payment\PaymentService;
use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * اجرای یک عملیاتِ مالیِ تکی، برای آزمونِ همزمانی (R50).
 *
 * ─── چرا این یک دستورِ artisan است و نه یک تست ──────────────────────────────
 * ⚠️ همزمانیِ واقعی در PHPUnit این پروژه **ممکن نیست**. تست‌ها روی
 * SQLiteِ درون‌حافظه‌ای اجرا می‌شوند که تک‌اتصال است، و `RefreshDatabase`
 * همه‌چیز را در یک تراکنشِ باز می‌پیچد. یعنی دو «درخواستِ هم‌زمان» در تست،
 * در واقع دو فراخوانیِ پشتِ‌سرِهم روی یک اتصال‌اند — و رقابتی که باید
 * سنجیده شود اصلاً ساخته نمی‌شود.
 *
 * این دستور همان عملیات را در یک **پردازه‌ی مستقل** اجرا می‌کند. با
 * اجرای هم‌زمانِ چند نسخه‌ی آن، هر کدام اتصالِ دیتابیسِ خودش را دارد و
 * همزمانی واقعی می‌شود.
 *
 * ─── چرا اجرایش روی MySQL اهمیت دارد ───────────────────────────────────────
 * ⚠️ اندازه‌گیری شد که `lockForUpdate()` روی SQLite **هیچ SQLی تولید
 * نمی‌کند** — بندِ `for update` بی‌صدا حذف می‌شود. پس هر آزمونی روی SQLite،
 * کد را **بدونِ قفل** اجرا می‌کند.
 *
 * این بد نیست؛ در واقع سخت‌گیرانه‌تر است: اگر منطق روی SQLite هم درست
 * بماند، یعنی دفاعی فراتر از قفل دارد. ولی **درستیِ خودِ قفل** فقط روی
 * MySQL قابلِ اثبات است، و تا آن اجرا انجام نشود وضعیتش
 * **NOT VERIFIED** می‌ماند.
 *
 * ─── نحوه‌ی استفاده ────────────────────────────────────────────────────────
 * چند نسخه را هم‌زمان اجرا کنید (مثلاً با `xargs -P` یا اسکریپت):
 *
 *     php artisan race:check debit --unit=1
 *     php artisan race:check settle --payment=7
 *
 * سپس با `verify` مطمئن شوید هیچ‌چیز خراب نشده:
 *
 *     php artisan race:check verify
 */
class CheckRaceConditions extends Command
{
    protected $signature = 'race:check
                            {operation : debit | settle | verify}
                            {--unit= : شناسه‌ی واحد}
                            {--payment= : شناسه‌ی پرداخت}
                            {--amount=10000 : مبلغ}';

    protected $description = 'اجرای یک عملیاتِ مالیِ تکی برای آزمونِ همزمانی (چند پردازه‌ای)';

    public function handle(WalletService $wallet, PaymentService $payments): int
    {
        return match ($this->argument('operation')) {
            'debit' => $this->debit($wallet),
            'settle' => $this->settle($payments),
            'verify' => $this->verify($wallet),
            default => $this->fail('عملیاتِ ناشناخته. یکی از: debit، settle، verify'),
        };
    }

    /** برداشت از کیف پول — دو اجرای هم‌زمان نباید موجودی را منفی کنند. */
    private function debit(WalletService $wallet): int
    {
        $unit = Unit::withoutGlobalScopes()->find((int) $this->option('unit'));

        if (! $unit) {
            $this->error('واحد پیدا نشد. با --unit شناسه بدهید.');

            return self::FAILURE;
        }

        try {
            $wallet->debit($unit, (float) $this->option('amount'), 'manual', null, 'آزمونِ همزمانی');
            $this->line('OK');

            return self::SUCCESS;
        } catch (Throwable $e) {
            // ⚠️ شکست اینجا **موفقیت** است: یعنی دومین برداشت جلویش گرفته شد
            $this->line('BLOCKED: '.str_replace("\n", ' ', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /** تسویه‌ی پرداخت — چند بازگشتِ هم‌زمانِ درگاه نباید دوبار بنشیند. */
    private function settle(PaymentService $payments): int
    {
        $payment = Payment::withoutGlobalScopes()->find((int) $this->option('payment'));

        if (! $payment) {
            $this->error('پرداخت پیدا نشد. با --payment شناسه بدهید.');

            return self::FAILURE;
        }

        try {
            /*
             * ⚠️ همان الگوی `GatewayController` بازسازی می‌شود: تراکنشِ
             * بیرونی + قفل + بازخوانیِ وضعیت. `attempts` روی تراکنشِ
             * **بیرونی** است، چون در تراکنشِ تودرتو لاراول اصلاً تلاشِ
             * دوباره نمی‌کند.
             */
            $done = DB::transaction(function () use ($payment, $payments): bool {
                $fresh = Payment::withoutGlobalScopes()->lockForUpdate()->find($payment->id);

                if (! $fresh || $fresh->status !== PaymentStatus::Pending) {
                    return false;
                }

                $payments->settle($fresh);

                return true;
            }, attempts: 3);

            $this->line($done ? 'SETTLED' : 'SKIPPED');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line('FAILED: '.str_replace("\n", ' ', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * ⚠️ مهم‌ترین بخش: پس از هجومِ هم‌زمان، ناوردایی‌ها هنوز برقرارند؟
     *
     * این‌ها چیزهایی‌اند که اگر رقابتی رخ داده باشد، حتماً می‌شکنند — حتی
     * اگر هیچ خطایی در لاگ نباشد.
     */
    private function verify(WalletService $wallet): int
    {
        $problems = [];

        // ① هیچ واحدی نباید موجودیِ منفی داشته باشد
        foreach (Unit::withoutGlobalScopes()->get() as $unit) {
            $balance = $wallet->balance($unit);

            if ($balance < 0) {
                $problems[] = "واحد #{$unit->id} موجودیِ منفی دارد: {$balance}";
            }

            /*
             * ② `balance_after` آخرین ردیفِ دفتر باید با جمعِ کلِ دفتر بخواند.
             *
             * ⚠️ این دقیقاً همان چیزی است که با رقابت می‌شکند: دو نوشتنِ
             * هم‌زمان هر دو مانده‌ی یکسانی می‌خوانند و هر دو `balance_after`
             * را بر آن مبنا می‌نویسند، پس عکسِ لحظه‌ای با دفتر واگرا می‌شود
             * بی‌آنکه خطایی رخ دهد.
             */
            $last = WalletTransaction::withoutGlobalScopes()
                ->where('unit_id', $unit->id)
                ->latest('id')
                ->first();

            if ($last && abs((float) $last->balance_after - $balance) > 0.01) {
                $problems[] = "واحد #{$unit->id}: balance_after={$last->balance_after} ولی جمعِ دفتر={$balance}";
            }
        }

        // ③ هیچ قبضی نباید بیش از مبلغِ کل پرداخت شده باشد
        foreach (Bill::withoutGlobalScopes()->get() as $bill) {
            if ((float) $bill->paid_amount > (float) $bill->total_amount + 0.01) {
                $problems[] = "قبض #{$bill->id}: پرداخت‌شده {$bill->paid_amount} > کل {$bill->total_amount}";
            }
        }

        // ④ هیچ قبضی نباید بیش از یک رسیدِ در انتظار داشته باشد
        $duplicates = Payment::withoutGlobalScopes()
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('bill_id')
            ->selectRaw('bill_id, count(*) as total')
            ->groupBy('bill_id')
            ->having('total', '>', 1)
            ->pluck('total', 'bill_id');

        foreach ($duplicates as $billId => $total) {
            $problems[] = "قبض #{$billId}: {$total} پرداختِ در انتظار (باید یکی باشد)";
        }

        if ($problems === []) {
            $this->info('همه‌ی ناوردایی‌ها برقرارند.');
            $this->line('  درایور: '.DB::getDriverName().
                (DB::getDriverName() === 'sqlite' ? '  ⚠️ روی SQLite قفلِ ردیفی اعمال نمی‌شود' : ''));

            return self::SUCCESS;
        }

        $this->error('یکپارچگیِ داده شکسته است:');

        foreach ($problems as $problem) {
            $this->line('  • '.$problem);
        }

        return self::FAILURE;
    }
}
