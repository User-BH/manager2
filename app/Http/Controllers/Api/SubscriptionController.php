<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountState;
use App\Enums\ComplexType;
use App\Enums\PlanTier;
use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\CheckoutIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscription\ComplexPurchase;
use App\Services\Subscription\PlanGate;
use App\Services\Subscription\SubscriptionGatewayManager;
use App\Support\Jalali;
use App\Support\PlanCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
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
 *
 * ─── ۱۳/۶ (ایرادات ۱۰، بخشِ اول) ───────────────────────────────────────────
 * فقط مدیرِ مجتمعِ فعال و حسابِ اولیه (`SubscriptionPolicy::viewAccount`) — نه واحد، نه سوپر ادمین (بندهای ۲ و ۵).
 * کارت‌ها، قیمتِ هر مدت و «تمدید یا تغییر» همه از سرور (`PlanCatalog`، `SubscriptionRenewal`)؛ رابط فقط نشان می‌دهد.
 */
class SubscriptionController extends Controller
{
    /** تاریخچه‌ی پرداخت‌ها در هر صفحه (بندِ ۲۸) */
    public const HISTORY_PER_PAGE = 15;

    public function __construct(
        protected SubscriptionGatewayManager $gateways,
        protected PlanGate $plans,
        protected ComplexPurchase $purchase,
    ) {}

    public function show(): JsonResponse
    {
        $this->authorize('viewAccount', Subscription::class);

        $user = Auth::user();
        $initial = AccountState::of($user) === AccountState::Initial;
        $complex = $initial ? null : $this->currentComplex();
        $active = $this->plans->activeSubscription($complex);
        $plan = $this->plans->planFor($complex);
        $currentPlan = $active?->planRef;

        // مدیر فقط کارت‌های نوعِ مجتمعِ خودش را می‌بیند (نوع را فقط سوپر ادمین عوض می‌کند)؛ حسابِ اولیه هر دو نوع را
        $types = $complex ? [$complex->type] : ComplexType::cases();
        $catalog = collect($types)->mapWithKeys(fn (ComplexType $type) => [
            $type->value => PlanCatalog::cards($type, $currentPlan?->id),
        ])->all();
        $renewable = $currentPlan && $complex && Plan::catalogFor($complex->type)->contains('id', $currentPlan->id);

        return response()->json([
            'account' => $initial ? 'initial' : 'manager',
            'complexName' => $complex?->name,
            'complexType' => $complex?->type->value,
            'complexTypeLabel' => $complex?->type->label(),
            // شناسه‌ی پلنِ فعلی: slugِ پکیجِ دیتابیسی، یا مقدارِ enumِ قدیمی، یا free.
            'currentPlan' => $active
                ? ($active->plan_id ? $currentPlan?->slug : $active->plan->value)
                : 'free',
            'currentPlanLabel' => $plan->planLabel(),
            'current' => $active ? [
                ...$this->present($active),
                'tierLabel' => $currentPlan?->tier?->label(),
                'months' => $active->months,
                // مدتِ باقی‌مانده تا پایانِ آخرین دوره‌ی پرداخت‌شده — تمدیدهای صف‌شده هم (بندِ ۱۱۴، پرسشِ ۳)
                'paidUntil' => Jalali::date($this->paidUntil($active)),
                'daysLeftTotal' => max(0, (int) now()->diffInDays($this->paidUntil($active), false)),
            ] : null,
            // «تمدیدِ پلنِ فعلی» (بندِ ۲۰): فقط مدیر و فقط اگر همان پلن هنوز عرضه می‌شود
            'renewal' => $renewable ? ['plan' => $currentPlan->slug] : null,
            // مصرف فعلی در برابر سقف پلن — تا کاربر بداند چرا محدود شده
            'usage' => $complex ? [
                'units' => $this->plans->unitCount($complex),
                'unitLimit' => $plan->unitLimit(),
            ] : null,
            'catalog' => $catalog,
            'durations' => PlanTier::DURATIONS,
            'baseFeatures' => PlanCatalog::BASE,
            'checkoutEnabled' => $this->gateways->isEnabled(),
            'checkoutAction' => route('subscription.checkout'),
            'supportPhone' => config('subscription.support_phone'),

            /*
             * ⚠️ شکلِ پیش از ۱۳/۶ — یک چرخه‌ی استقرار برای تبِ بازِ نسخه‌ی پیشین (Expand→Contract ِ `API_COMPATIBILITY.md`).
             * همان کارت‌ها با قیمتِ امروزِ همان مدتی که آن صفحه نشان می‌داد؛ خرید از آن صفحه با همان قاعده‌های سرور.
             */
            'freeFeatures' => SubscriptionPlan::Free->features(),
            'plans' => $complex ? $this->legacyPlans($complex->type) : [],
            'newComplexPlans' => collect(ComplexType::cases())->mapWithKeys(fn (ComplexType $type) => [
                $type->value => $this->legacyPlans($type),
            ])->all(),
            'bankInfo' => ['holder' => '', 'bank_name' => '', 'card' => '', 'iban' => ''],
            'history' => $complex
                ? Subscription::where('complex_id', $complex->id)->with(['user', 'planRef'])->orderByDesc('created_at')->limit(20)->get()
                    ->map(fn (Subscription $s) => $this->present($s))->values()
                : [],
        ]);
    }

    /**
     * «تاریخچه‌ی پرداخت‌ها» (بندهای ۲۶ تا ۲۸): نام و نوعِ پلن، مدت، مبلغ، تاریخ و ساعت، وضعیت — صفحه‌بندی‌شده.
     *
     * مدیر: همه‌ی خریدهای **همین مجتمع** (هر مدیری که خریده)؛ حسابِ اولیه: خریدهای خودش برای ساختِ مجتمع. شناسه‌ی مجتمع
     * از زمینه‌ی سرور است، نه از درخواست — پرداختِ مجتمعِ دیگر این‌جا نمی‌آید (بندِ ۱۰۵).
     */
    public function history(): JsonResponse
    {
        $this->authorize('viewAccount', Subscription::class);

        $user = Auth::user();

        if (AccountState::of($user) === AccountState::Initial) {
            $page = CheckoutIntent::where('user_id', $user->id)->with('plan')->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(self::HISTORY_PER_PAGE);
            $rows = collect($page->items())->map(fn (CheckoutIntent $i) => [
                'id' => 'intent-'.$i->id,
                'planLabel' => $i->plan_name,
                'tierLabel' => $i->plan?->tier?->label(),
                'complexTypeLabel' => $i->complex_type->label(),
                'complexName' => $i->complex_name,
                'months' => $i->months,
                'amountLabel' => Jalali::money($i->amount),
                'date' => Jalali::date($i->created_at),
                'time' => $i->created_at->format('H:i'),
                'status' => $i->status,
                'statusLabel' => $i->statusLabel(),
                'trackingCode' => $i->tracking_code,
            ]);
        } else {
            $complex = $this->requireComplex();
            $page = Subscription::where('complex_id', $complex->id)->with(['user', 'planRef'])
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(self::HISTORY_PER_PAGE);
            $rows = collect($page->items())->map(fn (Subscription $s) => [
                'id' => 'subscription-'.$s->id,
                'planLabel' => $s->planLabel(),
                'tierLabel' => $s->planRef?->tier?->label(),
                'complexTypeLabel' => $complex->type->label(),
                'complexName' => $complex->name,
                'months' => $s->months,
                'amountLabel' => Jalali::money($s->amount),
                'date' => Jalali::date($s->created_at),
                'time' => $s->created_at->format('H:i'),
                'status' => $s->status,
                'statusLabel' => $s->statusLabel(),
                'trackingCode' => $s->tracking_code,
            ]);
        }

        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
            ],
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

    /** پایانِ آخرین دوره‌ی پرداخت‌شده‌ی مجتمع — دوره‌ی جاری و تمدیدهای پشتش */
    private function paidUntil(Subscription $active): Carbon
    {
        $latest = Subscription::where('complex_id', $active->complex_id)->where('status', 'active')
            ->where('ends_at', '>', now())->max('ends_at');

        return $latest !== null ? Carbon::parse($latest) : Carbon::parse($active->ends_at);
    }

    /**
     * کارت‌ها به شکلِ پیش از ۱۳/۶: یک قیمت و یک مدت — مدتِ پیشینِ پلن (`plans.months`) اگر هنوز قیمت دارد، وگرنه کوتاه‌ترین
     * مدتِ قیمت‌دار. فقط برای تبِ باز؛ مبلغِ واقعی را همان لحظه سرور از جدول می‌خواند.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyPlans(ComplexType $type): array
    {
        return $this->purchase->plans($type)->map(function (Plan $p): array {
            $months = $p->priceFor((int) $p->months) !== null ? (int) $p->months : (int) collect(PlanTier::DURATIONS)->first(fn (int $m) => $p->priceFor($m) !== null);
            $price = (int) $p->priceFor($months);

            return [
                'value' => $p->slug,
                'label' => $p->name,
                'price' => $price,
                'priceLabel' => Jalali::money($price),
                'months' => $months,
                'features' => $p->features ?? [],
                'savingPercent' => 0,
            ];
        })->values()->all();
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
