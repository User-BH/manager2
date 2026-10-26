<?php

namespace App\Http\Controllers\Api\System;

use App\Enums\PlanTier;
use App\Enums\SubscriptionPlan;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GrantPlanRequest;
use App\Http\Requests\RevokePlanRequest;
use App\Http\Requests\StorePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\CheckoutIntent;
use App\Models\Complex;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Services\Subscription\PlanGate;
use App\Support\Audit;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پکیج‌های اشتراک برای ادمینِ کل: تعریف/ویرایش/فعال‌وغیرفعال‌کردنِ پکیج، و
 * فعال‌سازیِ دستیِ یک پلن برای یک مجتمع (بدونِ پرداخت).
 */
class PlanController extends Controller
{
    public function __construct(protected PlanGate $plans) {}

    public function index(): JsonResponse
    {
        $plans = Plan::with('prices')->orderBy('sort_order')->orderBy('id')->get();

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
            // ۱۳/۶: شش مدتِ قابلِ خرید و سه سطح — فرمِ قیمت از همین‌ها ساخته می‌شود، نه از عددِ درون‌کدِ رابط
            'durations' => PlanTier::DURATIONS,
            'tiers' => collect(PlanTier::cases())->map(fn (PlanTier $t) => ['value' => $t->value, 'label' => $t->label()])->values(),
        ]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = DB::transaction(fn (): Plan => $this->save(new Plan, $request->validated()));

        return response()->json(['message' => 'پکیج ساخته شد.', 'plan' => $this->present($plan)], 201);
    }

    public function update(StorePlanRequest $request, Plan $plan): JsonResponse
    {
        $plan = DB::transaction(fn (): Plan => $this->save($plan, $request->validated()));

        return response()->json(['message' => 'پکیج به‌روزرسانی شد.', 'plan' => $this->present($plan)]);
    }

    /** فعال/غیرفعال کردنِ خودِ پکیج (نه اشتراکِ مجتمع). */
    public function toggle(Plan $plan): JsonResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        Audit::log('plan.toggled', $plan->is_active ? 'فعال‌کردنِ پکیجِ اشتراک' : 'غیرفعال‌کردنِ پکیجِ اشتراک', $plan, [
            'is_active' => $plan->is_active,
        ]);

        return response()->json(['message' => 'وضعیت پکیج تغییر کرد.', 'plan' => $this->present($plan->fresh())]);
    }

    /**
     * پلن و قیمتِ هر مدتش — یک تراکنش، یک رویدادِ audit با قیمت‌های پیش و پس (۱۳/۶، بندهای ۶ تا ۸).
     *
     * قیمتِ تازه از همین لحظه در همه‌ی داشبوردها و در هر خرید و تمدید خوانده می‌شود (`Plan::priceFor`)؛ هیچ قیمتی در
     * رابط یا state ذخیره نیست. مدتی که در فرم نیامده برداشته می‌شود — خرید و تمدیدِ در جریان مبلغِ خودشان را دارند.
     *
     * @param  array<string, mixed>  $data
     */
    private function save(Plan $plan, array $data): Plan
    {
        $before = $plan->exists ? $this->snapshot($plan) : null;
        $prices = collect($data['prices'])->keyBy('months');
        unset($data['prices']);

        $plan->fill($data)->save();

        $plan->prices()->whereNotIn('months', $prices->keys()->all())->delete();
        foreach ($prices as $months => $row) {
            PlanPrice::updateOrCreate(
                ['plan_id' => $plan->id, 'months' => (int) $months],
                ['price' => (int) $row['price'], 'is_active' => (bool) ($row['is_active'] ?? true)],
            );
        }

        $plan = $plan->fresh(['prices']);
        Audit::log($before === null ? 'plan.created' : 'plan.updated', $before === null ? 'ساختِ پکیجِ اشتراک' : 'ویرایشِ پکیجِ اشتراک', $plan, [
            'before' => $before,
            'after' => $this->snapshot($plan),
        ]);

        return $plan;
    }

    /** @return array<string, mixed> */
    private function snapshot(Plan $plan): array
    {
        return [
            'name' => $plan->name,
            'tier' => $plan->tier?->value,
            'complex_type' => $plan->complex_type?->value,
            'is_active' => $plan->is_active,
            'unit_limit' => $plan->unit_limit,
            'real_gateway' => $plan->real_gateway,
            'excel_export' => $plan->excel_export,
            'prices' => $plan->prices()->get()->mapWithKeys(fn (PlanPrice $p) => [$p->months => [$p->price, $p->is_active]])->all(),
        ];
    }

    public function destroy(Plan $plan): JsonResponse
    {
        /*
         * 🔴 ۱۲/۴ (بندهای ۲۳ و ۲۶ ِ سندِ هسته): پکیجی که فروخته شده یا خریدی برایش ثبت شده حذف نمی‌شود. کلیدِ
         * `plan_id` روی اشتراک و قصدِ خرید `SET NULL` است (`DeleteSemanticsTest::REGISTER`)؛ حذف یعنی اشتراکِ فعال
         * امکاناتِ پکیج را از دست می‌دهد (`PlanGate` به پیش‌فرض برمی‌گردد) و سابقه‌ی فروش نمی‌گوید چه پکیجی بود.
         * برای کنار گذاشتن «غیرفعال» هست (`toggle`)؛ پکیجِ هرگز استفاده‌نشده هنوز حذف می‌شود.
         */
        $used = Subscription::withoutGlobalScopes()->where('plan_id', $plan->id)->exists()
            || CheckoutIntent::withoutGlobalScopes()->where('plan_id', $plan->id)->exists();

        if ($used) {
            throw DomainException::conflict(
                'این پکیج اشتراک یا خریدِ ثبت‌شده دارد و حذفش سابقه‌ی آن‌ها را می‌برد. به‌جای حذف، غیرفعالش کنید.',
                'plan.in_use',
            );
        }

        Audit::log('plan.deleted', 'حذفِ پکیجِ استفاده‌نشده', $plan, ['snapshot' => $this->snapshot($plan)]);
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

        /*
         * «اهدای پلن» ابزارِ جدای سکوست، نه خرید یا تمدید (۱۲/۲): مبلغِ صفر و روشِ `manual`، با نامِ ادمین در ممیزی.
         * هیچ اشتراکِ آنلاینِ `pending` را فعال نمی‌کند — نگهبانِ `Subscription` فقط ساختِ ردیفِ تازه‌ی `manual` را
         * بی پرداخت می‌پذیرد.
         */
        Audit::log('subscription.granted', 'اهدای پلن توسطِ ادمینِ کل', $subscription, [
            'plan_id' => $plan->id,
            'months' => $months,
        ], $complex->id);

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
