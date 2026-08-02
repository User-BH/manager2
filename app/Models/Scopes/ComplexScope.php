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
        $context = app(TenantContext::class);

        /*
         * کاربری که به هیچ مجتمعی وصل نیست باید **هیچ ردیفی** نبیند (R21).
         *
         * پیش از این چنین کاربری همان مسیرِ «شناسه تهی است» را می‌رفت که
         * معنایش «فیلتر نگذار» بود — یعنی دقیقاً برعکسِ چیزی که لازم است.
         * بی‌خطر مانده بود چون ثبت‌نام کاربر را غیرفعال می‌ساخت و این حالت
         * هرگز وارد نمی‌شد؛ «حالتِ اولیه»ی R21 همان در را باز می‌کند.
         */
        if ($context->deniesAll()) {
            $builder->whereRaw('0 = 1');

            return;
        }

        $complexId = $context->get();

        if ($complexId !== null) {
            $builder->where($model->getTable().'.complex_id', $complexId);
        }
    }
}
