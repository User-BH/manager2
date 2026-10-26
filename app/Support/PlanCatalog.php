<?php

namespace App\Support;

use App\Enums\ComplexType;
use App\Enums\PlanTier;
use App\Models\Plan;
use App\Models\PlanPrice;

/**
 * کارت‌های پلن برای «تنظیماتِ حساب» (مرحلهٔ ۱۳ · فاز ۶؛ ایرادات ۱۰، بندهای ۱۱ تا ۱۶).
 *
 * سه منبع، با مرزِ روشن:
 *
 *   ① **قابلیت‌های اجراشده** (`capabilities`) — از ستون‌هایی که کد واقعاً اعمال می‌کند (`PlanCapabilities`: سقفِ واحد،
 *      درگاهِ بانکی، خروجیِ Excel). این‌ها را سوپر ادمین با همان ستون‌ها عوض می‌کند و متنشان از کد ساخته می‌شود، پس
 *      دروغ نمی‌گویند.
 *   ② **امکاناتِ مشترک** (`BASE`) — ساخته‌شده و در دسترسِ **همه‌ی** پلن‌ها؛ یک بار بالای کارت‌ها، نه به‌عنوانِ مزیتِ یک
 *      کارت. هیچ قابلیتِ ساخته‌نشده‌ای این‌جا نیست (دستیار، تقویم و … در مرحله‌ی خودشان).
 *   ③ **متنِ کارت** (`features`، `description`) — نوشته‌ی سوپر ادمین (بندِ ۶).
 *
 * «تمامِ امکاناتِ سایت بدون محدودیت» (بندِ ۱۵) فقط وقتی که ① واقعاً بی‌سقف است — از داده محاسبه می‌شود، نه متن.
 */
final class PlanCatalog
{
    /** امکاناتِ ساخته‌شده‌ای که هیچ پلنی محدودشان نمی‌کند */
    public const BASE = [
        'صدورِ قبض و شارژِ دوره‌ای',
        'اطلاعیه و اعلان',
        'پیام‌رسان، گروهِ مجتمع و نظرسنجی',
        'درخواست‌ها و پیگیری',
        'کیف پول و تاریخچه‌ی پرداخت',
        'خروجیِ PDF ِ فاکتور و تسویه‌حساب',
    ];

    /** @return list<array<string, mixed>> */
    public static function cards(ComplexType $type, ?int $currentPlanId = null): array
    {
        return Plan::catalogFor($type)->map(fn (Plan $p) => self::card($p, $currentPlanId))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function card(Plan $plan, ?int $currentPlanId = null): array
    {
        $capabilities = self::capabilities($plan);

        return [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'tier' => $plan->tier?->value,
            'tierLabel' => $plan->tier?->label(),
            'complexType' => $plan->complex_type?->value,
            'capabilities' => $capabilities,
            // متنِ سوپر ادمین، بی تکرارِ آنچه ① گفته است
            'features' => array_values(array_diff($plan->features ?? [], $capabilities)),
            'includesEverything' => self::unlimited($plan),
            'prices' => $plan->prices
                ->filter(fn (PlanPrice $price) => $price->isPurchasable() && in_array($price->months, PlanTier::DURATIONS, true))
                ->map(fn (PlanPrice $price) => [
                    'months' => $price->months,
                    'price' => $price->price,
                    'priceLabel' => Jalali::money($price->price),
                ])->values()->all(),
            'isCurrent' => $currentPlanId !== null && $plan->id === $currentPlanId,
        ];
    }

    /** @return list<string> */
    public static function capabilities(Plan $plan): array
    {
        return array_values(array_filter([
            $plan->unit_limit === null ? 'واحدِ نامحدود' : 'تا '.Jalali::digits($plan->unit_limit).' واحد',
            $plan->real_gateway ? 'درگاهِ پرداختِ بانکی برای ساکنین' : null,
            $plan->excel_export ? 'خروجیِ Excel ِ قبض‌ها' : null,
        ]));
    }

    public static function unlimited(Plan $plan): bool
    {
        return $plan->unit_limit === null && $plan->real_gateway && $plan->excel_export;
    }
}
