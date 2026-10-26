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
 *
 * از ۱۳/۶ (ایرادات ۱۰، بندهای ۲۰ تا ۲۴): خریدِ **همان** پلن تمدید است و به باقی‌مانده اضافه می‌شود؛ خریدِ پلنِ **دیگر**
 * تغییر است — فقط با تأییدِ صریحِ خریدار ساخته می‌شود و پلنِ قبلی را در لحظه‌ی تأییدِ پرداخت خاتمه می‌دهد.
 */
class SubscriptionRenewal
{
    /** همان پلن: مدتِ تازه به باقی‌مانده اضافه می‌شود (بندِ ۲۱) */
    public const RENEWAL = 'renewal';

    /** پلنِ دیگر: پلنِ قبلی در لحظه‌ی تأییدِ پرداخت خاتمه می‌یابد، باقی‌مانده منتقل نمی‌شود (بندِ ۲۲) */
    public const CHANGE = 'change';

    public function __construct(private readonly PlanGate $gate) {}

    /**
     * پلن، مدت و مبلغ — فقط از کارت‌های همین نوع مجتمع (`Plan::catalogFor`) و جدولِ قیمت‌ها، در همین لحظه (بندهای ۷، ۸ و
     * ۲۵). مبلغ هرگز از درخواست نیست.
     *
     * 🔴 از ۱۳/۶ پلن‌های قدیمیِ درون‌کد (`SubscriptionPlan::Pro`، `ProYearly`) خریدنی نیستند: قیمتشان در کد بود و از
     * قیمت‌گذاریِ سوپر ادمین و تعرفه‌ی نوعِ مجتمع می‌گذشت (بندِ ۷: «نباید قیمتی در کد hard-code شده باشد»). اشتراک‌های
     * قدیمیِ آن‌ها خوانده می‌شوند، ولی خرید و تمدید فقط از جدولِ پلن‌هاست.
     *
     * ⚠️ `$months` ِ خالی فقط برای تبِ بازِ نسخه‌ی پیشین (Expand ِ `API_COMPATIBILITY.md`): همان مدتی که آن صفحه نشان
     * می‌داد (`plans.months`)، با قیمتِ امروزِ جدول.
     *
     * @return array{plan: Plan, amount: int, months: int}
     */
    public function resolvePlan(string $slug, ?int $months, ComplexType $type): array
    {
        $plan = Plan::catalogFor($type)->firstWhere('slug', $slug);

        if (! $plan) {
            // قاعده‌ی کسب‌وکار است، نه نبودِ دسترسی
            throw DomainException::invalid('پکیجِ انتخاب‌شده معتبر نیست.', 'subscription.invalid_plan');
        }

        $months ??= (int) $plan->months;
        $amount = $plan->priceFor($months);

        if ($amount === null) {
            throw DomainException::invalid('این مدت برای این پلن قیمت ندارد و فعلاً خریدنی نیست.', 'subscription.invalid_duration');
        }

        return ['plan' => $plan, 'amount' => $amount, 'months' => $months];
    }

    /**
     * تمدید یا تغییر؟ از اشتراکِ جاریِ مجتمع، در سرور. «همان پلن» یعنی همان ردیفِ پلن (`plan_id`) — مدتِ دیگرِ همان پلن
     * هم تمدید است. بی اشتراکِ جاری: `null` (خریدِ تازه، از امروز).
     */
    public function kindFor(Complex $complex, Plan $plan): ?string
    {
        $current = $this->gate->activeSubscription($complex);

        if ($current === null) {
            return null;
        }

        return $current->plan_id === $plan->id ? self::RENEWAL : self::CHANGE;
    }

    /**
     * اشتراکِ در انتظارِ درگاه؛ مبلغ و مدت از جدولِ قیمت.
     *
     * 🔴 تغییرِ پلن بی تأییدِ صریحِ خریدار ساخته نمی‌شود (بندهای ۲۲ تا ۲۴، ۱۰۵): بی آن هیچ ردیفی ثبت نمی‌شود و چیزی عوض
     * نمی‌شود. آنچه خریدار پذیرفت (`purchase_kind`) روی همان ردیف می‌ماند؛ خاتمه‌ی پلنِ قبلی فقط در `complete`.
     */
    public function begin(Complex $complex, User $buyer, string $slug, ?int $months = null, bool $confirmChange = false): Subscription
    {
        $resolved = $this->resolvePlan($slug, $months, $complex->type);
        $kind = $this->kindFor($complex, $resolved['plan']);

        if ($kind === self::CHANGE && ! $confirmChange) {
            throw DomainException::invalid(
                'شما در حالِ تغییرِ پلن هستید: پلنِ فعلی خاتمه پیدا می‌کند و مدتِ باقی‌مانده‌اش منتقل نمی‌شود. برای ادامه، تغییر را تأیید کنید.',
                'subscription.change_unconfirmed',
            );
        }

        return Subscription::create([
            'complex_id' => $complex->id,
            'user_id' => $buyer->id,
            // ستونِ enum سایه می‌گیرد تا cast نشکند؛ منبعِ واقعی plan_id است
            'plan' => SubscriptionPlan::Pro->value,
            'plan_id' => $resolved['plan']->id,
            'status' => 'pending',
            'method' => 'online',
            'purchase_kind' => $kind ?? self::RENEWAL,
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

            $running = Subscription::where('complex_id', $fresh->complex_id)
                ->where('status', 'active')
                ->whereKeyNot($fresh->id)
                ->where('ends_at', '>', now())
                ->lockForUpdate()
                ->get();

            if ($fresh->purchase_kind === self::CHANGE) {
                /*
                 * 🔴 تغییرِ پلن (بندِ ۲۲): همین‌جا — پس از تأییدِ مستقلِ درگاه، زیرِ همان قفل و تراکنش — پلنِ جاری و هر
                 * تمدیدِ صف‌شده‌ی پشتش خاتمه می‌یابند و پلنِ تازه از همین لحظه شروع می‌شود. باقی‌مانده منتقل نمی‌شود. ردیف‌ها
                 * حذف نمی‌شوند: وضعیتِ `replaced` و پایانِ واقعیِ دوره‌ی جاری سابقه‌اند. پرداختِ ناموفق به این‌جا نمی‌رسد،
                 * پس پلنِ قبلی بی پرداختِ موفق هرگز خاتمه نمی‌یابد (بندِ ۲۴).
                 */
                foreach ($running as $old) {
                    $old->update([
                        'status' => 'replaced',
                        'ends_at' => $old->starts_at !== null && Carbon::parse($old->starts_at)->isFuture() ? $old->ends_at : now(),
                        'review_note' => 'خاتمه با تغییرِ پلن (اشتراکِ #'.$fresh->id.')',
                    ]);
                }
                $starts = now();
            } else {
                // دوره‌ی تازه از پایانِ آخرین دوره‌ی فعالِ همین مجتمع، نه از امروز (زمانِ خریده‌شده نمی‌سوزد؛ بندِ ۲۱)
                $latest = $running->max('ends_at');
                $starts = $latest !== null && now()->lt($latest) ? Carbon::parse($latest) : now();
            }

            Subscription::activating(fn () => $fresh->update([
                'status' => 'active',
                'tracking_code' => $tracking,
                'paid_at' => now(),
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addMonths($fresh->months),
            ]));

            Audit::log($fresh->purchase_kind === self::CHANGE ? 'subscription.changed' : 'subscription.renewed',
                $fresh->purchase_kind === self::CHANGE ? 'تغییرِ پلن با پرداختِ آنلاین' : 'تمدیدِ آنلاینِ اشتراک', $fresh, [
                    'amount' => (int) $fresh->amount,
                    'months' => $fresh->months,
                    'tracking_code' => $tracking,
                    'replaced' => $fresh->purchase_kind === self::CHANGE ? $running->pluck('id')->all() : [],
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
