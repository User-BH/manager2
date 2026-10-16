<?php

namespace App\Console\Commands;

use App\Models\CheckoutIntent;
use App\Models\Subscription;
use App\Services\Payment\GatewayUnreachable;
use App\Services\Payment\Reverifiable;
use App\Services\Payment\TrackingCodes;
use App\Services\Subscription\ComplexPurchase;
use App\Services\Subscription\SubscriptionGatewayManager;
use App\Services\Subscription\SubscriptionRenewal;
use Illuminate\Console\Command;

/**
 * بازبینیِ دوره‌ایِ خرید و تمدیدِ اشتراک (مرحلهٔ ۱۲ · فاز ۲؛ بندِ ۵ ِ تصمیمِ قطعیِ کارفرما).
 *
 * «پرداخت موفق بود ولی ثبت نشد» نباید کاربر را به پرداختِ دوباره بکشاند یا وضعیتِ مالی را نامعلوم بگذارد. این دستور:
 *
 * ۱. تمدید و قصدِ خریدی که مرجعِ درگاه گرفته ولی بیش از `--after` دقیقه `pending` مانده (بازگشتِ مرورگر نرسید یا
 *    درگاه آن لحظه پاسخ نداد) را از **خودِ درگاه** دوباره می‌پرسد (`Reverifiable`): پرداخت‌شده ← از همان مسیرِ
 *    قفل‌دارِ بازگشت کامل می‌شود؛ قطعاً پرداخت‌نشده ← ناموفق؛ هنوز نامعلوم ← دست نمی‌خورد.
 * ۲. قصدِ `paid` (پول گرفته شد، مجتمع نشد) را اگر حساب دوباره واجدِ شرایط است کامل می‌کند؛ وگرنه برای ثبتِ
 *    بازپرداخت در پنلِ ادمینِ کل می‌ماند.
 *
 * هر دو از همان `SubscriptionRenewal::complete` و `ComplexPurchase::complete` می‌گذرند که بازگشتِ بانک — زیرِ قفلِ
 * کدِ رهگیری و ردیف، فقط `pending` جلو می‌رود — پس همزمانیِ این دستور با بازگشتِ دیرِ کاربر هیچ چیزی را دو بار
 * نمی‌سازد. درگاهی که دوباره‌پرسیدن ندارد (ملت، سامان) فقط شمرده و در `payments:stale` گزارش می‌شود.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
                            {--after=15 : فقط پرداخت‌هایی که از این تعداد دقیقه قدیمی‌ترند (فرصتِ بازگشتِ خودِ کاربر)}
                            {--days=7 : پنجره‌ی بازبینی؛ قدیمی‌ترها فقط در payments:stale}';

    protected $description = 'تعیینِ تکلیفِ خرید و تمدیدِ اشتراکِ نامعلوم با پرسیدن از خودِ درگاه';

    public function handle(SubscriptionGatewayManager $gateways, SubscriptionRenewal $renewal, ComplexPurchase $purchase): int
    {
        $before = now()->subMinutes(max(1, (int) $this->option('after')));
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $tally = ['completed' => 0, 'failed' => 0, 'undecided' => 0, 'unsupported' => 0, 'fulfilled' => 0];

        $driver = $gateways->isEnabled() ? $gateways->driver() : null;

        $renewals = Subscription::where('method', 'online')->where('status', 'pending')
            ->whereNotNull('ref_id')->whereBetween('created_at', [$since, $before])->orderBy('id')->get();
        foreach ($renewals as $subscription) {
            $tally[$this->settle($driver, $subscription,
                fn (string $tracking) => $renewal->complete($subscription, $tracking)->status === 'active',
                fn () => $renewal->fail($subscription, 'بازبینی: درگاه پرداختی ثبت نکرده است'))]++;
        }

        $intents = CheckoutIntent::where('status', CheckoutIntent::PENDING)
            ->whereNotNull('authority')->whereBetween('created_at', [$since, $before])->orderBy('id')->get();
        foreach ($intents as $intent) {
            $tally[$this->settle($driver, $intent,
                fn (string $tracking) => TrackingCodes::exclusively($tracking, fn () => $purchase->complete($intent, $tracking))->status !== CheckoutIntent::FAILED,
                fn () => $purchase->fail($intent, 'بازبینی: درگاه پرداختی ثبت نکرده است'))]++;
        }

        foreach (CheckoutIntent::where('status', CheckoutIntent::PAID)->orderBy('id')->get() as $paid) {
            if ($purchase->retryPaid($paid)->status === CheckoutIntent::COMPLETED) {
                $tally['fulfilled']++;
            }
        }

        $this->line(sprintf(
            'کامل: %d · ناموفق: %d · هنوز نامعلوم: %d · بی امکانِ بازپرسی: %d · پرداخت‌شده‌ی تکمیل‌شده: %d',
            $tally['completed'], $tally['failed'], $tally['undecided'], $tally['unsupported'], $tally['fulfilled'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  callable(string): bool  $complete
     * @param  callable(): mixed  $fail
     */
    private function settle(?object $driver, Subscription|CheckoutIntent $order, callable $complete, callable $fail): string
    {
        if (! $driver instanceof Reverifiable) {
            return 'unsupported';
        }

        try {
            $tracking = $driver->reverify($order);
        } catch (GatewayUnreachable) {
            return 'undecided';
        }

        if ($tracking === null) {
            $fail();

            return 'failed';
        }

        return $complete($tracking) ? 'completed' : 'failed';
    }
}
