<?php

namespace App\Console\Commands;

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

        $abandoned = Subscription::where('status', 'pending')
            ->where('method', 'online')
            ->where('created_at', '<', now()->subHours(self::ABANDONED_AFTER_HOURS))
            ->update([
                'status' => 'failed',
                'review_note' => 'پرداخت آنلاین نیمه‌تمام رها شد.',
            ]);

        $this->info("اشتراک منقضی‌شده: {$expired}");
        $this->info("تراکنش رهاشده: {$abandoned}");

        return self::SUCCESS;
    }
}
