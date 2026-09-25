<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SeoController;
use App\Services\Features\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حالتِ تعمیر از پنلِ سوپرادمین (مرحلهٔ ۵ · فاز ۹ · بندِ ۷۹).
 *
 * ─── چرا `php artisan down` کافی نبود ──────────────────────────────────────
 * آن مالِ اسکریپتِ استقرار است: از پنل روشن نمی‌شود، و وقتی روشن است خودِ
 * سوپرادمین هم جز با آدرسِ رمزدارِ جدا وارد نمی‌شود — یعنی کسی که حالت را
 * روشن کرده نمی‌تواند از همان پنل خاموشش کند.
 *
 * ─── سه رفتارِ خواسته‌شده ───────────────────────────────────────────────────
 * ① کاربرِ معمولی پیامِ درست می‌بیند: همان صفحه‌ی ۵۰۳ِ فارسیِ استقرار.
 * ② سوپرادمین وارد می‌شود و همه‌چیز برایش کار می‌کند.
 * ③ API رفتارِ مشخص دارد: ۵۰۳ با `code: maintenance` و `Retry-After`، تا
 *   فرانت بفهمد این «خرابی» نیست و صفحه را دوباره بار کند.
 *
 * ─── 🔴 آنچه عمداً باز می‌ماند ─────────────────────────────────────────────
 * بازگشت از بانک **هرگز** بسته نمی‌شود: پولِ ساکن همان لحظه کسر شده و اگر
 * تأییدش نرسد، تراکنش معلق می‌ماند. ورود هم باز است، وگرنه سوپرادمینی که
 * نشستش تمام شده دیگر راهی برای خاموش‌کردنِ حالت نداشت.
 */
class EnsureNotInMaintenance
{
    /**
     * مسیرهای همیشه‌باز، با نامِ بی‌پیشوندِ `api.`.
     *
     * ⚠️ محتوای خواندنیِ صفحه‌ی خانه (آگهی، تنظیماتِ سایت، موضوع‌های
     * پشتیبانی) هم باز است: صفحه‌ی عمومی در حالتِ تعمیر باز می‌ماند و بدونِ
     * این‌ها نیمه‌خراب دیده می‌شد.
     *
     * @var list<string>
     */
    public const OPEN_ROUTES = [
        'login', 'login.verify', 'login.resend', 'logout',
        'password.forgot', 'password.forgot.verify', 'password.reset',
        'csrf-token', 'features.index',
        'payments.callback', 'subscription.callback',
        'health', 'offline', 'client-errors.store', 'web-vitals.store',
        'ads.index', 'ads.image', 'site-settings', 'support.starters',
    ];

    public const MESSAGE = 'سامانه در حالِ به‌روزرسانی است و به‌زودی برمی‌گردد. لطفاً چند دقیقه‌ی دیگر دوباره سر بزنید.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! app(FeatureFlags::class)->enabled('maintenance_mode')
            || $request->user()?->isSuperAdmin()
            || $this->isOpen($request)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => self::MESSAGE,
                'code' => 'maintenance',
                'maintenance' => true,
            ], 503)->header('Retry-After', '600');
        }

        return response()->view('errors.503', [], 503)->header('Retry-After', '600');
    }

    private function isOpen(Request $request): bool
    {
        $name = (string) $request->route()?->getName();

        if (in_array(preg_replace('/^api\./', '', $name), self::OPEN_ROUTES, true)) {
            return true;
        }

        // صفحه‌های عمومیِ بازاریابی و خودِ صفحه‌ی ورود
        $path = $request->getPathInfo();

        return SeoController::isIndexable($path) || $path === '/auth' || str_starts_with($path, '/auth/');
    }
}
