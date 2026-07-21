<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Services\Charge\BillGenerator;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $complex = $this->requireComplex();
        $period = $request->query('period', Jalali::currentPeriod());

        $bills = Bill::where('period', $period)
            ->with('unit')
            ->join('units', 'bills.unit_id', '=', 'units.id')
            ->orderBy('units.unit_number')
            ->select('bills.*')
            ->get();

        // انتخابگر دوره: چند ماه گذشته و یک ماه آینده
        $periods = collect(range(-6, 1))
            ->map(fn ($i) => Jalali::shiftPeriod(Jalali::currentPeriod(), $i))
            ->map(fn ($p) => ['value' => $p, 'label' => Jalali::periodLabel($p)])
            ->values();

        return response()->json([
            'period' => $period,
            'periodLabel' => Jalali::periodLabel($period),
            'periods' => $periods,
            'currency' => $complex->currencyLabel(),
            'total' => (float) $bills->sum('total_amount'),
            'collected' => (float) $bills->sum('paid_amount'),
            'data' => $bills->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'unitLabel' => 'واحد '.$bill->unit?->unit_number,
                'ownerAmount' => (float) $bill->owner_amount,
                'tenantAmount' => (float) $bill->tenant_amount,
                'penaltyAmount' => (float) $bill->penalty_amount,
                'totalAmount' => (float) $bill->total_amount,
                'paidAmount' => (float) $bill->paid_amount,
                'status' => $bill->status->value,
                'statusLabel' => $bill->status->label(),
                'dueDate' => $bill->due_date ? Jalali::date($bill->due_date) : null,
            ])->values(),
        ]);
    }

    /** صدور یا به‌روزرسانی قبوض یک دوره. */
    public function generate(Request $request, BillGenerator $generator): JsonResponse
    {
        $complex = $this->requireComplex();
        $period = $request->input('period', Jalali::currentPeriod());

        // generate() آرایه‌ی قبوض ساخته‌شده را برمی‌گرداند، نه تعداد
        $bills = $generator->generate($complex, $period);

        return response()->json([
            'message' => 'قبوض دوره '.Jalali::periodLabel($period).' صادر شد.',
            'count' => count($bills),
        ]);
    }
}
