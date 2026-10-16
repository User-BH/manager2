<?php

namespace App\Services\Subscription;

use App\Enums\ComplexType;
use App\Enums\SubscriptionPlan;
use App\Exceptions\DomainException;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payment\TrackingCodes;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تمدیدِ اشتراکِ مجتمعِ موجود — **فقط آنلاین** (مرحلهٔ ۱۲ · فاز ۲؛ تصمیمِ قطعیِ کارفرما ۲۰۲۶-۱۰-۱۶).
 *
 *   مدیر ← پلن (سمتِ سرور) ← اشتراکِ `pending` ← درگاه ← تأییدِ مستقلِ سرور ← **در یک تراکنش:** فعال
 *
 * هیچ رسید، تأییدِ دستی یا کدِ رهگیریِ دستی در این مسیر نیست. فعال شدن فقط از `complete` و فقط برای ردیفِ
 * `pending` ِ زیرِ قفل؛ پس بازگشتِ تکراری، refresh، callback ِ هم‌زمان و بازبینیِ دوره‌ای (`payments:reconcile`) همه
 * یک نتیجه دارند و هیچ دوره‌ای دو بار حساب نمی‌شود. مبلغ و مدت از پلن، نه از درخواست؛ و تأییدِ درگاه با همان مبلغِ
 * ردیف (`gatewayAmount`) — مبلغِ دیگر «نامعلوم» است، نه موفق.
 *
 * تمدید پیش از پایانِ دوره زمانی را نمی‌سوزاند: دوره‌ی تازه از پایانِ آخرین دوره‌ی فعال شروع می‌شود.
 */
class SubscriptionRenewal
{
    /** @return array{shadow: string, plan_id: int|null, amount: int, months: int} */
    public function resolvePlan(string $slug, ComplexType $type): array
    {
        /*
         * 🔴 `forComplexType` (R67): بدونش مدیرِ مجتمعِ مسکونی slug ِ پکیجِ تجاری را مستقیم می‌فرستاد و تعرفه‌ی
         * آن را می‌گرفت — بی‌آنکه آن پکیج هرگز در صفحه‌اش دیده شده باشد.
         */
        $plan = Plan::where('slug', $slug)->where('is_active', true)->forComplexType($type)->first();

        if ($plan) {
            // ستونِ enum سایه می‌گیرد تا cast نشکند؛ منبعِ واقعی plan_id است
            return ['shadow' => SubscriptionPlan::Pro->value, 'plan_id' => $plan->id, 'amount' => (int) $plan->price, 'months' => (int) $plan->months];
        }

        $legacy = SubscriptionPlan::tryFrom($slug);
        if ($legacy && in_array($legacy, SubscriptionPlan::purchasable(), true)) {
            return ['shadow' => $legacy->value, 'plan_id' => null, 'amount' => $legacy->price(), 'months' => $legacy->months()];
        }

        // قاعده‌ی کسب‌وکار است، نه نبودِ دسترسی
        throw DomainException::invalid('پکیجِ انتخاب‌شده معتبر نیست.', 'subscription.invalid_plan');
    }

    /** اشتراکِ در انتظارِ درگاه؛ مبلغ و مدت از پلن */
    public function begin(Complex $complex, User $buyer, string $slug): Subscription
    {
        $resolved = $this->resolvePlan($slug, $complex->type);

        if ($resolved['amount'] <= 0) {
            throw DomainException::invalid('این پکیج خریدنی نیست.', 'subscription.invalid_plan');
        }

        return Subscription::create([
            'complex_id' => $complex->id,
            'user_id' => $buyer->id,
            'plan' => $resolved['shadow'],
            'plan_id' => $resolved['plan_id'],
            'status' => 'pending',
            'method' => 'online',
            'amount' => $resolved['amount'],
            'months' => $resolved['months'],
        ]);
    }

    /**
     * درگاه پرداخت را تأیید کرد: فعال — یک بار.
     *
     * درونِ `TrackingCodes::exclusively` و تراکنش؛ ردیف زیرِ قفل دوباره خوانده می‌شود و فقط `pending` جلو می‌رود.
     * کدِ رهگیری‌ای که مالِ پرداختِ دیگری است این ردیف را `failed` می‌کند و چیزی فعال نمی‌شود.
     */
    public function complete(Subscription $subscription, string $tracking): Subscription
    {
        return TrackingCodes::exclusively($tracking, fn (): Subscription => DB::transaction(function () use ($subscription, $tracking): Subscription {
            $fresh = Subscription::lockForUpdate()->findOrFail($subscription->id);

            if ($fresh->status !== 'pending' || $fresh->method !== 'online') {
                return $fresh;
            }

            if (TrackingCodes::claimedElsewhere($tracking, $fresh)) {
                $fresh->update(['status' => 'failed', 'review_note' => 'کدِ رهگیری پیش‌تر مصرف شده است']);

                return $fresh;
            }

            // دوره‌ی تازه از پایانِ آخرین دوره‌ی فعالِ همین مجتمع، نه از امروز (زمانِ خریده‌شده نمی‌سوزد)
            $current = Subscription::where('complex_id', $fresh->complex_id)
                ->where('status', 'active')
                ->whereKeyNot($fresh->id)
                ->where('ends_at', '>', now())
                ->lockForUpdate()
                ->max('ends_at');
            $starts = $current !== null && now()->lt($current) ? Carbon::parse($current) : now();

            Subscription::activating(fn () => $fresh->update([
                'status' => 'active',
                'tracking_code' => $tracking,
                'paid_at' => now(),
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addMonths($fresh->months),
            ]));

            Audit::log('subscription.renewed', 'تمدیدِ آنلاینِ اشتراک', $fresh, [
                'amount' => (int) $fresh->amount,
                'months' => $fresh->months,
                'tracking_code' => $tracking,
            ], $fresh->complex_id);

            return $fresh;
        }, attempts: 3));
    }

    /** درگاه گفت «نه» (لغو، شکست، مرجعِ بیگانه) — فقط ردیفی که هنوز `pending` است */
    public function fail(Subscription $subscription, string $reason): Subscription
    {
        return DB::transaction(function () use ($subscription, $reason): Subscription {
            $fresh = Subscription::lockForUpdate()->findOrFail($subscription->id);

            if ($fresh->status === 'pending') {
                $fresh->update(['status' => 'failed', 'review_note' => $reason]);
            }

            return $fresh;
        }, attempts: 3);
    }
}
