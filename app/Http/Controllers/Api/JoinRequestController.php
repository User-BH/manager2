<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountState;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\ComplexInvitation;
use App\Models\User;
use App\Support\Audit;
use App\Support\ComplexResolver;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * درخواستِ پیوستن از سمتِ واحد (R21b).
 *
 * جهتِ برعکسِ دعوت: ساکن شماره‌ی موبایلِ مدیرِ ساختمانش را وارد می‌کند،
 * سامانه همان‌جا تایید می‌کند که آن شماره واقعاً مدیرِ یک مجتمع است، نامِ
 * مجتمع را نشان می‌دهد، و با تاییدِ مدیر ساکن اضافه می‌شود.
 *
 * چرا لازم بود: تا اینجا تنها راهِ پیوستن این بود که **مدیر** اول اقدام کند.
 * ساکنی که مدیرش سراغش نمی‌آمد، کاری از دستش برنمی‌آمد.
 */
class JoinRequestController extends Controller
{
    /**
     * آیا این شماره متعلق به مدیرِ یک مجتمع است؟ (اعتبارسنجیِ لحظه‌ای)
     *
     * ─── چه چیزی لو می‌رود و چرا قابل قبول است ─────────────────────────────
     * پاسخ می‌گوید «این شماره مدیرِ فلان مجتمع است». این یعنی می‌شود شماره‌ها
     * را پیمود. سه محافظ داریم: نیاز به ورود، محدودیتِ نرخ، و اینکه فقط
     * حسابِ «حالتِ اولیه» می‌تواند صدایش بزند — یعنی کسی که هنوز عضوِ هیچ
     * مجتمعی نیست و چیزی برای دزدیدن ندارد.
     *
     * در مقابل، بدونِ نمایشِ نامِ مجتمع کاربر نمی‌داند درخواستش کجا می‌رود و
     * ممکن است به مجتمعِ اشتباهی بفرستد — که خودش نشتِ نام و شماره‌ی اوست.
     */
    public function lookup(Request $request): JsonResponse
    {
        $manager = $this->managerByPhone((string) $request->query('phone'));

        return response()->json([
            'found' => $manager !== null,
            'complexName' => $manager?->complex?->name,
        ]);
    }

    /** ارسالِ درخواست به مدیر. */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (AccountState::of($user) !== AccountState::Initial) {
            throw DomainException::invalid(
                'شما از قبل عضو یک مجتمع هستید.',
                'account.already_member',
            );
        }

        $manager = $this->managerByPhone((string) $request->input('phone'));

        if (! $manager || ! $manager->complex) {
            throw DomainException::invalid(
                'این شماره متعلق به هیچ مدیر مجتمعی نیست.',
                'join_request.manager_not_found',
            );
        }

        $invitation = ComplexInvitation::updateOrCreate(
            [
                'complex_id' => $manager->complex_id,
                'user_id' => $user->id,
                'status' => ComplexInvitation::PENDING,
            ],
            [
                'direction' => ComplexInvitation::REQUEST,
                // نقشِ پیشنهادی؛ مدیر هنگام تایید می‌تواند عوضش کند
                'role' => UserRole::Owner,
                'invited_by' => $user->id,
            ],
        );

        Audit::log('join_request.sent', 'ارسال درخواست پیوستن به مجتمع', $invitation, [
            'complex_id' => $manager->complex_id,
        ]);

        return response()->json([
            'message' => 'درخواست شما برای مدیر «'.$manager->complex->name.'» ارسال شد. '
                .'پس از تایید ایشان به مجتمع اضافه می‌شوید.',
        ], 202);
    }

    /* ── سمتِ مدیر ──────────────────────────────────────────────────────── */

    /** درخواست‌های در انتظارِ این مجتمع. */
    public function index(): JsonResponse
    {
        $complexId = $this->requireComplex()->id;

        $requests = ComplexInvitation::query()
            ->where('complex_id', $complexId)
            ->requests()
            ->pending()
            ->with('user:id,name,phone')
            ->latest()
            ->get();

        return response()->json([
            'data' => $requests->map(fn (ComplexInvitation $r) => [
                'id' => $r->id,
                // مدیر باید بداند چه کسی درخواست داده: نام و شماره
                'name' => $r->user?->name,
                'phone' => $r->user?->phone,
                'createdAt' => $r->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function approve(Request $request, ComplexInvitation $invitation): JsonResponse
    {
        $this->authorizeManagerOf($invitation);

        $role = $request->input('role') === UserRole::Tenant->value
            ? UserRole::Tenant
            : UserRole::Owner;

        DB::transaction(function () use ($invitation, $role): void {
            /*
             * وضعیت داخلِ قفل دوباره خوانده می‌شود: دو کلیکِ هم‌زمانِ مدیر
             * نباید دو بار کاربر را وصل کند.
             */
            $fresh = ComplexInvitation::lockForUpdate()->find($invitation->id);

            if (! $fresh || $fresh->status !== ComplexInvitation::PENDING) {
                throw DomainException::invalid('این درخواست دیگر معتبر نیست.', 'invitation.not_pending');
            }

            $applicant = User::withoutGlobalScopes()->lockForUpdate()->find($fresh->user_id);

            /*
             * ممکن است بینِ ارسال و تایید، خودش جای دیگری عضو شده باشد.
             * تاییدِ کورکورانه او را بی‌خبر از مجتمعِ فعلی‌اش بیرون می‌کشید.
             */
            if (! $applicant || $applicant->complex_id !== null) {
                throw DomainException::invalid(
                    'این کاربر دیگر در انتظار پیوستن نیست.',
                    'join_request.user_unavailable',
                );
            }

            $applicant->forceFill([
                'complex_id' => $fresh->complex_id,
                'role' => $role,
            ])->save();

            $fresh->update([
                'status' => ComplexInvitation::ACCEPTED,
                'role' => $role,
                'responded_at' => now(),
            ]);

            // بقیه‌ی درخواست‌ها/دعوت‌های در انتظارِ همین کاربر باطل می‌شوند
            ComplexInvitation::where('user_id', $applicant->id)
                ->pending()
                ->update(['status' => ComplexInvitation::DECLINED, 'responded_at' => now()]);
        }, attempts: 3);

        Audit::log('join_request.approved', 'تایید درخواست پیوستن', $invitation);

        return response()->json(['message' => 'کاربر به مجتمع اضافه شد.']);
    }

    public function reject(ComplexInvitation $invitation): JsonResponse
    {
        $this->authorizeManagerOf($invitation);

        $invitation->update([
            'status' => ComplexInvitation::DECLINED,
            'responded_at' => now(),
        ]);

        return response()->json(['message' => 'درخواست رد شد.']);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /**
     * مدیرِ فعالِ یک مجتمع بر پایه‌ی شماره — یا null.
     *
     * ⚠️ «مدیرِ مجتمع» یعنی نقشِ `complex_admin` + حسابِ فعال + وصل بودن به
     * یک مجتمع. عمداً **اشتراکِ فعال شرط نشد**: مدیرهایی که ادمینِ کل ساخته
     * و مجتمع‌های پلنِ رایگان هم مدیرِ واقعی‌اند و شرطِ اشتراک آن‌ها را از
     * دسترسِ ساکنانشان خارج می‌کرد.
     */
    private function managerByPhone(string $phone): ?User
    {
        if (! preg_match('/^09\d{9}$/', Phone::normalize($phone))) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->where('phone', Phone::normalize($phone))
            ->where('role', UserRole::ComplexAdmin->value)
            ->where('is_active', true)
            ->whereNotNull('complex_id')
            ->with('complex:id,name')
            ->first();
    }

    /**
     * فقط مدیرِ همان مجتمع می‌تواند پاسخ بدهد.
     *
     * ۴۰۴ و نه ۴۰۳: وجودِ یک درخواست هم اطلاعات است.
     */
    private function authorizeManagerOf(ComplexInvitation $invitation): void
    {
        abort_unless(
            $invitation->direction === ComplexInvitation::REQUEST
                && $invitation->complex_id === ComplexResolver::activeId(),
            404,
        );
    }
}
