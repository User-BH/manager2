<?php

namespace App\Console\Commands;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Notifications\BillDueReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * یادآوریِ دوره‌ایِ قبض‌های سررسیدشده (R22).
 *
 * ─── چرا «دوره‌ای» و نه یک‌بار ──────────────────────────────────────────────
 * خواسته‌ی محصول: «اگر واحد اعلان را دید و فراموش کرد، چند روز بعد دوباره
 * یادآوری بگیرد.» پس یادآوری تکرار می‌شود، ولی نه بی‌حساب.
 *
 * سه قید که یادآوری را از «مزاحمت» جدا می‌کند:
 *
 *  ۱. فقط پس از گذشتنِ مهلت (`due_date`)
 *  ۲. فاصله‌ی حداقلیِ `INTERVAL_DAYS` از یادآوریِ قبلی — بدونِ این، هر اجرای
 *     دستور یک اعلانِ تازه می‌ساخت و اگر روزی چند بار اجرا می‌شد، ساکن روزی
 *     چند پیام می‌گرفت و یادآوری را خاموش می‌کرد
 *  ۳. سقفِ `MAX_REMINDERS` — پس از آن دیگر پیام نمی‌رود؛ کسی که پنج بار
 *     یادآوری گرفته با ششمی پرداخت نمی‌کند و فقط اعتمادش به اعلان‌ها از بین
 *     می‌رود
 */
class SendBillReminders extends Command
{
    protected $signature = 'bills:remind {--dry-run : فقط گزارش بده}';

    protected $description = 'ارسال یادآوری برای قبض‌های سررسیدشده‌ی پرداخت‌نشده';

    /** کمترین فاصله بین دو یادآوری. */
    private const INTERVAL_DAYS = 3;

    /** بیشترین تعداد یادآوری برای یک قبض. */
    private const MAX_REMINDERS = 4;

    public function handle(): int
    {
        $bills = Bill::withoutGlobalScopes()
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->where('reminders_sent', '<', self::MAX_REMINDERS)
            ->where(function ($query) {
                $query->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', now()->subDays(self::INTERVAL_DAYS));
            })
            ->with('unit.residents')
            ->get();

        $sent = 0;

        foreach ($bills as $bill) {
            /*
             * قبضِ بدونِ ساکن نباید بی‌صدا رد شود و نه باعثِ خطا: واحدِ خالی
             * وضعیتِ کاملاً عادی است. فقط شمرده نمی‌شود.
             */
            $residents = $bill->unit->residents ?? collect();
            $dueDate = $bill->due_date;

            // واحدِ خالی یا قبضِ بدونِ مهلت: هیچ‌کدام خطا نیست، فقط رد می‌شود
            if ($residents->isEmpty() || $dueDate === null) {
                continue;
            }

            /*
             * ترتیبِ آرگومان مهم است: در Carbon 3 مقدار **علامت‌دار** است.
             * با `now()->diffInDays($due)` عددِ منفی درمی‌آمد و پیام می‌شد
             * «۳۲- روز از سررسید گذشته». در اجرای آزمایشی روی داده‌ی واقعی
             * دیده شد — تست‌ها نگرفته بودند چون متنِ پیام را نمی‌سنجیدند.
             */
            $daysOverdue = (int) $dueDate->startOfDay()->diffInDays(now()->startOfDay());

            if (! $this->option('dry-run')) {
                Notification::send($residents, new BillDueReminderNotification($bill, $daysOverdue));

                /*
                 * `saveQuietly` تا Observerِ ردگیری (R12) این را «ویرایشِ
                 * قبض» حساب نکند؛ یادآوری تغییرِ کسب‌وکاری نیست.
                 */
                $bill->forceFill([
                    'last_reminded_at' => now(),
                    'reminders_sent' => $bill->reminders_sent + 1,
                ])->saveQuietly();
            }

            $sent++;
            $this->line(sprintf(
                '  قبض %s · واحد %s · %d روز گذشته · %d نفر',
                $bill->period,
                $bill->unit?->unit_number,
                $daysOverdue,
                $residents->count(),
            ));
        }

        $this->info($this->option('dry-run')
            ? "{$sent} قبض واجد شرایط یادآوری است (چیزی فرستاده نشد)."
            : "برای {$sent} قبض یادآوری فرستاده شد.");

        return self::SUCCESS;
    }
}
