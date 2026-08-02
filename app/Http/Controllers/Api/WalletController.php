<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Unit;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * کیفِ پولِ واحد (R22).
 *
 * ساکن موجودی و صورت‌حسابِ واحدهای خودش را می‌بیند و می‌تواند با آن قبض
 * پرداخت کند. شارژِ کیف از مسیرِ رسید یا درگاه انجام می‌شود (همان مسیرهای
 * پرداختِ موجود)، نه از اینجا — اینجا فقط خرج‌کردن و دیدن است.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    /** موجودی و صورت‌حسابِ واحدهای کاربر. */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $units = $user->role->isAdmin()
            ? Unit::orderBy('unit_number')->get()
            : $user->units()->get();

        return response()->json([
            'wallets' => $units->map(fn (Unit $unit) => [
                'unitId' => $unit->id,
                'unitLabel' => 'واحد '.$unit->unit_number,
                'balance' => $this->wallet->balance($unit),
            ])->values(),
        ]);
    }

    /** صورت‌حسابِ یک واحد. */
    public function statement(Unit $unit): JsonResponse
    {
        $this->authorizeUnit($unit);

        $rows = WalletTransaction::where('unit_id', $unit->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'balance' => $this->wallet->balance($unit),
            'transactions' => $rows->map(fn (WalletTransaction $row) => [
                'id' => $row->id,
                'direction' => $row->direction,
                'amount' => (float) $row->amount,
                'balanceAfter' => (float) $row->balance_after,
                'sourceLabel' => $row->sourceLabel(),
                'note' => $row->note,
                'date' => Jalali::date($row->created_at),
            ])->values(),
        ]);
    }

    /** پرداختِ یک قبض از موجودیِ کیف. */
    public function payBill(Request $request, Bill $bill): JsonResponse
    {
        $unit = Unit::findOrFail($bill->unit_id);

        $this->authorizeUnit($unit);

        $paid = $this->wallet->payBill(
            $unit,
            $bill,
            $request->filled('amount') ? (float) $request->input('amount') : null,
        );

        return response()->json([
            'message' => 'مبلغ '.number_format($paid).' از کیف پول پرداخت شد.',
            'paid' => $paid,
            'balance' => $this->wallet->balance($unit),
        ]);
    }

    /**
     * فقط ساکنِ همان واحد (یا مدیرِ همان مجتمع).
     *
     * ۴۰۴ و نه ۴۰۳: وجود یا نبودِ یک واحد در مجتمعِ دیگر هم اطلاعات است.
     */
    private function authorizeUnit(Unit $unit): void
    {
        abort_unless($this->wallet->isAccessibleBy($unit, Auth::user()), 404);
    }
}
