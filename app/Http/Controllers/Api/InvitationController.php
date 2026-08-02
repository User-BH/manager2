<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\ComplexInvitation;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * دعوت‌های دریافتیِ کاربر (R21).
 *
 * این تنها نوشتنی است که حسابِ «حالتِ اولیه» اجازه دارد انجام دهد، چون همان
 * کاری است که او را از آن حالت بیرون می‌برد.
 */
class InvitationController extends Controller
{
    public function index(): JsonResponse
    {
        /*
         * صریحاً به کاربرِ واردشده محدود می‌شود.
         *
         * این مدل عمداً دامنه‌ی مستأجر ندارد (گیرنده هنوز به مجتمعی وصل نیست
         * و دامنه برایش «هیچ‌چیز» است)، پس محدودسازی اینجا **اجباری** است نه
         * اختیاری.
         */
        $invitations = ComplexInvitation::query()
            ->where('user_id', Auth::id())
            ->pending()
            ->with(['complex:id,name,address', 'unit:id,unit_number', 'inviter:id,name'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $invitations->map(fn (ComplexInvitation $i) => [
                'id' => $i->id,
                'complexName' => $i->complex?->name,
                'complexAddress' => $i->complex?->address,
                'unitLabel' => $i->unit ? 'واحد '.$i->unit->unit_number : null,
                'roleLabel' => $i->role->label(),
                'invitedBy' => $i->inviter?->name,
                'createdAt' => $i->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function accept(ComplexInvitation $invitation): JsonResponse
    {
        $this->authorizeOwnership($invitation);

        $user = Auth::user();

        DB::transaction(function () use ($invitation, $user): void {
            /*
             * وضعیت **داخلِ تراکنش** دوباره خوانده می‌شود.
             *
             * بدونِ این، دو کلیک هم‌زمان روی «پذیرفتن» هر دو `pending`
             * می‌دیدند و هر دو کاربر را وصل می‌کردند — الگوی «بررسی کن، بعد
             * عمل کن» که در R15 هم دیده شد.
             */
            $fresh = ComplexInvitation::lockForUpdate()->find($invitation->id);

            if (! $fresh || $fresh->status !== ComplexInvitation::PENDING) {
                throw DomainException::invalid('این دعوت دیگر معتبر نیست.', 'invitation.not_pending');
            }

            $user->forceFill([
                'complex_id' => $fresh->complex_id,
                'role' => $fresh->role,
            ])->save();

            if ($fresh->unit_id) {
                $user->units()->syncWithoutDetaching([
                    $fresh->unit_id => [
                        'relation' => $fresh->role->value,
                        'complex_id' => $fresh->complex_id,
                    ],
                ]);
            }

            $fresh->update([
                'status' => ComplexInvitation::ACCEPTED,
                'responded_at' => now(),
            ]);

            /*
             * بقیه‌ی دعوت‌های در انتظار باطل می‌شوند: کاربر حالا به یک مجتمع
             * وصل است و پذیرفتنِ دعوتِ دوم یعنی جابه‌جا شدن بی‌سروصدا.
             */
            ComplexInvitation::where('user_id', $user->id)
                ->pending()
                ->update(['status' => ComplexInvitation::DECLINED, 'responded_at' => now()]);
        }, attempts: 3);

        Audit::log('invitation.accepted', 'پذیرش دعوت به مجتمع', $invitation, [
            'complex_id' => $invitation->complex_id,
        ]);

        return response()->json([
            'message' => 'به مجتمع پیوستید. اکنون به داشبورد دسترسی دارید.',
        ]);
    }

    public function decline(ComplexInvitation $invitation): JsonResponse
    {
        $this->authorizeOwnership($invitation);

        $invitation->update([
            'status' => ComplexInvitation::DECLINED,
            'responded_at' => now(),
        ]);

        return response()->json(['message' => 'دعوت رد شد.']);
    }

    /**
     * فقط گیرنده‌ی دعوت می‌تواند به آن پاسخ بدهد.
     *
     * ۴۰۴ و نه ۴۰۳: وجود یا نبودِ یک دعوت هم اطلاعات است و کسی که گیرنده‌اش
     * نیست نباید بتواند تفاوتشان را بفهمد.
     */
    private function authorizeOwnership(ComplexInvitation $invitation): void
    {
        abort_unless($invitation->user_id === Auth::id(), 404);
    }
}
