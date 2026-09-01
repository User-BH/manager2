<?php

namespace App\Console\Commands;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * پرداخت‌هایی که سرنوشتشان روشن نشده (R59).
 *
 * ─── چرا لازم شد ───────────────────────────────────────────────────────────
 * R59 رفتارِ درگاهِ غیرقابل‌دسترس را عوض کرد: پرداخت دیگر برای همیشه
 * `Failed` نمی‌شود، `pending` می‌ماند. این تصمیمِ درستی است — ولی به تنهایی
 * فقط مسئله را جابه‌جا می‌کند.
 *
 * ⚠️ ردیفی که `pending` بماند و هیچ‌کس نبیندش، از ردیفی که اشتباه `Failed`
 * شده بهتر نیست. این دستور همان ردیف‌ها را جلوی چشم می‌آورد.
 *
 * ─── دو دسته که عمداً از هم جدا شده‌اند ──────────────────────────────────────
 * **رهاشده** — کاربر دکمه را زد و منصرف شد یا مرورگر را بست. عادی است، هر
 * روز چندتا هست، و هیچ کاری لازم ندارد.
 *
 * 🔴 **نامشخص** — ما با بانک حرف زدیم و پاسخ نگرفتیم. ممکن است پولِ کاربر
 * کسر شده باشد. **این یکی آدم می‌خواهد.**
 *
 * اگر این دو با هم گزارش می‌شدند، دسته‌ی دوم زیرِ نوفه‌ی دسته‌ی اول گم
 * می‌شد — همان الگویی که `health:check --quiet-when-ok` برای پرهیز از آن
 * ساخته شد.
 *
 * ─── ⚠️ چرا وضعیتی را عوض نمی‌کند ──────────────────────────────────────────
 * تعیینِ تکلیفِ یک تراکنشِ نامشخص یعنی پرسیدن از بانک، و APIهای استعلامِ
 * ملت و سامان اعتبارنامه و ترمینالِ واقعی می‌خواهند. حدس‌زدن در این نقطه
 * دقیقاً همان کاری است که این مرحله برای رفعش آمده.
 */
class StalePayments extends Command
{
    protected $signature = 'payments:stale
                            {--minutes=30 : از این تعداد دقیقه قدیمی‌تر}';

    protected $description = 'پرداخت‌های آنلاینی که هنوز تعیینِ تکلیف نشده‌اند';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $before = now()->subMinutes($minutes);

        $pending = Payment::withoutGlobalScopes()
            ->where('method', PaymentMethod::Online)
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<', $before)
            ->orderBy('id')
            ->get();

        $indeterminateIds = AuditLog::query()
            ->where('action', 'payment.indeterminate')
            ->whereIn('subject_id', $pending->pluck('id'))
            ->pluck('subject_id')
            ->unique()
            ->flip();

        [$unknown, $abandoned] = $pending->partition(
            fn (Payment $payment) => $indeterminateIds->has($payment->id),
        );

        $staleSubscriptions = Subscription::withoutGlobalScopes()
            ->where('status', 'pending')
            ->whereNotNull('ref_id')
            ->where('created_at', '<', $before)
            ->count();

        $this->line(sprintf(
            'رهاشده: %d پرداخت  ·  اشتراکِ نیمه‌کاره: %d',
            $abandoned->count(),
            $staleSubscriptions,
        ));

        if ($unknown->isEmpty()) {
            $this->info('هیچ پرداختِ نامشخصی وجود ندارد.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error($unknown->count().' پرداخت سرنوشتشان دانسته نشد و باید در پنلِ بانک بررسی شوند:');

        $this->table(
            ['شناسه', 'مجتمع', 'مبلغ', 'مرجعِ بانکی', 'زمان'],
            $unknown->map(fn (Payment $payment) => [
                $payment->id,
                $payment->complex_id,
                number_format((float) $payment->amount),
                $payment->ref_id ?: '—',
                $payment->created_at?->format('Y-m-d H:i') ?? '—',
            ])->all(),
        );

        $this->newLine();
        $this->line('رویه: docs/DISASTER_RECOVERY.md — سناریوی «درگاهِ پرداخت».');

        /*
         * ⚠️ خروجیِ ناموفق عمدی است و برخلافِ `backups:offsite` عمل می‌کند.
         *
         * آنجا نبودِ مقصدِ خارجی یک تنظیمِ انجام‌نشده بود و خطای هرشبه فقط
         * باعث می‌شد کسی خروجیِ cron را نخواند. اینجا هر ردیف یعنی پولِ
         * احتمالیِ یک آدم بلاتکلیف است — و تا وقتی بلاتکلیف است، **باید**
         * سر و صدا کند.
         */
        return self::FAILURE;
    }
}
