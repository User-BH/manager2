<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Subscription\PlanGate;
use App\Services\Subscription\SubscriptionGatewayManager;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {}

    public function show(): JsonResponse
    {
        $complex = $this->currentComplex();
        $active = $this->plans->activeSubscription($complex);
        $plan = $this->plans->planFor($complex);

        return response()->json([
            'complexName' => $complex?->name,
            'currentPlan' => $plan->value,
            'currentPlanLabel' => $plan->label(),

            'current' => $active ? $this->present($active) : null,

            // مصرف فعلی در برابر سقف پلن — تا کاربر بداند چرا محدود شده
            'usage' => $complex ? [
                'units' => $this->plans->unitCount($complex),
                'unitLimit' => $plan->unitLimit(),
            ] : null,

            'freeFeatures' => SubscriptionPlan::Free->features(),
            'plans' => collect(SubscriptionPlan::purchasable())->map(fn (SubscriptionPlan $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'price' => $p->price(),
                'priceLabel' => Jalali::money($p->price()),
                'months' => $p->months(),
                'features' => $p->features(),
                'savingPercent' => $p === SubscriptionPlan::ProYearly
                    ? (int) round((1 - $p->price() / (SubscriptionPlan::Pro->price() * 12)) * 100)
                    : 0,
            ])->values(),

            'checkoutEnabled' => $this->gateways->isEnabled(),
            'checkoutAction' => route('subscription.checkout'),
            'supportPhone' => config('subscription.support_phone'),
            'bankInfo' => config('subscription.bank'),

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

    /**
     * ثبت خرید با «واریز و آپلود رسید».
     *
     * تا وقتی درگاهِ اشتراک فعال نشده، این تنها راه خرید است. اشتراک با
     * وضعیت «در انتظار بررسی» ساخته می‌شود و تا تایید ادمین کل فعال نمی‌شود.
     */
    public function uploadReceipt(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403, 'خرید اشتراک فقط برای مدیران مجتمع است.');

        $complex = $this->requireComplex();

        $data = $request->validate([
            'plan' => ['required', 'in:pro,pro_yearly'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:300'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ], [
            'receipt.mimes' => 'فایل رسید باید تصویر (jpg/png) یا PDF باشد.',
            'receipt.max' => 'حجم فایل رسید نباید از ۴ مگابایت بیشتر باشد.',
            'paid_on.before_or_equal' => 'تاریخ واریز نمی‌تواند در آینده باشد.',
        ], [
            'plan' => 'پلن', 'paid_on' => 'تاریخ واریز', 'receipt' => 'فایل رسید',
        ]);

        // هر مجتمع هم‌زمان فقط یک درخواست در انتظار بررسی داشته باشد، وگرنه
        // صف بررسی ادمین با درخواست‌های تکراری پر می‌شود.
        $alreadyPending = Subscription::where('complex_id', $complex->id)
            ->where('status', 'pending')
            ->where('method', 'receipt')
            ->exists();

        abort_if($alreadyPending, 422, 'یک درخواست اشتراک در انتظار بررسی دارید. تا تعیین تکلیف آن، درخواست تازه ثبت نمی‌شود.');

        $plan = SubscriptionPlan::from($data['plan']);

        // دیسک local خصوصی است؛ فایل فقط از مسیر کنترل‌شده‌ی ادمین سرو می‌شود
        $path = $request->file('receipt')->store('subscription-receipts/'.$complex->id, 'local');

        $subscription = Subscription::create([
            'complex_id' => $complex->id,
            'user_id' => $user->id,
            'plan' => $plan,
            'status' => 'pending',
            'method' => 'receipt',
            // مبلغ از enum خوانده می‌شود نه از درخواست، وگرنه کاربر می‌توانست
            // پلن پرو را به مبلغ دلخواه ثبت کند.
            'amount' => $plan->price(),
            'months' => $plan->months(),
            'receipt_path' => $path,
            'receipt_original_name' => $request->file('receipt')->getClientOriginalName(),
            'receipt_paid_on' => $data['paid_on'] ?? now(),
            'review_note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'message' => 'رسید ثبت شد و پس از بررسی توسط پشتیبانی، اشتراک فعال می‌شود.',
            'subscription' => $this->present($subscription),
        ], 201);
    }

    /** لغو اشتراک فعال — تمدید خودکار ندارد، پس تاریخ پایان حفظ می‌شود. */
    public function cancel(Subscription $subscription): JsonResponse
    {
        $complex = $this->requireComplex();

        // اشتراکِ مجتمع دیگری نباید با دستکاری شناسه لغو شود
        abort_unless($subscription->complex_id === $complex->id, 403);
        abort_unless(Auth::user()->isAdmin(), 403);

        $subscription->update(['status' => 'canceled']);

        return response()->json(['message' => 'اشتراک لغو شد.']);
    }

    private function present(Subscription $s): array
    {
        return [
            'id' => $s->id,
            'plan' => $s->plan->value,
            'planLabel' => $s->plan->label(),
            'status' => $s->status,
            'statusLabel' => $s->statusLabel(),
            'method' => $s->method,
            'methodLabel' => $s->methodLabel(),
            'amount' => (float) $s->amount,
            'amountLabel' => Jalali::money($s->amount),
            'buyerName' => $s->user?->name,
            'startsAt' => $s->starts_at ? Jalali::date($s->starts_at) : null,
            'endsAt' => $s->ends_at ? Jalali::date($s->ends_at) : null,
            'daysLeft' => $s->ends_at ? max(0, (int) now()->diffInDays($s->ends_at, false)) : 0,
            'trackingCode' => $s->tracking_code,
            'reviewNote' => $s->review_note,
            'hasReceipt' => filled($s->receipt_path),
            'createdAt' => Jalali::dateTime($s->created_at),
        ];
    }
}
