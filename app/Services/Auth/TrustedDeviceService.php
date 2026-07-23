<?php

namespace App\Services\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * صدور، بررسی و باطل‌کردنِ دستگاه‌های مورداعتماد.
 *
 * قرارداد کوکی: مقدارش «{id}:{توکن خام}» است. شناسه برای پیداکردن سریع ردیف،
 * و توکن خام برای مقایسه با هشِ ذخیره‌شده. کوکی امضاشده (httpOnly) است تا نه
 * جاوااسکریپت بتواند بخواندش و نه کاربر بتواند دستکاری‌اش کند.
 */
class TrustedDeviceService
{
    public const COOKIE = 'device_token';

    /** مدت اعتماد به دستگاه، به روز. */
    public function days(): int
    {
        return (int) config('auth.trusted_device_days', 10);
    }

    /**
     * ثبت این دستگاه به‌عنوان مورداعتماد و صف‌کردن کوکی‌اش.
     *
     * کوکی «صف» می‌شود؛ لاراول هنگام ارسال پاسخ آن را ضمیمه می‌کند. طول عمر
     * کوکی دقیقاً برابر انقضای ردیف است تا هر دو با هم تمام شوند.
     */
    public function remember(User $user, Request $request): void
    {
        $plain = Str::random(48);
        $expiresAt = now()->addDays($this->days());

        $device = TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'label' => Str::limit((string) $request->userAgent(), 180, ''),
            'last_used_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        Cookie::queue(Cookie::make(
            self::COOKIE,
            $device->id.':'.$plain,
            $this->days() * 24 * 60,   // دقیقه
            null, null, null, true, false, 'lax',
        ));
    }

    /**
     * دستگاهِ مورداعتمادِ معتبر از روی کوکی، یا null.
     *
     * علاوه بر مطابقت توکن، انقضا و فعال‌بودن کاربر هم بررسی می‌شود؛ یک دستگاهِ
     * منقضی یا کاربرِ غیرفعال نباید احراز را دور بزند.
     */
    public function resolve(Request $request): ?TrustedDevice
    {
        $raw = $request->cookie(self::COOKIE);
        if (! is_string($raw) || ! str_contains($raw, ':')) {
            return null;
        }

        [$id, $plain] = explode(':', $raw, 2);

        $device = TrustedDevice::active()->with('user')->find($id);

        if (! $device
            || ! hash_equals($device->token_hash, hash('sha256', $plain))
            || ! $device->user
            || ! $device->user->is_active) {
            return null;
        }

        return $device;
    }

    /** آیا این دستگاه برای همین کاربر مورداعتماد است؟ (برای ردکردن مرحله‌ی دوم هنگام ورود) */
    public function isTrustedFor(User $user, Request $request): bool
    {
        $device = $this->resolve($request);

        return $device !== null && $device->user_id === $user->id;
    }

    public function touch(TrustedDevice $device): void
    {
        $device->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /** باطل‌کردن دستگاهِ فعلی (هنگام خروج از حساب). */
    public function forget(Request $request): void
    {
        $raw = $request->cookie(self::COOKIE);
        if (is_string($raw) && str_contains($raw, ':')) {
            [$id] = explode(':', $raw, 2);
            TrustedDevice::whereKey($id)->delete();
        }

        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
