<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillResource;
use App\Models\Bill;
use App\Services\Finance\ResidentDebt;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * صورت‌حساب‌های خود کاربر.
 *
 * برخلاف Api\BillController که نمای مدیر روی کل مجتمع است، اینجا فقط قبوض
 * واحدهایی برگردانده می‌شود که کاربر در حال حاضر مالک یا مستاجرشان است.
 */
class MyBillController extends Controller
{
    public function index(ResidentDebt $debt): JsonResponse
    {
        $user = Auth::user();
        // فقط دوره‌های سکونتِ خودش، نه سابقه‌ی ساکنِ قبلیِ واحد (مرحلهٔ ۹ · فاز ۶، F4)
        $bills = Bill::query()->visibleToResident($user)
            ->with('unit')
            ->orderByDesc('period')
            ->paginate(15);

        return response()->json([
            'data' => collect($bills->items())->map(fn (Bill $b) => $this->present($b))->values(),
            'meta' => [
                'currentPage' => $bills->currentPage(),
                'lastPage' => $bills->lastPage(),
                'total' => $bills->total(),
            ],
            'currency' => $user->complex?->currencyLabel() ?? 'تومان',
            // بدهیِ دوره‌های خودش، همان قبض‌های بالا — نه مانده‌ی کلِ واحد با ساکنِ قبلی (۱۰/۱۳)
            'totalDebt' => $debt->total($user),
        ]);
    }

    public function show(Bill $bill): JsonResponse
    {
        $this->authorizeBill($bill);
        $bill->load('unit', 'payments');

        return response()->json([
            'bill' => $this->present($bill) + [
                // breakdown ریز محاسبه‌ی شارژ است؛ ستون JSON روی خود قبض،
                // با کلیدهای label/category/amount که BillGenerator می‌نویسد.
                'breakdown' => collect($bill->breakdown ?? [])->map(fn ($row) => [
                    'label' => $row['label'] ?? '—',
                    'amount' => (float) ($row['amount'] ?? 0),
                    'categoryLabel' => match ($row['category'] ?? null) {
                        'owner' => 'مالکانه',
                        'tenant' => 'مستاجرانه',
                        default => null,
                    },
                ])->values(),
                'payments' => $bill->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => (float) $p->amount,
                    'method' => $p->method?->label(),
                    'status' => $p->status->value,
                    'statusLabel' => $p->status->label(),
                    'paidAt' => $p->paid_at ? Jalali::date($p->paid_at) : null,
                ])->values(),
            ],
        ]);
    }

    /**
     * شکلِ خروجی حالا در `BillResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Bill $bill): array
    {
        return (new BillResource($bill))->toArray(request());
    }

    /** قبض باید متعلق به یکی از واحدهای کاربر باشد. */
    private function authorizeBill(Bill $bill): void
    {
        $this->authorize('view', $bill);
    }
}
