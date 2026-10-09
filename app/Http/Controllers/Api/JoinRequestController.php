<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountState;
use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveJoinRequestRequest;
use App\Models\ComplexInvitation;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\JoinRequestNotification;
use App\Services\Account\Membership;
use App\Support\Audit;
use App\Support\ComplexResolver;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
    public function __construct(private readonly Membership $membership) {}

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

        /*
         * یک درخواستِ در انتظار برای هر (مجتمع، کاربر) — در خودِ پایگاه‌داده
         * (`live_key` ِ یکتا؛ مرحلهٔ ۹ · فاز ۶). ارسالِ دوباره همان را تازه می‌کند.
         */
        $key = 'request:'.$manager->complex_id.':'.$user->id;
        $invitation = ComplexInvitation::where('live_key', $key)->first()
            ?? new ComplexInvitation([
                'complex_id' => $manager->complex_id,
                'user_id' => $user->id,
                'phone' => $user->phone,
                'direction' => ComplexInvitation::REQUEST,
                'status' => ComplexInvitation::PENDING,
            ]);
        $invitation->fill([
            // نقشِ پیشنهادی؛ مدیر هنگام تایید می‌تواند عوضش کند
            'role' => UserRole::Owner,
            'invited_by' => $user->id,
        ]);
        $invitation->forceFill(['live_key' => $key])->save();

        Audit::log('join_request.sent', 'ارسال درخواست پیوستن به مجتمع', $invitation, [
            'complex_id' => $manager->complex_id,
        ]);

        /*
         * اعلانِ داشبورد به همه‌ی مدیرانِ فعالِ همین مجتمع (تصمیمِ کارفرما؛ مرحلهٔ ۹ ·
         * اصلاحاتِ پس از فاز ۶). فقط بارِ اول: ارسالِ دوباره‌ی همان درخواست صندوق و
         * اعلان‌ها را پر نمی‌کند.
         */
        if ($invitation->wasRecentlyCreated) {
            User::withoutGlobalScopes()
                ->where('complex_id', $manager->complex_id)
                ->where('role', UserRole::ComplexAdmin->value)
                ->where('is_active', true)
                ->get()
                ->each(fn (User $admin) => $admin->notify(new JoinRequestNotification($invitation, $user->name)));
        }

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

    /**
     * تأیید — با نقش و **واحد** (F6). شرط‌ها زیرِ قفل در `Membership::approveRequest`
     * دوباره خوانده می‌شوند: دو کلیکِ هم‌زمانِ مدیر یک بار کاربر را وصل می‌کند.
     */
    public function approve(ApproveJoinRequestRequest $request, ComplexInvitation $invitation): JsonResponse
    {
        $this->authorizeManagerOf($invitation);

        $data = $request->validated();

        $this->membership->approveRequest(
            $invitation,
            UserRole::from($data['role']),
            Unit::findOrFail($data['unit_id']),
        );

        Audit::log('join_request.approved', 'تایید درخواست پیوستن', $invitation, [
            'unit_id' => (int) $data['unit_id'],
        ]);

        return response()->json(['message' => 'کاربر به مجتمع و واحدش اضافه شد.']);
    }

    public function reject(ComplexInvitation $invitation): JsonResponse
    {
        $this->authorizeManagerOf($invitation);

        $invitation->close(ComplexInvitation::DECLINED);

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
    /**
     * فقط مدیرِ همان مجتمع می‌تواند به این درخواست دست بزند.
     *
     * ⚠️ شرطِ نقش اینجا **تکرارِ** قیدِ `role:` روی مسیر است، و عمدی.
     *
     * تا R53 این متد فقط می‌سنجید که درخواست به «مجتمعِ فعال» تعلق دارد.
     * ولی مجتمعِ فعالِ یک ساکن، همان مجتمعِ خودش است — پس آن شرط برای او
     * هم برقرار بود و تستِ حمله ۲۰۰ گرفت: یک ساکنِ ساده می‌توانست کاربرِ
     * دلخواهی را عضوِ مجتمع کند و نقشش را تعیین کند.
     *
     * قیدِ نقش به فایلِ مسیرها اضافه شد، ولی اینجا هم می‌ماند: مجوزی که
     * فقط در یک فایلِ پیکربندی زندگی کند، با یک بازآراییِ مسیرها بی‌صدا
     * می‌افتد. این متد تنها دروازه‌ی ورودِ هر دو عملِ حساس است.
     */
    private function authorizeManagerOf(ComplexInvitation $invitation): void
    {
        $user = Auth::user();

        abort_unless(
            $user !== null
                && in_array($user->role, [UserRole::SuperAdmin, UserRole::ComplexAdmin], true)
                && $invitation->direction === ComplexInvitation::REQUEST
                && $invitation->complex_id === ComplexResolver::activeId(),
            404,
        );
    }
}
