<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveJoinRequestRequest;
use App\Models\Complex;
use App\Models\ComplexInvitation;
use App\Models\ComplexMembership;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\JoinRequestNotification;
use App\Services\Account\ComplexMembers;
use App\Services\Account\Membership;
use App\Services\Account\PublicJoinLink;
use App\Support\Audit;
use App\Support\ComplexResolver;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Collection;
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
    public function __construct(
        private readonly Membership $membership,
        private readonly PublicJoinLink $joinLinks,
    ) {}

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
        $phone = $request->query('phone');
        $complexes = is_string($phone) ? $this->managedByPhone($phone) : collect();

        return response()->json([
            'found' => $complexes->isNotEmpty(),
            // یک مجتمع: همان نام. چند مجتمع: کاربر از `complexes` برمی‌گزیند (۱۲/۳)
            'complexName' => $complexes->count() === 1 ? $complexes->first()?->name : null,
            'complexes' => $complexes->map(fn (Complex $c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ]);
    }

    /**
     * ارسالِ درخواست به مدیر — از دو راهِ عمومی: شماره‌ی مدیر، یا کدِ QR ِ مجتمع (مرحلهٔ ۱۰ · فاز ۵).
     *
     * هر دو همان درخواستِ در انتظار را می‌سازند: تا تأییدِ مدیر هیچ عضویتی نیست (بندِ ۲۳).
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($request->filled('code')) {
            $code = $request->input('code');
            $complex = is_string($code) ? $this->joinLinks->resolve($code) : null;
            $channel = 'qr';

            if ($complex === null) {
                throw DomainException::invalid(
                    'این QR معتبر نیست؛ شاید مدیر آن را عوض کرده است.',
                    'join_link.invalid',
                );
            }
        } else {
            $phone = $request->input('phone');
            $complex = $this->chosenComplex(is_string($phone) ? $this->managedByPhone($phone) : collect(), $request->input('complex_id'));
            $channel = 'manager_phone';
        }

        // از ۱۰/۴ عضوِ مجتمعِ دیگر هم درخواست می‌دهد؛ عضوِ همین مجتمع نه
        // عضوِ معلقِ همین مجتمع هم عضو است: درخواستِ تازه تعلیق را دور نمی‌زند (۱۰/۶)
        if (ComplexMembers::has($user, $complex->id, ComplexMembership::ROLES, withSuspended: true)) {
            throw DomainException::invalid(
                'شما از قبل عضوِ همین مجتمع هستید.',
                'account.already_member',
            );
        }

        /*
         * یک درخواستِ در انتظار برای هر (مجتمع، کاربر) — در خودِ پایگاه‌داده
         * (`live_key` ِ یکتا؛ مرحلهٔ ۹ · فاز ۶). ارسالِ دوباره همان را تازه می‌کند.
         */
        $key = 'request:'.$complex->id.':'.$user->id;
        $invitation = ComplexInvitation::where('live_key', $key)->first()
            ?? new ComplexInvitation([
                'complex_id' => $complex->id,
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
            'complex_id' => $complex->id,
            'channel' => $channel,
        ]);

        /*
         * اعلانِ داشبورد به همه‌ی مدیرانِ فعالِ همین مجتمع (تصمیمِ کارفرما؛ مرحلهٔ ۹ ·
         * اصلاحاتِ پس از فاز ۶). فقط بارِ اول: ارسالِ دوباره‌ی همان درخواست صندوق و
         * اعلان‌ها را پر نمی‌کند.
         */
        if ($invitation->wasRecentlyCreated) {
            ComplexMembers::query($complex->id, [UserRole::ComplexAdmin])
                ->where('users.is_active', true)
                ->get()
                ->each(fn (User $admin) => $admin->notify(new JoinRequestNotification($invitation, $user->name)));
        }

        return response()->json([
            'message' => 'درخواست شما برای مدیر «'.$complex->name.'» ارسال شد. '
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

        $this->membership->rejectRequest($invitation);

        Audit::log('join_request.rejected', 'رد درخواست پیوستن', $invitation, [
            'complex_id' => $invitation->complex_id,
        ]);

        return response()->json(['message' => 'درخواست رد شد.']);
    }

    /* ── کمکی ──────────────────────────────────────────────────────────── */

    /**
     * مجتمع‌هایی که صاحبِ این شماره **اکنون** مدیرشان است — از عضویت، نه ردیفِ حساب.
     *
     * 🔴 ۱۲/۳: پیش از این از `users.role` و `users.complex_id` خوانده می‌شد. از ۱۲/۱ آن ستون‌ها فقط «پیش‌فرض» اند:
     * مدیری که زمینه‌ی پیش‌فرضش مجتمعی است که در آن ساکن است پیدا نمی‌شد، مدیرِ دو مجتمع فقط یکی را داشت، و
     * مجتمعِ تعلیق‌شده (که QR ِ آن ۴۰۴ است، `PublicJoinLink::resolve`) درخواست می‌گرفت.
     *
     * «مدیر» یعنی عضویتِ **فعالِ** `complex_admin` (نه معلق، نه پایان‌یافته) + حسابِ فعال + مجتمعِ فعال. عمداً
     * **اشتراکِ فعال شرط نیست**: مدیرهایی که ادمینِ کل ساخته و مجتمع‌های پلنِ رایگان هم مدیرِ واقعی‌اند.
     *
     * @return Collection<int, Complex>
     */
    private function managedByPhone(string $phone): Collection
    {
        $phone = Phone::normalize($phone);

        if (! preg_match('/^09\d{9}$/', $phone)) {
            return new Collection;
        }

        return Complex::query()
            ->join('complex_memberships as cm', 'cm.complex_id', '=', 'complexes.id')
            ->join('users', 'users.id', '=', 'cm.user_id')
            ->where('users.phone', $phone)
            ->where('users.is_active', true)
            ->where('cm.role', UserRole::ComplexAdmin->value)
            ->where('cm.status', ComplexMembership::ACTIVE)
            ->where('complexes.is_active', true)
            ->orderBy('complexes.id')
            ->get(['complexes.id', 'complexes.name']);
    }

    /**
     * مجتمعِ مقصد از میانِ مجتمع‌های همان مدیر. مدیرِ چند مجتمع: متقاضی باید یکی را برگزیند — درخواست به مجتمعِ
     * حدسی نمی‌رود، چون نام و شماره‌ی او به مدیرانِ همان مجتمع می‌رسد. شناسه‌ای بیرون از این فهرست همان پاسخِ
     * «مدیر نیست» را دارد: پاسخ نمی‌گوید آن مجتمع وجود دارد یا نه.
     *
     * @param  Collection<int, Complex>  $candidates
     */
    private function chosenComplex(Collection $candidates, mixed $chosen): Complex
    {
        $notFound = DomainException::invalid('این شماره متعلق به هیچ مدیر مجتمعی نیست.', 'join_request.manager_not_found');

        if ($chosen !== null) {
            $id = is_int($chosen) || (is_string($chosen) && ctype_digit($chosen)) ? (int) $chosen : null;

            return ($id === null ? null : $candidates->firstWhere('id', $id)) ?? throw $notFound;
        }

        if ($candidates->count() > 1) {
            throw DomainException::invalid('این شماره مدیرِ چند مجتمع است؛ مجتمعِ خودتان را انتخاب کنید.', 'join_request.choose_complex');
        }

        return $candidates->first() ?? throw $notFound;
    }

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
