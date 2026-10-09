<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationPresenter;
use App\Models\ComplexInvitation;
use App\Services\Account\Membership;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * دعوت‌های فرستاده‌ی مجتمعِ فعال — دیدن و ابطال (مرحلهٔ ۹ · فاز ۶، F7).
 *
 * فقط «در انتظار»ها؛ منقضی‌ها هم با برچسب دیده می‌شوند تا مدیر بداند باید
 * دوباره بفرستد. پیوند دوباره نشان داده نمی‌شود (توکنِ خام جایی نیست)؛ برای
 * پیوندِ تازه همان فرمِ «ساکن جدید» یا «مدیر جدید» دعوتِ قبلی را باطل و تازه
 * می‌سازد.
 */
class SentInvitationController extends Controller
{
    public function __construct(private readonly Membership $membership) {}

    public function index(Request $request): JsonResponse
    {
        $complexId = $this->requireComplex()->id;

        $roles = $request->query('kind') === 'managers'
            ? [UserRole::ComplexAdmin->value]
            : [UserRole::Owner->value, UserRole::Tenant->value];

        $invitations = ComplexInvitation::query()
            ->where('complex_id', $complexId)
            ->invites()
            ->pending()
            ->whereIn('role', $roles)
            ->with(['unit:id,unit_number'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $invitations->map(fn (ComplexInvitation $i) => InvitationPresenter::forSender($i))->values(),
        ]);
    }

    public function destroy(ComplexInvitation $invitation): JsonResponse
    {
        /*
         * ۴۰۴ برای دعوتِ مجتمعِ دیگر — همان قاعده‌ی `JoinRequestController`:
         * وجودِ یک دعوت هم اطلاعات است.
         */
        abort_unless(
            $invitation->complex_id === $this->requireComplex()->id
                && $invitation->direction === ComplexInvitation::INVITE,
            404,
        );

        $this->membership->revoke($invitation);

        Audit::log('invitation.revoked', 'ابطالِ دعوت', $invitation, ['phone' => $invitation->phone]);

        return response()->json(['message' => 'دعوت باطل شد؛ پیوندش دیگر کار نمی‌کند.']);
    }
}
