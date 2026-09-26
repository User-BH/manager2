<?php

namespace App\Services\Auth;

use App\Models\LoginEvent;
use App\Models\User;
use App\Notifications\NewDeviceLoginNotification;
use App\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * تاریخچه‌ی ورود و هشدارِ «ورود از دستگاهِ تازه» (مرحلهٔ ۵ · فاز ۱۰).
 *
 * ─── دستگاه را چطور می‌شناسیم ───────────────────────────────────────────────
 * ⚠️ نه با رشته‌ی مرورگر: با هر به‌روزرسانیِ Chrome عوض می‌شود و هر چهار هفته
 * یک هشدارِ دروغ می‌ساخت — و هشداری که همیشه دروغ است را کسی نمی‌خواند.
 *
 * هر مرورگر هنگامِ ورود یک کوکیِ تصادفیِ بلندمدت (`device_id`) می‌گیرد که
 * **هیچ اعتباری نمی‌دهد** — فقط نشانه است. هشِ آن در تاریخچه می‌ماند؛ ورودی
 * که نشانه‌اش در تاریخچه‌ی همین کاربر نیست، «دستگاهِ تازه» است.
 *
 * هشدار فقط در زنگوله است: ایمیل در این پروژه کنار گذاشته شده و پیامک فقط
 * برای کدِ یک‌بارمصرف است.
 */
class LoginRecorder
{
    public const DEVICE_COOKIE = 'device_id';

    /** رویدادهایی که «ورودِ موفق» شمرده می‌شوند. */
    private const SUCCESSES = [LoginEvent::SUCCESS, LoginEvent::TRUSTED_DEVICE, LoginEvent::PASSWORD_RESET];

    public function success(User $user, Request $request, string $event = LoginEvent::SUCCESS): void
    {
        $hash = $this->deviceHash($request);

        $history = LoginEvent::where('user_id', $user->id)->whereIn('event', self::SUCCESSES);
        $hadLogins = (clone $history)->exists();
        $knownDevice = (clone $history)->where('device_hash', $hash)->exists();

        $this->write($user, $request, $event, $hash);

        // اولین ورودِ عمر «تازه» نیست؛ دستگاهِ مورداعتماد هم تعریفاً آشناست
        if ($hadLogins && ! $knownDevice && $event !== LoginEvent::TRUSTED_DEVICE) {
            $user->notify(new NewDeviceLoginNotification(
                DeviceLabel::from($request->userAgent()),
                $request->ip(),
            ));
        }
    }

    public function failure(User $user, Request $request, string $event): void
    {
        $this->write($user, $request, $event, $this->deviceHash($request, issue: false));
    }

    private function write(User $user, Request $request, string $event, ?string $hash): void
    {
        LoginEvent::create([
            'user_id' => $user->id,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'device_hash' => $hash,
        ]);
    }

    /**
     * هشِ نشانه‌ی این مرورگر؛ اگر ندارد (و `issue`)، همین حالا صادر می‌شود.
     */
    private function deviceHash(Request $request, bool $issue = true): ?string
    {
        $token = $request->cookie(self::DEVICE_COOKIE);

        if (! is_string($token) || strlen($token) !== 40) {
            if (! $issue) {
                return null;
            }

            $token = Str::random(40);
            // ۵ سال؛ httpOnly، و Secure و SameSite از پیکربندیِ نشست
            Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $token, 60 * 24 * 365 * 5));
        }

        return hash('sha256', $token);
    }
}
