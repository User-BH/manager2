<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AdminTwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * سوپر ادمینِ Production بی TOTP فقط تا راه‌اندازیِ آن (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵؛ `AdminTwoFactor` قاعده‌ی ③).
 *
 * چنین نشستی فقط با کدِ پیامکی ساخته شده؛ تا عاملِ دوم فعال نشود هیچ مسیرِ مدیریتی، داده‌ای یا عملیاتی باز نیست — فقط
 * صفحه‌ی «اطلاعاتِ کاربری»، راه‌اندازیِ TOTP، تأییدِ رمز، `me` و خروج. پیش‌فرض **بستن** است: هر مسیرِ تازه‌ای
 * که فردا اضافه شود خودبه‌خود بسته است، مگر صریحاً در فهرستِ زیر باشد.
 *
 * چون روی هر درخواست سنجیده می‌شود، تازه‌کردنِ نشست، تبِ دیگر یا مسیرِ جایگزین دورش نمی‌زند.
 */
class EnsureAdminTwoFactor
{
    /** @var list<string> */
    private const OPEN = [
        'me', 'csrf-token', 'logout', 'presence.offline', 'client-errors.store', 'web-vitals.store', 'features.index',
        // صفحه‌ی «اطلاعاتِ کاربری» و کارتِ امنیت — نه ویرایشِ حساب، رمز یا شماره
        'profile.show', 'profile.sessions', 'profile.login-history', 'profile.sessions.destroy', 'profile.sessions.end-others',
        'profile.confirm-password', 'profile.confirm-password.status',
    ];

    public const CODE = 'two_factor.setup_required';

    public function __construct(private readonly AdminTwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->twoFactor->setupRequired($user) && ! $this->open($request)) {
            return response()->json([
                'message' => 'پیش از هر کار، ورودِ دومرحله‌ای با برنامه‌ی Authenticator را از «اطلاعاتِ کاربری» فعال کنید.',
                'code' => self::CODE,
            ], 403);
        }

        return $next($request);
    }

    private function open(Request $request): bool
    {
        // نامِ مسیرهای `routes/api.php` پیشوندِ `api.` دارد
        $name = substr((string) $request->route()?->getName(), strlen('api.'));

        return str_starts_with((string) $request->route()?->getName(), 'api.')
            && (in_array($name, self::OPEN, true) || str_starts_with($name, 'profile.two-factor.'));
    }
}
