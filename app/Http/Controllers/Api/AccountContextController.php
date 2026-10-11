<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SwitchContextRequest;
use App\Models\ComplexMembership;
use App\Models\User;
use App\Services\Account\MembershipLedger;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * عضویت‌های خودِ حساب و زمینه‌ی فعال (مرحلهٔ ۱۰ · فاز ۳؛ بندهای ۱۳، ۱۴ و ۹۲ ِ فایلِ معماری).
 *
 * فقط درباره‌ی **خودِ** کاربرِ واردشده: هیچ شناسه‌ی حسابی از کلاینت پذیرفته نمی‌شود، و
 * شناسه‌ی مجتمع در تعویض فقط «کدام» است — اجازه از عضویتِ فعالِ همین حساب در آن مجتمع.
 * ادمینِ کل عضویت ندارد؛ مجتمعِ کاریِ او از پنلِ سیستم انتخاب می‌شود.
 */
class AccountContextController extends Controller
{
    public function __construct(private readonly MembershipLedger $ledger) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->present($user));
    }

    public function switch(SwitchContextRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            throw DomainException::invalid('ادمینِ کل مجتمعِ کاری را از پنلِ سیستم انتخاب می‌کند.', 'membership.platform_account');
        }

        $this->ledger->switchTo($user, (int) $request->validated()['complex_id']);

        return response()->json($this->present($user));
    }

    /** @return array{current: int|null, memberships: list<array<string, mixed>>} */
    private function present(User $user): array
    {
        /*
         * ⚠️ `withoutGlobalScopes`: عضویت‌های خودِ حساب در **همه‌ی** مجتمع‌ها — دامنه‌ی
         * مستأجر فقط مجتمعِ جاری را نشان می‌داد. مرزِ دسترسی `user_id = خودش` است.
         */
        $memberships = ComplexMembership::withoutGlobalScopes()
            ->active()
            ->where('user_id', $user->id)
            ->with('complex:id,name,type')
            ->orderBy('started_at')
            ->get();

        return [
            'current' => $user->complex_id,
            'memberships' => $memberships->map(fn (ComplexMembership $m) => [
                'complexId' => $m->complex_id,
                'complexName' => $m->complex?->name,
                'complexTypeLabel' => $m->complex?->type->label(),
                'role' => $m->role->value,
                'roleLabel' => $m->role->label(),
                'since' => Jalali::date($m->started_at),
                'current' => $m->complex_id === $user->complex_id,
            ])->values()->all(),
        ];
    }
}
