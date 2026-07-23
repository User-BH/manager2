<?php

namespace App\Models\Concerns;

use App\Models\Complex;
use App\Models\Scopes\ComplexScope;
use App\Support\ComplexResolver;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جداسازی چند-مستأجری. هر مدلی که این trait را داشته باشد:
 *  - در کوئری‌ها به مجتمع کاربر واردشده محدود می‌شود (مگر ادمین کل)،
 *  - هنگام ساخت، complex_id همان مجتمع را می‌گیرد،
 *  - و از راه route-model binding هم فقط داخل همان مجتمع پیدا می‌شود.
 *
 * بند سوم را دست‌کم نگیرید: پیش از این فقط دو بند اول برقرار بود. مسیرهایی
 * مثل `/api/units/{id}` مدل را پیش از مقداردهی TenantContext می‌خواندند، پس
 * اسکوپ سراسری هیچ فیلتری اعمال نمی‌کرد و مدیرِ یک مجتمع می‌توانست واحد یا
 * اطلاعیه یا هزینه‌ی مجتمع دیگری را ویرایش و حذف کند.
 */
trait BelongsToComplex
{
    public static function bootBelongsToComplex(): void
    {
        static::addGlobalScope(new ComplexScope);

        static::creating(function ($model) {
            if (empty($model->complex_id) && ($complexId = ComplexResolver::activeId())) {
                $model->complex_id = $complexId;
            }
        });
    }

    /**
     * خواندن مدل از روی پارامتر مسیر، محدود به مجتمع فعال.
     *
     * اتکا به اسکوپ سراسری اینجا کافی نیست، چون در ترتیب میدل‌ورها ممکن است
     * SubstituteBindings پیش از میدل‌ورِ مجتمع اجرا شود و در آن لحظه
     * TenantContext هنوز خالی باشد. `ComplexResolver::activeId()` در آن حالت
     * شناسه را مستقیم از کاربر واردشده می‌گیرد، پس این تضمین به ترتیب اجرای
     * میدل‌ورها وابسته نمی‌ماند.
     *
     * نتیجه‌ی نیافتن، ۴۰۴ است نه ۴۰۳ — عمدی است تا وجود یا نبودِ یک شناسه در
     * مجتمع دیگر هم لو نرود.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->resolveRouteBindingQuery($this, $value, $field);

        if ($complexId = ComplexResolver::activeId()) {
            $query->where($this->getTable().'.complex_id', $complexId);
        }

        return $query->first();
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }
}
