<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ComplexScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $complexId = app(TenantContext::class)->get();

        if ($complexId !== null) {
            $builder->where($model->getTable().'.complex_id', $complexId);
        }
    }
}
