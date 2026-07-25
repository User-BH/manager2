<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscription\PlanGate;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request);
        $plan = Plan::create($data);

        return response()->json(['message' => 'پکیج ساخته شد.', 'plan' => $this->present($plan)], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $plan->update($this->validatePlan($request, $plan));

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
    public function grant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'complex_id' => ['required', 'exists:complexes,id'],
            'plan_id' => ['required', 'exists:plans,id'],
            'months' => ['nullable', 'integer', 'min:1', 'max:60'],
        ], [], ['complex_id' => 'مجتمع', 'plan_id' => 'پکیج']);

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
    public function revoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'complex_id' => ['required', 'exists:complexes,id'],
        ], [], ['complex_id' => 'مجتمع']);

        $count = Subscription::where('complex_id', $data['complex_id'])
            ->where('status', 'active')
            ->update(['status' => 'canceled']);

        return response()->json([
            'message' => $count > 0 ? 'اشتراکِ مجتمع غیرفعال شد.' : 'این مجتمع اشتراکِ فعالی نداشت.',
        ]);
    }

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'price' => ['required', 'integer', 'min:0'],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'unit_limit' => ['nullable', 'integer', 'min:1'],
            'real_gateway' => ['boolean'],
            'excel_export' => ['boolean'],
            'features' => ['array'],
            'features.*' => ['string', 'max:120'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'slug.regex' => 'شناسه فقط می‌تواند حروف کوچک انگلیسی، عدد و خط تیره باشد.',
        ], [
            'name' => 'نام', 'slug' => 'شناسه', 'price' => 'قیمت', 'months' => 'مدت',
        ]);
    }

    private function present(Plan $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'price' => $p->price,
            'priceLabel' => Jalali::money($p->price),
            'months' => $p->months,
            'unit_limit' => $p->unit_limit,
            'real_gateway' => $p->real_gateway,
            'excel_export' => $p->excel_export,
            'features' => $p->features ?? [],
            'is_active' => $p->is_active,
            'sort_order' => $p->sort_order,
        ];
    }
}
