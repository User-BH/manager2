<?php

namespace App\Http\Middleware;

use App\Listeners\StartAuthenticatedSession;
use App\Models\LoginEvent;
use App\Services\Auth\TrustedDeviceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ورود خودکارِ دستگاهِ مورداعتماد.
 *
 * اگر کاربر نشست فعالی ندارد ولی کوکیِ دستگاه مورداعتمادِ معتبری دارد (تا ۱۰
 * روز پس از «مرا به خاطر بسپار»)، همین‌جا واردش می‌کنیم؛ درست مثل remember
 * لاراول، ولی با مدت و شرایط خودمان. این کار پیش از رسیدن به کنترلر انجام
 * می‌شود تا بقیه‌ی برنامه کاربر را واردشده ببیند.
 */
class AuthenticateTrustedDevice
{
    public function __construct(protected TrustedDeviceService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $device = $this->devices->resolve($request);

            if ($device) {
                $request->attributes->set(StartAuthenticatedSession::EVENT_ATTRIBUTE, LoginEvent::TRUSTED_DEVICE);
                /*
                 * ⚠️ شناسه‌ی نشست همین‌جا عوض و قبلی نابود می‌شود: `login()`ِ
                 * نگهبانِ لاراول خودش `regenerate(true)` می‌کند. پس کاشتنِ
                 * شناسه‌ی مهمان (session fixation) از این راه کار نمی‌کند —
                 * خرابکاریِ فاز ۱۰ همین را نشان داد و `SessionLifecycleTest`
                 * قفلش کرده است.
                 */
                Auth::loginUsingId($device->user_id);
                $this->devices->touch($device);
            }
        }

        return $next($request);
    }
}
