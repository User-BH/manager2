<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmPasswordRequest;
use App\Models\LoginEvent;
use App\Models\TrustedDevice;
use App\Services\Auth\LoginRecorder;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Auth\SessionRegistry;
use App\Services\Auth\TrustedDeviceService;
use App\Support\DeviceLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * امنیتِ حسابِ خودِ کاربر: نشست‌ها، دستگاه‌ها، تاریخچه‌ی ورود و تأییدِ
 * دوباره‌ی رمز (مرحلهٔ ۵ · فاز ۱۰).
 *
 * همه‌چیز دربارهٔ **خودِ** کاربرِ واردشده است؛ هیچ مسیری شناسه‌ی کاربرِ
 * دیگری نمی‌گیرد.
 */
class SecurityController extends Controller
{
    /** چند ردیفِ آخرِ تاریخچه‌ی ورود نشان داده می‌شود. */
    private const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly SessionRegistry $sessions,
        private readonly TrustedDeviceService $devices,
        private readonly PasswordConfirmation $confirmation,
        private readonly LoginRecorder $recorder,
    ) {}

    /** نشست‌های باز و دستگاه‌های مورداعتماد. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->devices->resolve($request);

        return response()->json([
            'supported' => $this->sessions->supported(),
            'sessions' => $this->sessions->forUser($user, $request->session()->getId()),
            'devices' => $user->trustedDevices()->active()->latest('last_used_at')->get()
                ->map(fn (TrustedDevice $device): array => [
                    'id' => $device->id,
                    'device' => DeviceLabel::from($device->label),
                    'lastUsedAt' => $device->last_used_at?->toIso8601String(),
                    'expiresAt' => $device->expires_at->toIso8601String(),
                    'current' => $current?->id === $device->id,
                ]),
            'absoluteLifetimeMinutes' => (int) config('session.absolute_lifetime'),
        ]);
    }

    /** پایانِ یک نشستِ دیگر. */
    public function destroySession(Request $request, string $session): JsonResponse
    {
        $ended = $this->sessions->end($request->user(), $session, $request->session()->getId());

        abort_unless($ended, 404);

        return response()->json(['message' => 'آن نشست بسته شد.']);
    }

    /** لغوِ اعتمادِ یک دستگاه. */
    public function destroyDevice(Request $request, int $device): JsonResponse
    {
        $deleted = $request->user()->trustedDevices()->whereKey($device)->delete();

        abort_unless($deleted > 0, 404);

        return response()->json(['message' => 'اعتماد به آن دستگاه لغو شد.']);
    }

    /**
     * «از همه‌ی دستگاه‌های دیگر خارج شو»: همه‌ی نشست‌های دیگر، و اعتمادِ همه‌ی
     * دستگاه‌ها جز همین یکی.
     *
     * ⚠️ هر دو لازم است: بستنِ نشست بدونِ لغوِ دستگاهِ مورداعتماد بی‌اثر
     * بود — کوکیِ دستگاه در درخواستِ بعدی دوباره واردش می‌کرد.
     */
    public function endOthers(Request $request): JsonResponse
    {
        $user = $request->user();

        $ended = $this->sessions->endOthers($user, $request->session()->getId());

        $keep = $this->devices->resolve($request)?->id;
        $user->trustedDevices()->when($keep, fn ($q) => $q->whereKeyNot($keep))->delete();

        return response()->json([
            'message' => $ended > 0
                ? "از {$ended} نشستِ دیگر خارج شدید."
                : 'نشستِ بازِ دیگری نبود؛ اعتمادِ دستگاه‌های دیگر هم لغو شد.',
            'ended' => $ended,
        ]);
    }

    /** تاریخچه‌ی ورود، تازه‌ترین اول. */
    public function history(Request $request): JsonResponse
    {
        $events = LoginEvent::where('user_id', $request->user()->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (LoginEvent $event): array => [
                'id' => $event->id,
                'event' => $event->event,
                'failed' => $event->isFailure(),
                'device' => DeviceLabel::from($event->user_agent),
                'ip' => $event->ip_address,
                'at' => $event->created_at->toIso8601String(),
            ]);

        return response()->json(['events' => $events]);
    }

    /**
     * آیا تأییدِ تازه‌ای هست؟ برای پیوندهای معمولیِ مرورگر (دانلودِ بکاپ) که
     * رابط پیش از رفتن به آن‌ها باید بداند پنجره‌ی رمز لازم است یا نه.
     */
    public function confirmationStatus(Request $request): JsonResponse
    {
        return response()->json(['confirmed' => $this->confirmation->isRecent($request)]);
    }

    /**
     * تأییدِ دوباره پیش از عملیاتِ حساس؛ پس از آن مسیرهای حساس تا `auth.password_timeout` ثانیه باز می‌مانند.
     *
     * مدیرِ سامانه با رمز؛ بقیه‌ی کاربران — که از ۲۰۲۶-۱۰-۳۰ رمز ندارند — با کدِ پیامکیِ شماره‌ی خودشان. شکلِ تأیید را
     * نقشِ حساب تعیین می‌کند، نه درخواست: کاربرِ عادی با فرستادنِ «رمز» و مدیر با فرستادنِ «کد» تأیید نمی‌گیرند.
     */
    public function confirmPassword(ConfirmPasswordRequest $request, OtpService $otp): JsonResponse
    {
        $request->validated();

        $user = $request->user();

        if ($user->usesPassword()) {
            if (! Hash::check((string) $request->input('password'), $user->password)) {
                $this->recorder->failure($user, $request, LoginEvent::FAILED_REAUTH);

                throw ValidationException::withMessages(['password' => 'رمز عبور نادرست است.']);
            }
        } elseif ($user->phone === null || ! $otp->verify($user->phone, (string) $request->input('code'))) {
            $this->recorder->failure($user, $request, LoginEvent::FAILED_REAUTH);

            throw ValidationException::withMessages(['code' => 'کد واردشده نادرست یا منقضی شده است.']);
        }

        $this->confirmation->confirm($request);

        return response()->json([
            'message' => 'تأیید شد.',
            'validForSeconds' => (int) config('auth.password_timeout'),
        ]);
    }

    /**
     * کدِ تأییدِ دوباره به شماره‌ی خودِ حساب — فقط کاربرانِ بی رمز؛ مدیرِ سامانه رمز می‌زند.
     */
    public function sendConfirmationCode(Request $request, OtpService $otp): JsonResponse
    {
        $user = $request->user();

        if ($user->usesPassword() || $user->phone === null) {
            throw ValidationException::withMessages(['code' => 'برای این حساب تأیید با رمز است.']);
        }

        $result = $otp->request($user->phone);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['code' => $result['error'] ?? 'ارسال کد ناموفق بود.']);
        }

        return response()->json([
            'message' => 'کد تأیید به شماره‌ی شما فرستاده شد.',
            'dev_code' => $result['dev_code'] ?? null,
        ]);
    }
}
