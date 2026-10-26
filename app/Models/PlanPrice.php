<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قیمتِ یک مدت از یک پلن (مرحلهٔ ۱۳ · فاز ۶؛ ایرادات ۱۰، بندهای ۶ تا ۸ و ۱۶).
 *
 * تنها منبعِ مبلغِ خرید و تمدید: هر مسیرِ پرداخت مبلغ را همین‌جا، در همان لحظه، می‌خواند — نه از درخواست، نه از
 * state ِ رابط. مدتِ بی‌ردیف، غیرفعال یا با قیمتِ صفر خریدنی نیست؛ قیمت حدس زده نمی‌شود.
 *
 * @property int $plan_id
 * @property int $months
 * @property int $price
 * @property bool $is_active
 */
class PlanPrice extends Model
{
    protected $fillable = ['plan_id', 'months', 'price', 'is_active'];

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'price' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && $this->price > 0;
    }
}
