<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\GrantPlanRequest;
use App\Http\Requests\RevokePlanRequest;
use App\Http\Requests\StorePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscription\PlanGate;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * پکیج‌های اشتراک برای ادمینِ کل: تعریف/ویرایش/فعال‌وغیرفعال‌کردنِ پکیج، و
 * فعال‌سازیِ دستیِ یک پلن برای یک مجتمع (بدونِ پرداخت).
 */
class PlanController extends Controller
{
    public function __construct(protected PlanGate $plans) {}

    public function index(): JsonResponse
    {
        $plans = Plan::orderBy('sort_order')->orderBy('id')->get();

        $complexes = Complex::orderBy('name')->get()->map(function (Complex $c) {
            $active = $this->plans->activeSubscription($c);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'activePlan' => $active ? $active->planLabel() : null,
                'activeUntil' => $active?->ends_at ? Jalali::date($active->ends_at) : null,
            ];
        });

        return response()->json([
            'plans' => $plans->map(fn (Plan $p) => $this->present($p))->values(),
            'complexes' => $complexes->values(),
        ]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $plan = Plan::create($data);

        return response()->json(['message' => 'پکیج ساخته شد.', 'plan' => $this->present($plan)], 201);
    }

    public function update(StorePlanRequest $request, Plan $plan): JsonResponse
    {
        $plan->update($request->validated());

        return response()->json(['message' => 'پکیج به‌روزرسانی شد.', 'plan' => $this->present($plan->fresh())]);
    }

    /** فعال/غیرفعال کردنِ خودِ پکیج (نه اشتراکِ مجتمع). */
    public function toggle(Plan $plan): JsonResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return response()->json(['message' => 'وضعیت پکیج تغییر کرد.', 'plan' => $this->present($plan)]);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        // اشتراک‌هایی که به این پکیج وصل‌اند plan_idشان null می‌شود (nullOnDelete).
        $plan->delete();

        return response()->json(['message' => 'پکیج حذف شد.']);
    }

    /** فعال‌سازیِ دستیِ یک پلن برای یک مجتمع (آفر/هدیه، بدونِ پرداخت). */
    public function grant(GrantPlanRequest $request): JsonResponse
    {
        $data = $request->validated();

        $complex = Complex::findOrFail($data['complex_id']);
        $plan = Plan::findOrFail($data['plan_id']);
        $months = $data['months'] ?? max(1, $plan->months);

        // اشتراکِ فعالِ قبلی لغو می‌شود تا هم‌پوشانی نداشته باشیم.
        Subscription::where('complex_id', $complex->id)
            ->where('status', 'active')
            ->update(['status' => 'canceled']);

        $subscription = Subscription::create([
            'complex_id' => $complex->id,
            'user_id' => Auth::id(),
            // ستونِ enum سایه می‌گیرد؛ منبعِ واقعی plan_id است.
            'plan' => SubscriptionPlan::Pro->value,
            'plan_id' => $plan->id,
            'status' => 'active',
            'method' => 'manual',
            'amount' => 0,
            'months' => $months,
            'starts_at' => now(),
            'ends_at' => now()->addMonths($months),
            'paid_at' => now(),
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => 'فعال‌سازی دستی توسط ادمین کل',
        ]);

        return response()->json([
            'message' => 'پلن «'.$plan->name.'» برای مجتمع «'.$complex->name.'» تا '
                .Jalali::date($subscription->ends_at).' فعال شد.',
        ], 201);
    }

    /** غیرفعال‌سازیِ دستیِ اشتراکِ فعالِ یک مجتمع. */
    public function revoke(RevokePlanRequest $request): JsonResponse
    {
        $data = $request->validated();

        $count = Subscription::where('complex_id', $data['complex_id'])
            ->where('status', 'active')
            ->update(['status' => 'canceled']);

        return response()->json([
            'message' => $count > 0 ? 'اشتراکِ مجتمع غیرفعال شد.' : 'این مجتمع اشتراکِ فعالی نداشت.',
        ]);
    }

    /**
     * شکلِ خروجی حالا در `PlanResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Plan $p): array
    {
        return (new PlanResource($p))->toArray(request());
    }
}
