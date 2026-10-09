<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * کیفِ پولِ **شخصی** (R22؛ مالکیت از مرحلهٔ ۹ · اصلاحاتِ پس از فاز ۶).
 *
 * ─── چه کسی چه می‌بیند ─────────────────────────────────────────────────────
 * • هر کس — ساکن، مدیر، و حسابِ اولیه پس از پایانِ عضویت — **فقط کیفِ خودش**
 *   را می‌بیند، به تفکیکِ مجتمع (جیب). هیچ شناسه‌ی واحد یا مجتمعی در درخواست
 *   صاحبِ کیف را عوض نمی‌کند: صاحب همیشه کاربرِ واردشده است.
 * • مدیر علاوه بر آن، جیب‌های اعضای **همین مجتمع** را برای حسابداری می‌بیند —
 *   فقط بخشی که در همین مجتمع است.
 *
 * شارژِ کیف از مسیرِ پرداخت (مازاد) انجام می‌شود، نه از اینجا.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    /** جیب‌های کیفِ خودِ کاربر؛ برای مدیر، جیب‌های اعضای همین مجتمع هم. */
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $wallets = collect($this->wallet->pockets($user))->map(fn (array $pocket) => [
            'key' => 'own-'.$pocket['complexId'],
            'label' => $pocket['complexName'] ?? 'مجتمع',
            'balance' => $pocket['balance'],
            'statement' => '/wallet/'.$pocket['complexId'],
        ]);

        $members = [];
        if ($user->role->isAdmin() && ($complex = $this->currentComplex())) {
            $members = User::query()
                ->whereIn('id', WalletTransaction::query()->where('complex_id', $complex->id)->select('user_id'))
                ->orderBy('name')
                ->get()
                ->map(fn (User $member) => [
                    'key' => 'member-'.$member->id,
                    'label' => $member->name,
                    'balance' => $this->wallet->balance($member, $complex->id),
                    'statement' => '/wallet/members/'.$member->id,
                ])
                ->values()
                ->all();
        }

        return response()->json(['wallets' => $wallets->values()->all(), 'members' => $members]);
    }

    /** صورت‌حسابِ جیبِ **خودِ** کاربر در یک مجتمع — حتی پس از پایانِ عضویت. */
    public function statement(int $complexId): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json($this->statementOf($user, $complexId));
    }

    /**
     * صورت‌حسابِ یک عضو برای مدیرِ همین مجتمع.
     *
     * `User` با دامنه‌ی مستأجر بایند می‌شود: عضوِ مجتمعِ دیگر ۴۰۴ است. و فقط
     * ردیف‌های همین مجتمع، نه جیب‌های او جای دیگر.
     */
    public function memberStatement(User $member): JsonResponse
    {
        $complexId = $this->requireComplex()->id;

        /*
         * 🔴 `User` دامنه‌ی مستأجر ندارد؛ بی این، مدیرِ مجتمعِ دیگر برای هر کاربری
         * ۲۰۰ می‌گرفت (`TenantIsolationTest` گرفت). فقط کسی که در همین مجتمع
         * عضو است یا کیفش در همین مجتمع ردیف دارد (عضوِ رفته)؛ بقیه ۴۰۴.
         */
        abort_unless(
            $member->complex_id === $complexId
                || WalletTransaction::withoutGlobalScopes()->where('user_id', $member->id)->where('complex_id', $complexId)->exists(),
            404,
        );

        return response()->json($this->statementOf($member, $complexId));
    }

    /** پرداختِ یک قبض از کیفِ خودِ پرداخت‌کننده (جیبِ همان مجتمع). */
    public function payBill(Request $request, Bill $bill): JsonResponse
    {
        $this->authorize('pay', $bill);

        /** @var User $user */
        $user = Auth::user();

        $paid = $this->wallet->payBill(
            $user,
            $bill,
            $request->filled('amount') ? (float) $request->input('amount') : null,
        );

        return response()->json([
            'message' => 'مبلغ '.number_format($paid).' از کیف پول پرداخت شد.',
            'paid' => $paid,
            'balance' => $this->wallet->balance($user, $bill->complex_id),
        ]);
    }

    /** @return array{balance: float, transactions: list<array<string, mixed>>} */
    private function statementOf(User $owner, int $complexId): array
    {
        /*
         * ⚠️ `withoutGlobalScopes`: صاحبِ کیف شاید دیگر عضوِ این مجتمع نباشد (حسابِ
         * اولیه) و دامنه‌ی مستأجر برایش «هیچ‌چیز» است. مرزِ دسترسی همان
         * `user_id = صاحب` است.
         */
        $rows = WalletTransaction::withoutGlobalScopes()
            ->where('user_id', $owner->id)
            ->where('complex_id', $complexId)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return [
            'balance' => $this->wallet->balance($owner, $complexId),
            'transactions' => $rows->map(fn (WalletTransaction $row) => [
                'id' => $row->id,
                'direction' => $row->direction,
                'amount' => (float) $row->amount,
                'balanceAfter' => (float) $row->balance_after,
                'sourceLabel' => $row->sourceLabel(),
                'note' => $row->note,
                'date' => Jalali::date($row->created_at),
            ])->values()->all(),
        ];
    }
}
