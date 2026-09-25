<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Subscription;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * یک کدِ رهگیریِ بانک فقط یک پرداخت را تسویه می‌کند (مرحلهٔ ۵ · فاز ۹ · بندِ ۶۹).
 *
 * 🔴 چرا لازم شد: سامان کدِ ۲ («از قبل تأیید شده») را هم موفقیت می‌داند —
 * درست، چون بازگشتِ دوباره‌ی همان پرداخت باید کار کند. ولی یعنی `RefNum`ِ
 * تراکنشی که **قبلاً** برای قبضِ A تأیید و ثبت شده، اگر به بازگشتِ قبضِ B
 * با همان مبلغ فرستاده شود، دوباره «تأیید» می‌گیرد و B هم با همان یک پول
 * تسویه می‌شد.
 *
 * ⚠️ فقط پرداخت‌های **آنلاین**: کدِ رهگیریِ رسیدِ دستی را کاربر تایپ می‌کند
 * و تکرارش معنای دیگری دارد. اشتراک‌ها هم سنجیده می‌شوند چون ممکن است روی
 * همان ترمینال باشند.
 */
class TrackingCodes
{
    public static function claimedElsewhere(string $tracking, Payment|Subscription $except): bool
    {
        $payments = Payment::withoutGlobalScopes()
            ->where('method', PaymentMethod::Online->value)
            ->where('tracking_code', $tracking);

        $subscriptions = Subscription::withoutGlobalScopes()
            ->where('method', 'online')
            ->where('tracking_code', $tracking);

        if ($except instanceof Payment) {
            $payments->whereKeyNot($except->id);
        } else {
            $subscriptions->whereKeyNot($except->id);
        }

        return $payments->exists() || $subscriptions->exists();
    }

    /**
     * «بررسی و ثبت» به‌صورتِ یک کار.
     *
     * ⚠️ بدونِ قفل، دو بازگشتِ هم‌زمان با یک کد هر دو «هنوز ثبت نشده» را
     * می‌دیدند. قفلِ ردیفِ پرداخت اینجا کمکی نمی‌کند — دو پرداختِ متفاوت‌اند.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function exclusively(string $tracking, Closure $work): mixed
    {
        return Cache::lock('gateway-tracking:'.sha1($tracking), 15)->block(10, $work);
    }
}
