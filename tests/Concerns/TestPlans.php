<?php

namespace Tests\Concerns;

use App\Enums\PlanTier;
use App\Models\Plan;
use App\Models\PlanPrice;

/**
 * پلنِ آزمون به شکلِ ساختارِ ۱۳/۶ (سطح + قیمتِ هر مدت) — همان چیزی که پنلِ سوپر ادمین می‌سازد.
 *
 * آزمون‌های پیش از ۱۳/۶ پلن را با یک `price` و یک `months` می‌ساختند. این‌جا همان دو، ردیفِ `plan_prices` ِ همان مدت
 * می‌شوند و سطحِ نیامده اولین سطحی است که هنوز پلنِ فعالی از همان نوع ندارد — تا چند پلنِ یک آزمون همه کارت شوند.
 */
final class TestPlans
{
    /** @param  array<string, mixed>  $attributes */
    public static function create(array $attributes): Plan
    {
        $attributes['tier'] ??= self::freeTier($attributes);
        $plan = Plan::create($attributes);

        PlanPrice::create([
            'plan_id' => $plan->id,
            'months' => (int) ($attributes['months'] ?? 1),
            'price' => (int) ($attributes['price'] ?? 0),
            'is_active' => true,
        ]);

        return $plan->load('prices');
    }

    /** @param  array<string, mixed>  $attributes */
    private static function freeTier(array $attributes): string
    {
        $type = $attributes['complex_type'] ?? null;
        $type = $type instanceof \BackedEnum ? $type->value : $type;

        $used = Plan::where('is_active', true)
            ->when($type === null, fn ($q) => $q->whereNull('complex_type'), fn ($q) => $q->where('complex_type', $type))
            ->pluck('tier')->map(fn ($tier) => $tier instanceof PlanTier ? $tier->value : $tier)->all();

        foreach (PlanTier::values() as $tier) {
            if (! in_array($tier, $used, true)) {
                return $tier;
            }
        }

        return PlanTier::Advanced->value;
    }
}
