<?php

namespace App\Models\Concerns;

use App\Models\Complex;
use App\Models\Scopes\ComplexScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant isolation. Any model using this trait is automatically:
 *  - filtered to the authenticated user's complex (unless super-admin), and
 *  - stamped with that complex_id on creation.
 * This makes cross-complex data leakage a query-level impossibility rather
 * than relying on every controller to remember to filter.
 */
trait BelongsToComplex
{
    public static function bootBelongsToComplex(): void
    {
        static::addGlobalScope(new ComplexScope);

        static::creating(function ($model) {
            if (empty($model->complex_id) && ($complexId = app(\App\Support\TenantContext::class)->get())) {
                $model->complex_id = $complexId;
            }
        });
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }
}
