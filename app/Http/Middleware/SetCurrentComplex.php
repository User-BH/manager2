<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\ComplexMembership;
use App\Models\User;
use App\Support\ComplexResolver;
use App\Support\SessionContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    /** مجتمعِ جاری که همین‌جا خوانده شد؛ `EnsureComplexActive` دوباره نمی‌خواند. */
    public const COMPLEX = 'sakena.current_complex';

    /** زمینه‌ی همین درخواست برای کلاینت: «مجتمع:نقش» — رابط اگر جای دیگری بود از نو بار می‌شود (۱۲/۱) */
    public const CONTEXT_HEADER = 'X-Sakena-Context';

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // ادمینِ کل عضویت ندارد؛ مجتمعِ کاری‌اش انتخابِ نشستِ خودش در پنلِ سیستم است
        if ($user instanceof User && $user->isSuperAdmin()) {
            if ($complexId = ComplexResolver::idFor($user)) {
                app(TenantContext::class)->set($complexId);
            }

            return $next($request);
        }

        if ($user instanceof User && $user->complex_id !== null) {
            return $this->member($request, $next, $user);
        }

        if ($user instanceof User) {
            SessionContext::pin($user, null);
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

    /**
     * 🔴 زنجیره‌ی مجوز (مرحلهٔ ۱۰ · فاز ۳؛ از ۱۲/۱ با زمینه‌ی نشست):
     *
     *   حساب ← فعال ← **مجتمعِ این نشست** ← عضویتِ فعال در همان ← **نقشِ همان عضویت** ← policy
     *
     * - پیش‌فرضِ حساب (`complex_id`، `role`) باید خودش عضویتِ فعالِ هم‌خوان داشته باشد؛ اگر نه (پایان‌یافته پشتِ سرِ
     *   حساب، دست‌کاریِ مستقیمِ پایگاه‌داده) **هر** درخواست رد می‌شود — نه بازگشت به جای دیگر. خروج آزاد است.
     * - مجتمعِ سنجاق‌شده‌ی نشست (`SessionContext`) فقط تا وقتی عضویتِ فعال دارد؛ وگرنه نشست به پیش‌فرض برمی‌گردد
     *   (همان که دفترِ عضویت پس از پایان یا تعلیق می‌گذارد). نشستِ بی‌سنجاق به پیش‌فرض سنجاق می‌شود.
     * - نقش از عضویت است، نه از حساب: مدیرِ الف در نشستی که در «ب» (مالک) است مدیر نیست.
     *
     * هر دو نامزد (سنجاق و پیش‌فرض) با **یک** کوئری خوانده می‌شوند — همان یک کوئریِ مجتمعِ جاری که
     * `EnsureComplexActive` همیشه می‌زد؛ هزینه‌ی هر درخواست بیشتر نشد.
     */
    private function member(Request $request, Closure $next, User $user)
    {
        $default = (int) $user->complex_id;
        $pinned = SessionContext::pinned($user);

        $rows = Complex::withoutGlobalScopes()
            ->join('complex_memberships as cm', function ($join) use ($user): void {
                $join->on('cm.complex_id', '=', 'complexes.id')
                    ->where('cm.user_id', $user->id)
                    ->where('cm.status', ComplexMembership::ACTIVE);
            })
            ->whereIn('complexes.id', array_values(array_unique(array_filter([$pinned, $default]))))
            ->get(['complexes.*', 'cm.role as membership_role'])
            ->keyBy('id');

        $base = $rows->get($default);
        if ($base === null || $base->getAttribute('membership_role') !== $user->role->value) {
            app(TenantContext::class)->denyAll();

            if (! $request->routeIs('logout', '*.logout')) {
                Log::channel('alerts')->warning('[membership] درخواست بی عضویتِ فعالِ هم‌خوان رد شد', [
                    'user_id' => $user->id, 'complex_id' => $default, 'role' => $user->role->value,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'عضویتِ فعالی در این مجتمع ندارید.',
                    'code' => 'membership.inactive',
                ], 403);
            }

            return $next($request);
        }

        $complex = $pinned !== null && $rows->has($pinned) ? $rows->get($pinned) : $base;
        $role = UserRole::from((string) $complex->getAttribute('membership_role'));
        $complex->offsetUnset('membership_role');
        $complex->syncOriginal();

        if ($pinned !== $complex->id) {
            SessionContext::pin($user, $complex->id);
        }

        // زمینه‌ی همین درخواست روی همان نمونه‌ی حساب — نه نوشته در پایگاه‌داده (پیش‌فرض فقط کارِ دفترِ عضویت است)
        $user->forceFill(['complex_id' => $complex->id, 'role' => $role]);
        $user->syncOriginalAttributes(['complex_id', 'role']);
        $user->setRelation('complex', $complex);

        $request->attributes->set(self::COMPLEX, $complex);
        app(TenantContext::class)->set($complex->id);

        $response = $next($request);
        $response->headers->set(self::CONTEXT_HEADER, $complex->id.':'.$role->value);

        return $response;
    }
}
