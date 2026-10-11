<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreManagerRequest;
use App\Http\Resources\InvitationPresenter;
use App\Models\User;
use App\Services\Account\ComplexMembers;
use App\Services\Account\Membership;
use App\Support\Audit;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ManagerController extends Controller
{
    public function index(): JsonResponse
    {
        $complex = $this->requireComplex();

        $managers = ComplexMembers::query($complex->id, [UserRole::ComplexAdmin])
            ->orderBy('users.name')
            ->get();

        return response()->json([
            'data' => $managers->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'phone' => $m->phone,
                'isActive' => (bool) $m->is_active,
                'isSelf' => $m->id === Auth::id(),
                'createdAt' => Jalali::date($m->created_at),
            ])->values(),
            'complexName' => $complex->name,
        ]);
    }

    public function __construct(private readonly Membership $membership) {}

    /**
     * «مدیر جدید» = دعوتِ امن با نقشِ مدیر (مرحلهٔ ۹ · فاز ۶، F5).
     *
     * پیش از این حساب با رمزی که همین مدیر انتخاب می‌کرد ساخته می‌شد؛ یعنی
     * او رمزِ حسابِ مدیرِ دوم را می‌دانست. حالا صاحبِ شماره خودش با OTP
     * ثبت‌نام می‌کند و با کدِ تازه می‌پذیرد. دادنِ نقشِ مدیر همچنان تأییدِ
     * دوباره‌ی رمز می‌خواهد (`reauth`).
     */
    public function store(StoreManagerRequest $request): JsonResponse
    {
        $complex = $this->requireComplex();

        [$invitation, $token] = $this->membership->invite(
            $complex,
            $request->validated()['phone'],
            UserRole::ComplexAdmin,
            null,
            $request->user(),
        );

        Audit::log('invitation.sent', 'ارسال دعوتِ مدیر', $invitation, [
            'phone' => $invitation->phone,
            'role' => UserRole::ComplexAdmin->value,
        ]);

        return response()->json([
            'invited' => true,
            'invitation' => InvitationPresenter::forSender($invitation),
            'link' => InvitationPresenter::link($token),
            'message' => 'دعوتِ مدیر ساخته شد. پیوند را برای صاحبِ این شماره بفرستید.',
        ], 201);
    }

    public function destroy(User $manager): JsonResponse
    {
        $this->guard($manager);

        $complexId = $this->requireComplex()->id;

        // مجتمع نباید بدون مدیر بماند، وگرنه دیگر کسی به تنظیماتش دسترسی ندارد.
        $count = ComplexMembers::query($complexId, [UserRole::ComplexAdmin])->count();

        if ($count <= 1) {
            return response()->json([
                'message' => 'حداقل یک مدیر باید برای مجتمع باقی بماند.',
            ], 422);
        }

        if ($manager->id === Auth::id()) {
            return response()->json([
                'message' => 'نمی‌توانید حساب خودتان را حذف کنید.',
            ], 422);
        }

        Audit::log('manager.removed', 'پایانِ مدیریتِ مجتمع', $manager, [
            'name' => $manager->name,
            'phone' => $manager->phone,
            'complex_id' => $complexId,
        ]);

        // F4: پایانِ عضویت، نه حذفِ حساب — سابقه‌ی کارهای مدیر (تأییدها، پیام‌ها) می‌ماند
        $this->membership->end($manager, Auth::user(), $complexId);

        return response()->json(['message' => 'دسترسیِ مدیریتیِ این کاربر پایان یافت. حسابش حفظ شد.']);
    }

    /** قاعده‌اش حالا در `UserPolicy::manageManager` است، نه اینجا. */
    private function guard(User $manager): void
    {
        $this->authorize('manageManager', $manager);
    }
}
