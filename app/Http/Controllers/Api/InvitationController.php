<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationPresenter;
use App\Models\ComplexInvitation;
use App\Models\User;
use App\Services\Account\Membership;
use App\Services\Auth\OtpService;
use App\Support\Audit;
use App\Support\SystemSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * دعوت‌های دریافتیِ صاحبِ شماره (R21؛ دعوتِ امن از مرحلهٔ ۹ · فاز ۶).
 *
 * این تنها نوشتنی است که حسابِ «حالتِ اولیه» اجازه دارد انجام دهد، چون همان
 * کاری است که او را از آن حالت بیرون می‌برد.
 *
 * ─── دعوت به‌تنهایی دسترسی نمی‌دهد ──────────────────────────────────────
 * پذیرش سه چیز می‌خواهد: حسابِ واردشده با **همان شماره**، **کدِ OTP ِ تازه** به
 * همان شماره (تصمیمِ کارفرما؛ دستگاهِ مورداعتماد این گام را دور نمی‌زند)، و
 * دعوتِ زنده (در انتظار، منقضی‌نشده، باطل‌نشده). پیوند فقط پیش‌نمایش است.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly Membership $membership) {}

    public function index(): JsonResponse
    {
        /*
         * صریحاً به شماره‌ی کاربرِ واردشده محدود می‌شود. این مدل عمداً دامنه‌ی
         * مستأجر ندارد (گیرنده هنوز به مجتمعی وصل نیست)، پس این قید **اجباری**
         * است نه اختیاری.
         */
        $invitations = $this->mine()
            ->with([
                'complex:id,name,address',
                'unit' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'unit_number'),
                'inviter' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
            ])
            ->latest()
            ->get();

        return response()->json([
            'data' => $invitations->map(fn (ComplexInvitation $i) => InvitationPresenter::forRecipient($i))->values(),
        ]);
    }

    /**
     * پیش‌نمایشِ پیوندِ دعوت — برای مهمان هم.
     *
     * ۴۰۴ ِ یکسان برای نامعتبر، مصرف‌شده، باطل و منقضی: پاسخ نمی‌گوید کدام.
     */
    public function preview(Request $request): JsonResponse
    {
        // ⚠️ `token[]=…` پیش از این با «Array to string» ۵۰۰ می‌گرفت (مرحلهٔ ۱۰ · فاز ۵)
        $token = $request->input('token');
        $invitation = is_string($token) ? ComplexInvitation::findLiveByToken($token) : null;

        abort_if($invitation === null, 404);

        $invitation->load([
            'complex:id,name',
            'unit' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'unit_number'),
        ]);

        return response()->json(['invitation' => InvitationPresenter::forLinkHolder($invitation)]);
    }

    /** کدِ پذیرش به شماره‌ی خودِ کاربر — همان سرویس و سقفِ نرخِ OTP ِ ورود. */
    public function requestCode(ComplexInvitation $invitation, OtpService $otp): JsonResponse
    {
        $this->authorizeRecipient($invitation);

        if ($this->otpDisabled()) {
            return response()->json(['otpRequired' => false]);
        }

        $phone = $this->user()->phone;
        $result = $otp->request($phone);

        if (! $result['ok']) {
            throw ValidationException::withMessages(['code' => $result['error'] ?? 'ارسال کد ناموفق بود.']);
        }

        return response()->json([
            'otpRequired' => true,
            'message' => 'کدِ تأیید به شماره‌ی '.$phone.' فرستاده شد.',
            'devCode' => $result['dev_code'],
        ]);
    }

    public function accept(Request $request, ComplexInvitation $invitation, OtpService $otp): JsonResponse
    {
        $this->authorizeRecipient($invitation);

        $user = $this->user();

        if (! $this->otpDisabled()) {
            $code = $request->input('code');
            $code = is_string($code) ? $code : '';

            if (! preg_match('/^\d{6}$/', $code) || ! $otp->verify($user->phone, $code)) {
                throw ValidationException::withMessages(['code' => 'کدِ تأیید نادرست یا منقضی است.']);
            }
        }

        $this->membership->accept($invitation, $user);

        Audit::log('invitation.accepted', 'پذیرش دعوت به مجتمع', $invitation, [
            'complex_id' => $invitation->complex_id,
        ]);

        return response()->json([
            'message' => 'به مجتمع پیوستید. اکنون به داشبورد دسترسی دارید.',
        ]);
    }

    public function decline(ComplexInvitation $invitation): JsonResponse
    {
        $this->authorizeRecipient($invitation);

        $this->membership->decline($invitation, $this->user());

        return response()->json(['message' => 'دعوت رد شد.']);
    }

    /** @return Builder<ComplexInvitation> */
    private function mine(): Builder
    {
        return ComplexInvitation::query()
            ->invites()
            ->live()
            ->where('phone', $this->user()->phone);
    }

    /**
     * فقط صاحبِ شماره، و فقط دعوتِ زنده.
     *
     * ۴۰۴ و نه ۴۰۳: وجود یا نبودِ یک دعوت هم اطلاعات است و کسی که گیرنده‌اش
     * نیست نباید بتواند تفاوتشان را بفهمد.
     */
    private function authorizeRecipient(ComplexInvitation $invitation): void
    {
        abort_unless($this->mine()->whereKey($invitation->id)->exists(), 404);
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    /** همان تنظیمِ ادمینِ کل که مرحله‌ی دومِ ورود را برمی‌دارد (`AuthController::login`). */
    private function otpDisabled(): bool
    {
        return (bool) SystemSettings::get('otp_disabled', false);
    }
}
