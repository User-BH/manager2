<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmPhoneChangeRequest;
use App\Http\Requests\StartPhoneChangeRequest;
use App\Models\User;
use App\Services\Account\PhoneChange;
use App\Services\Auth\SessionRegistry;
use App\Services\Auth\TrustedDeviceService;
use Illuminate\Http\JsonResponse;

/**
 * تغییرِ شماره‌ی حساب توسطِ **خودِ** کاربر (مرحلهٔ ۹ · تصمیمِ کارفرما).
 *
 * صاحب همیشه کاربرِ واردشده است؛ هیچ شناسه‌ی حسابی در درخواست پذیرفته نمی‌شود.
 * گامِ اول پشتِ تأییدِ دوباره‌ی رمز (`reauth`) و سقفِ نرخِ کدِ پیامکی است.
 */
class PhoneChangeController extends Controller
{
    public function __construct(
        private readonly PhoneChange $phoneChange,
        private readonly SessionRegistry $sessions,
        private readonly TrustedDeviceService $devices,
    ) {}

    public function start(StartPhoneChangeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->phoneChange->start($user, $request->validated()['phone']);

        return response()->json([
            // ⚠️ عمداً یکسان برای شماره‌ی آزاد و شماره‌ی دارای حساب (شمارشِ حساب)
            'message' => 'اگر این شماره برای حسابِ شما قابلِ استفاده باشد، کدِ تأیید به آن فرستاده شد.',
            'devCode' => $result['devCode'],
        ]);
    }

    public function confirm(ConfirmPhoneChangeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->phoneChange->confirm($user, $request->validated()['code']);

        /*
         * مثلِ تغییرِ رمز: نشست‌های دیگر بسته، اعتمادِ همه‌ی دستگاه‌ها (همین یکی هم)
         * لغو، و شناسه‌ی همین نشست عوض و نابود می‌شود. ورودِ بعدی با شماره‌ی تازه.
         */
        $this->devices->forget($request);
        $this->sessions->endOthers($user, $request->session()->getId());
        $request->session()->regenerate(true);

        return response()->json([
            'message' => 'شماره‌ی حساب تغییر کرد. دستگاه‌های دیگر خارج شدند.',
            'phone' => $user->fresh()?->phone,
        ]);
    }
}
