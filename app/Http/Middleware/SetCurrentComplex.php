<?php

namespace App\Http\Middleware;

use App\Models\User;
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
        $user = $request->user();

        if ($complexId = ComplexResolver::idFor($user)) {
            app(TenantContext::class)->set($complexId);

            return $next($request);
        }

        /*
         * کاربرِ واردشده‌ای که به هیچ مجتمعی وصل نیست («حالتِ اولیه»ی R21)
         * باید صریحاً از همه‌ی داده محروم شود.
         *
         * ادمینِ کل استثناست: نبودِ مجتمع برای او یعنی «همه‌ی مجتمع‌ها».
         * درخواستِ بدونِ کاربر (کنسول، صف) هم دست‌نخورده می‌ماند، وگرنه
         * Jobها هیچ داده‌ای نمی‌دیدند.
         */
        if ($user instanceof User && ! $user->isSuperAdmin()) {
            app(TenantContext::class)->denyAll();
        }

        return $next($request);
    }
}
