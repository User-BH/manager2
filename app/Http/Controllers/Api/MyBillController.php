<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
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
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $unitIds = $user->currentUnits()->pluck('units.id');

        $bills = Bill::whereIn('unit_id', $unitIds)
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
            'totalDebt' => (float) $user->currentUnits()->sum('balance'),
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

    private function present(Bill $bill): array
    {
        return [
            'id' => $bill->id,
            'unitLabel' => $bill->unit ? $bill->unit->label() : '—',
            'period' => $bill->period,
            'periodLabel' => Jalali::periodLabel($bill->period),
            'ownerAmount' => (float) $bill->owner_amount,
            'tenantAmount' => (float) $bill->tenant_amount,
            'penaltyAmount' => (float) $bill->penalty_amount,
            'totalAmount' => (float) $bill->total_amount,
            'paidAmount' => (float) $bill->paid_amount,
            'remaining' => (float) $bill->remaining(),
            'status' => $bill->status->value,
            'statusLabel' => $bill->status->label(),
            'dueDate' => $bill->due_date ? Jalali::date($bill->due_date) : null,
            // PDF یک دانلود مستقیم است و صفحه‌ی پرداخت مسیر داخلی SPA
            'pdfUrl' => route('bills.invoice', $bill),
            'payPath' => '/pay/'.$bill->id,
        ];
    }

    /** قبض باید متعلق به یکی از واحدهای کاربر باشد. */
    private function authorizeBill(Bill $bill): void
    {
        $unitIds = Auth::user()->currentUnits()->pluck('units.id');

        abort_unless($unitIds->contains($bill->unit_id) || Auth::user()->isAdmin(), 403);
    }
}
