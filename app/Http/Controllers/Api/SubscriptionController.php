<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Subscription\SubscriptionGatewayManager;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * صفحه‌ی «تنظیمات حساب» — وضعیت اشتراک، پلن‌ها و سابقه‌ی خرید.
 *
 * خودِ خرید اینجا نیست: شروع پرداخت باید مرورگر را واقعاً به درگاه ببرد،
 * پس مثل پرداخت قبض یک فرم POST به روت وب می‌رود (routes/web.php).
 */
class SubscriptionController extends Controller
{
    public function __construct(protected SubscriptionGatewayManager $gateways) {}

    public function show(): JsonResponse
    {
        $user = Auth::user();

        $active = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();

        return response()->json([
            'current' => $active ? [
                'id' => $active->id,
                'plan' => $active->plan->value,
                'planLabel' => $active->plan->label(),
                'statusLabel' => $active->statusLabel(),
                'startsAt' => $active->starts_at ? Jalali::date($active->starts_at) : null,
                'endsAt' => $active->ends_at ? Jalali::date($active->ends_at) : null,
                'daysLeft' => $active->ends_at ? max(0, (int) now()->diffInDays($active->ends_at, false)) : 0,
                'trackingCode' => $active->tracking_code,
            ] : null,
            'freeFeatures' => SubscriptionPlan::Free->features(),
            'plans' => collect(SubscriptionPlan::purchasable())->map(fn (SubscriptionPlan $plan) => [
                'value' => $plan->value,
                'label' => $plan->label(),
                'price' => $plan->price(),
                'priceLabel' => Jalali::money($plan->price()),
                'months' => $plan->months(),
                'features' => $plan->features(),
                // صرفه‌جویی پلن سالانه نسبت به دوازده ماه پرداخت ماهانه
                'savingPercent' => $plan === SubscriptionPlan::ProYearly
                    ? (int) round((1 - $plan->price() / (SubscriptionPlan::Pro->price() * 12)) * 100)
                    : 0,
            ])->values(),
            'checkoutEnabled' => $this->gateways->isEnabled(),
            'checkoutAction' => route('subscription.checkout'),
            'supportPhone' => config('subscription.support_phone'),
            'history' => Subscription::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (Subscription $s) => [
                    'id' => $s->id,
                    'planLabel' => $s->plan->label(),
                    'amount' => (float) $s->amount,
                    'amountLabel' => Jalali::money($s->amount),
                    'status' => $s->status,
                    'statusLabel' => $s->statusLabel(),
                    'trackingCode' => $s->tracking_code,
                    'createdAt' => Jalali::dateTime($s->created_at),
                    'endsAt' => $s->ends_at ? Jalali::date($s->ends_at) : null,
                ])->values(),
        ]);
    }

    /** لغو اشتراک فعال — تمدید خودکار ندارد، پس فقط تاریخ پایان را نگه می‌دارد. */
    public function cancel(Subscription $subscription): JsonResponse
    {
        abort_unless($subscription->user_id === Auth::id(), 403);

        $subscription->update(['status' => 'canceled']);

        return response()->json(['message' => 'اشتراک لغو شد.']);
    }
}
