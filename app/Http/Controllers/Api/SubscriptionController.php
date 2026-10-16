<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscription\ComplexPurchase;
use App\Services\Subscription\PlanGate;
use App\Services\Subscription\SubscriptionGatewayManager;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * صفحه‌ی «تنظیمات حساب» — وضعیت اشتراک، پلن‌ها، خرید و سابقه.
 *
 * اشتراک به مجتمع تعلق دارد نه به کاربر: هر مدیرِ همان مجتمع باید وضعیت
 * یکسانی ببیند. برای همین همه‌جا با `complex_id` کار می‌شود و `user_id`
 * فقط می‌گوید چه کسی خرید را انجام داده است.
 *
 * خودِ شروع پرداخت آنلاین اینجا نیست: باید مرورگر را واقعاً به درگاه ببرد،
 * پس مثل پرداخت قبض یک فرم POST به روت وب می‌رود (routes/web.php).
 */
class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionGatewayManager $gateways,
        protected PlanGate $plans,
        protected ComplexPurchase $purchase,
    ) {}

    public function show(): JsonResponse
    {
        $complex = $this->currentComplex();
        $active = $this->plans->activeSubscription($complex);
        $plan = $this->plans->planFor($complex);

        return response()->json([
            'complexName' => $complex?->name,
            // شناسه‌ی پلنِ فعلی: slugِ پکیجِ دیتابیسی، یا مقدارِ enumِ قدیمی، یا free.
            'currentPlan' => $active
                ? ($active->plan_id ? $active->planRef?->slug : $active->plan->value)
                : 'free',
            'currentPlanLabel' => $plan->planLabel(),

            'current' => $active ? $this->present($active) : null,

            // مصرف فعلی در برابر سقف پلن — تا کاربر بداند چرا محدود شده
            'usage' => $complex ? [
                'units' => $this->plans->unitCount($complex),
                'unitLimit' => $plan->unitLimit(),
            ] : null,

            'freeFeatures' => SubscriptionPlan::Free->features(),
            'plans' => $this->purchasablePlans($this->complexType()),
            /*
             * حسابِ اولیه هنوز نوعی ندارد: پلن‌های قابلِ خریدِ آنلاین برای **هر** نوع،
             * از همان قاعده‌ای که سرور هنگامِ خرید می‌پذیرد (مرحلهٔ ۹ · فاز ۵).
             */
            // از ۱۰/۵ برای هر حسابِ غیرِ سکو: عضوِ مجتمع‌های دیگر هم مجتمعِ تازه می‌خرد
            'newComplexPlans' => AccountState::of(Auth::user()) !== AccountState::Platform
                ? collect(ComplexType::cases())->mapWithKeys(fn (ComplexType $type) => [
                    $type->value => $this->purchase->plans($type)->map(fn (Plan $p) => [
                        'value' => $p->slug,
                        'label' => $p->name,
                        'price' => $p->price,
                        'priceLabel' => Jalali::money($p->price),
                        'months' => $p->months,
                        'features' => $p->features ?? [],
                    ])->values()->all(),
                ])->all()
                : null,

            'checkoutEnabled' => $this->gateways->isEnabled(),
            'checkoutAction' => route('subscription.checkout'),
            'supportPhone' => config('subscription.support_phone'),
            /*
             * ۱۲/۲: حسابِ مقصدِ واریز دیگر نیست (خرید و تمدید فقط آنلاین). فیلد یک چرخه‌ی استقرار با همان شکل و مقدارِ
             * خالی می‌ماند تا تبِ بازِ نسخه‌ی پیشین نشکند (Expand→Contract ِ `API_COMPATIBILITY.md`)؛ سپس برداشته می‌شود.
             */
            'bankInfo' => ['holder' => '', 'bank_name' => '', 'card' => '', 'iban' => ''],

            // سابقه‌ی کل مجتمع، نه فقط خریدهای همین کاربر
            'history' => Subscription::where('complex_id', $complex?->id)
                ->with('user')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (Subscription $s) => $this->present($s))
                ->values(),
        ]);
    }

    /** لغو اشتراک فعال — تمدید خودکار ندارد، پس تاریخ پایان حفظ می‌شود. */
    public function cancel(Subscription $subscription): JsonResponse
    {
        $complex = $this->requireComplex();

        // اشتراکِ مجتمع دیگری نباید با دستکاری شناسه لغو شود
        $this->authorize('view', $subscription);

        $subscription->update(['status' => 'canceled']);

        return response()->json(['message' => 'اشتراک لغو شد.']);
    }

    /**
     * نوعِ مجتمعِ جاری — و اگر هنوز مجتمعی نیست، پیش‌فرض (R67).
     *
     * ⚠️ کاربرِ «حالتِ اولیه» هنوز مجتمع ندارد ولی باید فهرستِ پکیج‌ها را
     * ببیند. نشان‌دادنِ فهرستِ مسکونی به او درست است: نوع را در همان فرمِ
     * خرید انتخاب می‌کند و از آن لحظه فهرست به نوعِ خودش می‌آید.
     */
    private function complexType(): ComplexType
    {
        $complex = $this->currentComplex();

        return $complex === null ? ComplexType::default() : $complex->type;
    }

    /** فهرستِ پکیج‌های قابلِ خرید؛ پکیج‌های دیتابیسی مقدم‌اند، وگرنه enumِ قدیمی. */
    private function purchasablePlans(ComplexType $type): array
    {
        // 🔴 فیلترِ نوع، همان‌جا که فهرست ساخته می‌شود (R67)
        $dbPlans = Plan::purchasable()->forComplexType($type)->get();

        if ($dbPlans->isNotEmpty()) {
            return $dbPlans->map(fn (Plan $p) => [
                'value' => $p->slug,
                'label' => $p->name,
                'price' => $p->price,
                'priceLabel' => Jalali::money($p->price),
                'months' => $p->months,
                'features' => $p->features ?? [],
                'savingPercent' => 0,
            ])->values()->all();
        }

        return collect(SubscriptionPlan::purchasable())->map(fn (SubscriptionPlan $p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'price' => $p->price(),
            'priceLabel' => Jalali::money($p->price()),
            'months' => $p->months(),
            'features' => $p->features(),
            'savingPercent' => $p === SubscriptionPlan::ProYearly
                ? (int) round((1 - $p->price() / (SubscriptionPlan::Pro->price() * 12)) * 100)
                : 0,
        ])->values()->all();
    }

    /**
     * شکلِ خروجی حالا در `SubscriptionResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Subscription $s): array
    {
        return (new SubscriptionResource($s))->toArray(request());
    }
}
