<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TwoFactorCodeRequest;
use App\Http\Requests\TwoFactorSetupRequest;
use App\Models\User;
use App\Services\Auth\AdminTwoFactor;
use App\Support\Jalali;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * راه‌اندازی، تعویض و کدهای بازیابیِ عاملِ دومِ سوپر ادمین (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵).
 *
 *   فعال‌سازی:    رمز (پنجره‌ی `reauth`) ← کلیدِ تازه و QR ← یک کدِ درست از برنامه ← ده کدِ بازیابی، یک بار
 *   تعویض:        رمز + کدِ فعلیِ برنامه ← کلیدِ تازه؛ کلیدِ فعلی تا تأییدِ تازه معتبر می‌ماند
 *   کدهای تازه:   رمز + کدِ فعلیِ برنامه ← ده کدِ تازه؛ همه‌ی قبلی‌ها باطل
 *
 * غیرفعال‌سازی مسیرِ وب ندارد: عاملِ دومِ سوپر ادمین الزامی است. بازنشانی فقط با فرمانِ سرور (`admin:two-factor
 * --reset`) — کسی که نشستِ دزدیده دارد به آن نمی‌رسد. کلید هرگز در پاسخی جز همان پاسخِ راه‌اندازی نیست و کدهای بازیابی
 * فقط همان لحظه‌ی ساخت دیده می‌شوند.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly AdminTwoFactor $twoFactor) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->admin($request);

        return response()->json([
            'enabled' => $this->twoFactor->enabled($user),
            'setupRequired' => $this->twoFactor->setupRequired($user),
            'confirmedAt' => $user->two_factor_confirmed_at ? Jalali::dateTime($user->two_factor_confirmed_at) : null,
            'recoveryCodesRemaining' => $this->twoFactor->enabled($user) ? $this->twoFactor->remainingRecoveryCodes($user) : 0,
        ]);
    }

    public function setup(TwoFactorSetupRequest $request): JsonResponse
    {
        $user = $this->admin($request);

        // تعویض با نشستِ دزدیده و رمزِ لورفته کافی نیست: کدِ فعلیِ برنامه هم لازم است
        if ($this->twoFactor->enabled($user)) {
            $this->requireCurrentCode($user, $request->validated()['code'] ?? null);
        }

        return response()->json($this->twoFactor->begin($user));
    }

    public function confirm(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $this->admin($request);
        $data = $request->validated();

        $codes = $this->twoFactor->confirm($user, $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'کد نادرست است یا مهلتِ راه‌اندازی گذشته؛ دوباره از اول شروع کنید.']);
        }

        return response()->json([
            'message' => 'ورودِ دومرحله‌ای فعال شد. کدهای بازیابی را همین حالا جایی امن نگه دارید؛ دیگر نشان داده نمی‌شوند.',
            'recoveryCodes' => $codes,
        ]);
    }

    public function regenerateRecoveryCodes(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $this->admin($request);

        if (! $this->twoFactor->enabled($user)) {
            throw DomainException::invalid('ورودِ دومرحله‌ای هنوز فعال نیست.', 'two_factor.not_enabled');
        }
        $this->requireCurrentCode($user, $request->validated()['code']);

        return response()->json([
            'message' => 'کدهای بازیابیِ تازه ساخته شد و همه‌ی کدهای قبلی باطل شدند.',
            'recoveryCodes' => $this->twoFactor->regenerateRecoveryCodes($user),
        ]);
    }

    /** عاملِ دوم فقط مالِ سوپر ادمین است — مرزِ اصلی `role:super_admin` روی مسیرهاست؛ این لایه‌ی دوم */
    private function admin(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            throw new AuthorizationException('ورودِ دومرحله‌ای با Authenticator ویژه‌ی سوپر ادمین است.');
        }

        return $user;
    }

    private function requireCurrentCode(User $user, ?string $code): void
    {
        if ($code === null || $code === '') {
            throw ValidationException::withMessages(['code' => 'کدِ فعلیِ برنامه را وارد کنید.']);
        }

        if (! $this->twoFactor->verifyCode($user, $code)) {
            throw ValidationException::withMessages(['code' => 'کدِ فعلیِ برنامه نادرست یا منقضی است.']);
        }
    }
}
