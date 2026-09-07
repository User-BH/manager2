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
         */
        if ($user instanceof User) {
            if (! $user->isSuperAdmin()) {
                app(TenantContext::class)->denyAll();
            }

            return $next($request);
        }

        /*
         * 🔴 مهمان هم محروم می‌شود — شکافی که فاز ۲ پیدا کرد.
         *
         * ─── چه چیزی باز بود ───────────────────────────────────────────────
         * پیش از این، درخواستِ **بدونِ کاربر** به هیچ‌کدام از دو شاخه
         * نمی‌خورد و زمینه روی `null` می‌ماند. و `null` در `ComplexScope`
         * معنای خطرناکی دارد: **«فیلتر نگذار»**.
         *
         * ⚠️ یعنی هر مسیرِ عمومی‌ای که به یکی از چهارده مدلِ مستأجری برسد،
         * ردیفِ **همه‌ی مجتمع‌ها** را به مهمان می‌داد.
         *
         * امروز چنین مسیری وجود ندارد — سنجیده شد، نه فرض. ولی این یعنی
         * محافظت به «کسی چنین مسیری اضافه نکند» وابسته بود، نه به کد. اولین
         * مسیرِ عمومی‌ای که روزی به قبض یا اطلاعیه دست بزند، بی‌صدا نشت
         * می‌کرد.
         *
         * 🔴 تفاوتِ اینجا با کنسول و صف: آن‌ها اصلاً از میدل‌ورِ HTTP
         * نمی‌گذرند، پس این تغییر کارِ Jobها را خراب نمی‌کند.
         */
        app(TenantContext::class)->denyAll();

        return $next($request);
    }
}
