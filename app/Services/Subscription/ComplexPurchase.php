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
     * قاعده، تا صفحه پلنی را عرضه نکند که سرور رد می‌کند (مرحلهٔ ۹ · فاز ۵).
     *
     * @return Collection<int, Plan>
     */
    public function plans(ComplexType $type): Collection
    {
        return Plan::purchasable()->forComplexType($type)->where('price', '>', 0)->get();
    }

    /**
     * قصدِ خرید؛ مبلغ و مدت از پلن، نه از درخواست.
     *
     * قصدِ بازِ قبلیِ همین کاربر «لغو» می‌شود تا هر بار فقط یکی منتظرِ درگاه باشد.
     */
    public function begin(User $user, Plan $plan, string $complexName, ComplexType $type, ?string $address): CheckoutIntent
    {
        return DB::transaction(function () use ($user, $plan, $complexName, $type, $address): CheckoutIntent {
            $fresh = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);

            if (AccountState::of($fresh) !== AccountState::Initial) {
                throw DomainException::invalid('این حساب از قبل به یک مجتمع وصل است.', 'account.already_member');
            }

            CheckoutIntent::where('user_id', $fresh->id)
                ->where('status', CheckoutIntent::PENDING)
                ->lockForUpdate()
                ->get()
                ->each(fn (CheckoutIntent $old) => $old->transitionTo(CheckoutIntent::CANCELLED, [
                    'failure_reason' => 'قصدِ تازه‌تری جایش را گرفت',
                ]));

            return CheckoutIntent::create([
                'user_id' => $fresh->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'amount' => (int) $plan->price,
                'months' => (int) $plan->months,
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
             * پول گرفته شده ولی ساختن ممکن نیست (حساب در این فاصله عضوِ مجتمعی
             * شد یا دیگر نیست). نه مدیرِ دوم می‌سازیم، نه پرداخت را گم می‌کنیم:
             * `paid` + audit تا ادمینِ کل تعیینِ تکلیف کند.
             */
            if (! $user || AccountState::of($user) !== AccountState::Initial) {
                $fresh->transitionTo(CheckoutIntent::PAID, [
                    'tracking_code' => $tracking,
                    'paid_at' => now(),
                    'failure_reason' => 'حساب دیگر در حالتِ اولیه نیست؛ مجتمع ساخته نشد',
                ]);
                Audit::log('checkout.paid_unfulfilled', 'پرداختِ اشتراک تأیید شد ولی مجتمع ساخته نشد', $fresh, [
                    'user_id' => $fresh->user_id,
                    'amount' => $fresh->amount,
                    'tracking_code' => $tracking,
                ]);

                return $fresh;
            }

            $complex = $this->upgrader->upgrade($user, $fresh->complex_name, $fresh->complex_address, $fresh->complex_type);

            $subscription = Subscription::create([
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
            ]);

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

            return $fresh;
        }, attempts: 3);
    }
}
