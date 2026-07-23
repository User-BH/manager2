<?php

namespace App\Http\Middleware;

use App\Support\ComplexResolver;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * شناسه‌ی مجتمع فعال را برای این درخواست ثبت می‌کند تا اسکوپ سراسری
 * `ComplexScope` هر مستأجر را جدا نگه دارد.
 *
 * این میدل‌ور در فهرست اولویت (bootstrap/app.php) صراحتاً پیش از
 * SubstituteBindings نشانده شده، وگرنه مدل از روی پارامتر مسیر پیش از
 * مقداردهی اینجا خوانده می‌شد و بدون فیلتر مجتمع برمی‌گشت.
 */
class SetCurrentComplex
{
    public function handle(Request $request, Closure $next)
    {
        if ($complexId = ComplexResolver::idFor($request->user())) {
            app(TenantContext::class)->set($complexId);
        }

        return $next($request);
    }
}
