<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * نگهداری روزانه‌ی اشتراک‌ها.
 *
 * دو کارِ جاافتاده را انجام می‌دهد:
 *
 * ۱) اشتراک‌های سررسیدشده تا ابد وضعیت `active` می‌ماندند و فقط هنگام
 *    خواندن با شرط `ends_at > now()` فیلتر می‌شدند. نتیجه‌اش این بود که
 *    گزارش «اشتراک فعال» عددِ نادرست می‌داد.
 *
 * ۲) اگر کاربر دکمه‌ی پرداخت را می‌زد ولی به درگاه نمی‌رفت (یا برنمی‌گشت)،
 *    یک ردیف `pending` برای همیشه باقی می‌ماند و صف بررسی و سابقه را
 *    شلوغ می‌کرد.
 *
 * درخواست‌های `pending` از نوع «رسید» عمداً دست‌نخورده می‌مانند؛ آن‌ها منتظر
 * بررسی انسانی‌اند نه بازگشت از بانک.
 */
class MaintainSubscriptions extends Command
{
    /** پس از این مدت، تراکنش آنلاینِ ناتمام رهاشده حساب می‌شود. */
    private const ABANDONED_AFTER_HOURS = 3;

    protected $signature = 'subscriptions:maintain';

    protected $description = 'اشتراک‌های منقضی را علامت می‌زند و تراکنش‌های آنلاینِ رهاشده را می‌بندد';

    public function handle(): int
    {
        $expired = Subscription::where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->update(['status' => 'expired']);

        /*
         * 🔴 تراکنشی که سرنوشتش **دانسته نشده** رهاشده نیست (R59).
         *
         * ─── چه چیزی بی‌صدا می‌شکست ────────────────────────────────────────
         * R59 کاری کرد که وقتی درگاه پاسخ نمی‌دهد، اشتراک `pending` بماند —
         * چون نمی‌دانیم پول کسر شده یا نه.
         *
         * ولی همین دستور، سه ساعت بعد، **همان ردیف را `failed` می‌کرد** و
         * یادداشتی می‌گذاشت که می‌گفت «پرداخت آنلاین نیمه‌تمام رها شد» —
         * جمله‌ای که ممکن است دروغ باشد و ردِ ممیزی خلافش را می‌گوید.
         *
         * یعنی رفعِ R59 ظرفِ سه ساعت خنثی می‌شد، و چون این دستور شبانه و
         * خودکار است، هیچ‌کس نمی‌دید.
         *
         * ⚠️ ردیف‌هایی که ردِ «نامشخص» دارند کنار گذاشته می‌شوند و
         * `payments:stale` نشانشان می‌دهد تا آدم تعیینِ تکلیفشان کند.
         */
        $indeterminate = AuditLog::query()
            ->where('action', 'subscription.indeterminate')
            ->pluck('subject_id')
            ->unique();

        /*
         * 🔴 مرحلهٔ ۱۲ · فاز ۲: فقط ردیفی که **هرگز به درگاه نرسید** (`ref_id` ندارد) قطعاً بی‌پول است. ردیفی که مرجعِ
         * درگاه گرفته شاید پرداخت شده باشد و بازگشتش فقط نرسیده (مرورگر بسته شد) — «ناموفق» کردنش بی پرسیدن از درگاه
         * پولِ کاربر را گم می‌کرد. تکلیفِ آن‌ها را `payments:reconcile` از خودِ درگاه می‌پرسد و `payments:stale` به
         * آدم نشان می‌دهد.
         */
        $abandoned = Subscription::where('status', 'pending')
            ->where('method', 'online')
            ->whereNull('ref_id')
            ->where('created_at', '<', now()->subHours(self::ABANDONED_AFTER_HOURS))
            ->whereNotIn('id', $indeterminate)
            ->update([
                'status' => 'failed',
                'review_note' => 'پرداخت آنلاین نیمه‌تمام رها شد.',
            ]);

        $this->info("اشتراک منقضی‌شده: {$expired}");
        $this->info("تراکنش رهاشده: {$abandoned}");

        return self::SUCCESS;
    }
}
