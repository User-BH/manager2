<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Complex;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * کیفِ پولِ **شخصی** (R22؛ یک کیف برای هر حساب — مرحلهٔ ۹، تصمیمِ نهاییِ کارفرما).
 *
 * ─── چه کسی چه می‌بیند ─────────────────────────────────────────────────────
 * • هر کس — ساکن، مدیر، و حسابِ اولیه پس از پایانِ عضویت — **فقط کیفِ خودش**
 *   را می‌بیند: یک مانده و یک دفتر، در هر داشبوردی همان. هیچ شناسه‌ی واحد،
 *   مجتمع یا حسابی در درخواست صاحب را عوض نمی‌کند.
 * • مدیر کیفِ شخصیِ اعضا را **نمی‌بیند** (تصمیمِ کارفرما: «مدیر بودن اجازه‌ی
 *   دسترسی به کیفِ شخصیِ دیگران نمی‌دهد»). فقط ردیف‌هایی را می‌بیند که در
 *   **همین مجتمع** ثبت شده‌اند — مازادِ پرداخت به همین مجتمع و پرداختِ قبض‌های
 *   همین مجتمع — بی مانده و بی «مانده پس از تراکنش»، چون آن عدد گردشِ شخص در
 *   مجتمع‌های دیگر را لو می‌دهد.
 *
 * شارژِ کیف از مسیرِ پرداخت (مازاد) انجام می‌شود، نه از اینجا.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    /** کیفِ خودِ کاربر؛ برای مدیر، فهرستِ اعضایی که در همین مجتمع گردشِ کیف دارند. */
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $wallets = [[
            'key' => 'own',
            'label' => 'کیفِ پولِ شخصی',
            'balance' => $this->wallet->balance($user),
            'statement' => '/wallet/statement',
        ]];

        $members = [];
        if ($user->role->isAdmin() && ($complex = $this->currentComplex())) {
            $members = User::query()
                ->whereIn('id', WalletTransaction::query()->where('complex_id', $complex->id)->select('user_id'))
                ->orderBy('name')
                ->get()
                ->map(fn (User $member) => [
                    'key' => 'member-'.$member->id,
                    'label' => $member->name,
                    'balance' => null,
                    'statement' => '/wallet/members/'.$member->id,
                ])
                ->values()
                ->all();
        }

        return response()->json(['wallets' => $wallets, 'members' => $members]);
    }

    /** صورت‌حسابِ کیفِ **خودِ** کاربر — همه‌ی مجتمع‌ها، حتی پس از پایانِ عضویت. */
    public function statement(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /*
         * ⚠️ `withoutGlobalScopes`: صاحبِ کیف شاید دیگر عضوِ مجتمعی نباشد (حسابِ
         * اولیه) و دامنه‌ی مستأجر برایش «هیچ‌چیز» است. مرزِ دسترسی `user_id = خودش` است.
         */
        $rows = WalletTransaction::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $balance = $this->wallet->balance($user);

        return response()->json([
            'balance' => $balance,
            'transactions' => $this->present($rows, $balance),
        ]);
    }

    /**
     * گردشِ کیفِ یک عضو **در همین مجتمع**، برای مدیرِ همین مجتمع.
     *
     * فقط عضوی که در همین مجتمع است یا در همین مجتمع ردیف دارد (عضوِ رفته)؛
     * بقیه ۴۰۴. مانده‌ی شخصی و «مانده پس از تراکنش» برگردانده نمی‌شوند.
     */
    public function memberStatement(User $member): JsonResponse
    {
        $complexId = $this->requireComplex()->id;

        // `User` دامنه‌ی مستأجر ندارد؛ بی این، مدیرِ مجتمعِ دیگر برای هر کاربری ۲۰۰ می‌گرفت
        abort_unless(
            $member->complex_id === $complexId
                || WalletTransaction::withoutGlobalScopes()->where('user_id', $member->id)->where('complex_id', $complexId)->exists(),
            404,
        );

        $rows = WalletTransaction::withoutGlobalScopes()
            ->where('user_id', $member->id)
            ->where('complex_id', $complexId)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'balance' => null,
            'transactions' => $this->present($rows, null),
        ]);
    }

    /**
     * پرداختِ یک قبض از کیفِ خودِ پرداخت‌کننده — در هر مجتمعی که اجازه‌ی پرداختِ
     * آن قبض را دارد (`BillPolicy::pay`). `Idempotency-Key` (سرآیند یا `idempotency_key` در بدنه)
     * تکرار را بی‌اثر می‌کند.
     */
    public function payBill(Request $request, Bill $bill): JsonResponse
    {
        $this->authorize('pay', $bill);

        /** @var User $user */
        $user = Auth::user();

        // سرآیندِ استاندارد، یا همان کلید در بدنه (کلاینتِ خودِ برنامه؛ لایه‌ی مشترکِ HTTP دست نخورد)
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');
        if ($key !== null && ! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'شناسه‌ی درخواست نامعتبر است.']);
        }

        $paid = $this->wallet->payBill(
            $user,
            $bill,
            $request->filled('amount') ? (float) $request->input('amount') : null,
            $key,
        );

        return response()->json([
            'message' => 'مبلغ '.number_format($paid).' از کیف پول پرداخت شد.',
            'paid' => $paid,
            'balance' => $this->wallet->balance($user),
        ]);
    }

    /**
     * ردیف‌ها برای نمایش. با `$balance`، «مانده پس از تراکنش» از **خودِ دفتر** ساخته
     * می‌شود (از مانده‌ی فعلی به عقب) — نه از `balance_after` ِ ذخیره‌شده، که برای
     * ردیف‌های پیش از مدلِ یک‌کیفی عکسِ جیبِ همان مجتمع است.
     *
     * @param  Collection<int, WalletTransaction>  $rows  جدیدترین اول
     * @return list<array<string, mixed>>
     */
    private function present(Collection $rows, ?float $balance): array
    {
        $names = Complex::withoutGlobalScopes()->whereIn('id', $rows->pluck('complex_id')->unique())->pluck('name', 'id');
        $running = $balance;

        return $rows->map(function (WalletTransaction $row) use ($names, &$running): array {
            $after = $running;
            if ($running !== null) {
                $running = round($running - ($row->direction === WalletTransaction::CREDIT ? 1 : -1) * (float) $row->amount, 2);
            }

            return [
                'id' => $row->id,
                'direction' => $row->direction,
                'amount' => (float) $row->amount,
                'balanceAfter' => $after,
                'complexName' => $names[$row->complex_id] ?? null,
                'sourceLabel' => $row->sourceLabel(),
                'note' => $row->note,
                'date' => Jalali::date($row->created_at),
            ];
        })->values()->all();
    }
}
