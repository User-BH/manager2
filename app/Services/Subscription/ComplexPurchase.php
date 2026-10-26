<?php

namespace App\Services\Subscription;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Enums\SubscriptionPlan;
use App\Exceptions\DomainException;
use App\Models\CheckoutIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Account\ComplexUpgrader;
use App\Services\Payment\TrackingCodes;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * خریدِ اشتراک برای ساختنِ مجتمع (مرحلهٔ ۹ · فاز ۴؛ اصلاحِ قطعیِ کارفرما).
 *
 *   ثبت‌نام → داشبوردِ اولیه → پلن → پرداختِ آنلاین → تأییدِ درگاه در سرور →
 *   **در یک تراکنش:** مجتمع + اشتراکِ فعال + نقشِ مدیر → داشبوردِ مدیر
 *
 * هیچ رسید، تأییدِ دستی یا فعال‌سازیِ دستی در این مسیر نیست. پیش از پرداخت
 * چیزی جز `CheckoutIntent` ساخته نمی‌شود؛ پرداختِ رهاشده مجتمعِ یتیم نمی‌سازد.
 *
 * ⚠️ همه‌ی گذارهای کاربر از اولیه به مدیر از همین‌جا و `ComplexUpgrader`
 * می‌گذرند تا مرحلهٔ ۱۰ (عضویت) فقط همین نقطه را عوض کند.
 */
class ComplexPurchase
{
    public function __construct(private readonly ComplexUpgrader $upgrader) {}

    /**
     * پلنِ قابلِ خریدِ آنلاین برای این نوع مجتمع، یا خطای روشن.
     *
     * فقط پلنِ دیتابیسیِ فعال با قیمتِ مثبت: پلنِ رایگان بیرون از scope است و
     * درگاه مبلغِ صفر را نمی‌پذیرد. نام، قیمت و تعدادِ پلن‌ها را کارفرما بعداً
     * تعیین می‌کند؛ اینجا فقط از جدولِ `plans` خوانده می‌شود.
     */
    public function plan(string $slug, ComplexType $type): Plan
    {
        $plan = $this->plans($type)->firstWhere('slug', $slug);

        if (! $plan) {
            throw DomainException::invalid('پکیجِ انتخاب‌شده برای این نوع مجتمع قابلِ خرید نیست.', 'subscription.invalid_plan');
        }

        return $plan;
    }

    /**
     * همه‌ی پلن‌های قابلِ خریدِ آنلاین برای مجتمعِ تازه از این نوع.
     *
     * همان فهرستی که صفحه‌ی خرید نشان می‌دهد و همان که `plan()` می‌پذیرد — یک
     * قاعده، تا صفحه پلنی را عرضه نکند که سرور رد می‌کند (مرحلهٔ ۹ · فاز ۵). از ۱۳/۶ همان کارت‌های سطح‌دارِ
     * `Plan::catalogFor`، که تمدید هم از آن می‌خواند.
     *
     * @return Collection<int, Plan>
     */
    public function plans(ComplexType $type): Collection
    {
        return Plan::catalogFor($type);
    }

    /**
     * قصدِ خرید؛ مبلغ و مدت از جدولِ قیمت (`Plan::priceFor`)، نه از درخواست — مدتِ بی‌قیمت خریدنی نیست.
     *
     * قصدِ بازِ قبلیِ همین کاربر «لغو» می‌شود تا هر بار فقط یکی منتظرِ درگاه باشد.
     */
    public function begin(User $user, Plan $plan, string $complexName, ComplexType $type, ?string $address, ?int $months = null): CheckoutIntent
    {
        // ⚠️ مدتِ خالی فقط برای تبِ بازِ نسخه‌ی پیشین: همان مدتی که آن صفحه نشان می‌داد
        $months ??= (int) $plan->months;
        $amount = $plan->priceFor($months);

        if ($amount === null) {
            throw DomainException::invalid('این مدت برای این پلن قیمت ندارد و فعلاً خریدنی نیست.', 'subscription.invalid_duration');
        }

        return DB::transaction(function () use ($user, $plan, $complexName, $type, $address, $months, $amount): CheckoutIntent {
            $fresh = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);

            /*
             * از ۱۰/۵ (تصمیمِ کارفرما) عضوِ مجتمع‌های دیگر هم می‌خرد؛ فقط حسابِ سکو و حسابِ غیرفعال نه.
             * قاعده‌های تجاریِ واقعی سرِ جایشان‌اند: پلنِ قابلِ خرید و هم‌نوع (`plan`)، مبلغ و مدت از
             * پلن، و یک قصدِ باز برای هر حساب (پایین).
             */
            if (AccountState::of($fresh) === AccountState::Platform || ! $fresh->is_active) {
                throw DomainException::invalid('این حساب نمی‌تواند مجتمع بخرد.', 'checkout.buyer_not_allowed');
            }

            /*
             * ⚠️ فقط قصدی که هنوز به درگاه نرسیده (۱۲/۲). قصدی که مرجعِ درگاه گرفته شاید همین حالا پرداخت شده
             * باشد — لغوش پولِ گرفته‌شده را بی‌صاحب می‌کرد. آن‌ها `pending` می‌مانند تا بازگشتِ بانک یا بازبینیِ دوره‌ای
             * (`payments:reconcile`) تکلیفشان را روشن کند.
             */
            CheckoutIntent::where('user_id', $fresh->id)
                ->where('status', CheckoutIntent::PENDING)
                ->whereNull('authority')
                ->lockForUpdate()
                ->get()
                ->each(fn (CheckoutIntent $old) => $old->transitionTo(CheckoutIntent::CANCELLED, [
                    'failure_reason' => 'قصدِ تازه‌تری جایش را گرفت',
                ]));

            return CheckoutIntent::create([
                'user_id' => $fresh->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'amount' => $amount,
                'months' => $months,
                'complex_name' => $complexName,
                'complex_type' => $type,
                'complex_address' => $address,
            ]);
        }, attempts: 3);
    }

    /** درگاه پاسخِ «نه» داد (لغو، شکست، مرجعِ بیگانه). */
    public function fail(CheckoutIntent $intent, string $reason): CheckoutIntent
    {
        return DB::transaction(function () use ($intent, $reason): CheckoutIntent {
            $fresh = CheckoutIntent::lockForUpdate()->findOrFail($intent->id);

            // بازگشتِ دوباره پس از تعیینِ تکلیف، چیزی را عوض نمی‌کند
            if ($fresh->isPending()) {
                $fresh->transitionTo(CheckoutIntent::FAILED, ['failure_reason' => $reason]);
            }

            return $fresh;
        }, attempts: 3);
    }

    /**
     * پرداخت تأیید شد: مجتمع، اشتراک و مدیر — همه یا هیچ.
     *
     * باید درونِ `TrackingCodes::exclusively($tracking, …)` صدا زده شود.
     * بازگشتِ دوباره (refresh، callback ِ تکراری، درخواستِ هم‌زمان) همان نتیجه را
     * برمی‌گرداند و هیچ چیزِ تکراری نمی‌سازد: ردیفِ قصد زیرِ قفل دوباره خوانده
     * می‌شود و فقط `pending` جلو می‌رود.
     */
    public function complete(CheckoutIntent $intent, string $tracking): CheckoutIntent
    {
        return DB::transaction(function () use ($intent, $tracking): CheckoutIntent {
            $fresh = CheckoutIntent::lockForUpdate()->findOrFail($intent->id);

            if (! $fresh->isPending()) {
                return $fresh;
            }

            // 🔴 یک کدِ رهگیری، یک پرداخت (همان قاعده‌ی قبض و اشتراک)
            if (TrackingCodes::claimedElsewhere($tracking, $fresh)) {
                $fresh->transitionTo(CheckoutIntent::FAILED, ['failure_reason' => 'کدِ رهگیری پیش‌تر مصرف شده است']);

                return $fresh;
            }

            $user = $fresh->user_id
                ? User::withoutGlobalScopes()->lockForUpdate()->find($fresh->user_id)
                : null;

            /*
             * پول گرفته شده ولی ساختن ممکن نیست (حساب در این فاصله حذف یا غیرفعال شد). نه مجتمعِ
             * بی‌صاحب می‌سازیم، نه پرداخت را گم می‌کنیم: `paid` + audit تا ادمینِ کل تعیینِ تکلیف کند.
             * از ۱۰/۵ عضویت در مجتمعِ دیگر دلیلِ این شاخه نیست (تصمیمِ کارفرما).
             */
            if (! $user || ! $user->is_active || AccountState::of($user) === AccountState::Platform) {
                $fresh->transitionTo(CheckoutIntent::PAID, [
                    'tracking_code' => $tracking,
                    'paid_at' => now(),
                    'failure_reason' => 'حساب دیگر نمی‌تواند مجتمع داشته باشد؛ مجتمع ساخته نشد',
                ]);
                Audit::log('checkout.paid_unfulfilled', 'پرداختِ اشتراک تأیید شد ولی مجتمع ساخته نشد', $fresh, [
                    'user_id' => $fresh->user_id,
                    'amount' => $fresh->amount,
                    'tracking_code' => $tracking,
                ]);

                return $fresh;
            }

            $this->fulfil($fresh, $user, $tracking);

            return $fresh;
        }, attempts: 3);
    }

    /**
     * قصدِ `paid` — پول گرفته شد ولی آن لحظه مجتمع ساخته نشد — اگر حساب دوباره واجدِ شرایط است، همین حالا کامل
     * می‌شود (مرحلهٔ ۱۲ · فاز ۲). بازبینیِ دوره‌ای صدایش می‌زند؛ پرداختِ دوباره لازم نیست. قصدی که بازپرداخت شده یا
     * حسابی که هنوز نمی‌تواند همان می‌ماند.
     */
    public function retryPaid(CheckoutIntent $intent): CheckoutIntent
    {
        return DB::transaction(function () use ($intent): CheckoutIntent {
            $fresh = CheckoutIntent::lockForUpdate()->findOrFail($intent->id);
            $user = $fresh->user_id ? User::withoutGlobalScopes()->lockForUpdate()->find($fresh->user_id) : null;

            if ($fresh->status !== CheckoutIntent::PAID || ! $user || ! $user->is_active || AccountState::of($user) === AccountState::Platform) {
                return $fresh;
            }

            $this->fulfil($fresh, $user, (string) $fresh->tracking_code);

            return $fresh;
        }, attempts: 3);
    }

    /**
     * بازپرداختِ قصدِ `paid` ثبت شد — کارِ ادمینِ کل پس از برگرداندنِ پول در بانک. فقط ثبت است، نه فعال‌سازی؛ پس از آن
     * هیچ چیزی از این پرداخت ساخته نمی‌شود.
     */
    public function refund(CheckoutIntent $intent, User $by, string $reference): CheckoutIntent
    {
        return DB::transaction(function () use ($intent, $by, $reference): CheckoutIntent {
            $fresh = CheckoutIntent::lockForUpdate()->findOrFail($intent->id);

            if ($fresh->status !== CheckoutIntent::PAID) {
                throw DomainException::invalid('فقط پرداختِ تأییدشده‌ای که مجتمعش ساخته نشده بازپرداخت می‌شود.', 'checkout.not_refundable');
            }

            $fresh->transitionTo(CheckoutIntent::REFUNDED, [
                'refunded_at' => now(),
                'refund_reference' => $reference,
                'refunded_by' => $by->id,
            ]);
            Audit::log('checkout.refunded', 'ثبتِ بازپرداختِ خریدِ ساخته‌نشده', $fresh, [
                'user_id' => $fresh->user_id,
                'amount' => $fresh->amount,
                'tracking_code' => $fresh->tracking_code,
                'refund_reference' => $reference,
            ]);

            return $fresh;
        }, attempts: 3);
    }

    /** مجتمع، اشتراکِ فعال و عضویتِ مدیر — درونِ تراکنشِ فراخواننده؛ قصد به `completed` */
    private function fulfil(CheckoutIntent $fresh, User $user, string $tracking): void
    {
        $complex = $this->upgrader->upgrade($user, $fresh->complex_name, $fresh->complex_address, $fresh->complex_type);

        // فعال‌سازیِ آنلاین فقط از همین مسیرِ تأییدشده (`Subscription::activating`، ۱۲/۲)
        $subscription = Subscription::activating(fn (): Subscription => Subscription::create([
            'complex_id' => $complex->id,
            'user_id' => $user->id,
            // ستونِ enum ِ قدیمی سایه‌ای معتبر می‌گیرد؛ منبعِ واقعی plan_id است
            'plan' => SubscriptionPlan::Pro->value,
            'plan_id' => $fresh->plan_id,
            'status' => 'active',
            'method' => 'online',
            'amount' => $fresh->amount,
            'months' => $fresh->months,
            'gateway' => $fresh->gateway,
            'ref_id' => $fresh->authority,
            'tracking_code' => $tracking,
            'paid_at' => now(),
            'starts_at' => now(),
            'ends_at' => now()->addMonths($fresh->months),
        ]));

        $fresh->transitionTo(CheckoutIntent::COMPLETED, [
            'tracking_code' => $tracking,
            'paid_at' => now(),
            'completed_at' => now(),
            'complex_id' => $complex->id,
            'subscription_id' => $subscription->id,
        ]);

        Audit::log('checkout.completed', 'خریدِ اشتراک و ساختِ مجتمع', $fresh, [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'amount' => $fresh->amount,
        ], $complex->id);

    }
}
