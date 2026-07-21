<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Unit;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DiscountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $complex = $this->requireComplex();
        $period = $request->query('period', Jalali::currentPeriod());

        $discounts = Discount::where('period', $period)->with('unit')->get();

        return response()->json([
            'period' => $period,
            'periodLabel' => Jalali::periodLabel($period),
            'periods' => collect(range(-6, 1))
                ->map(fn ($i) => Jalali::shiftPeriod(Jalali::currentPeriod(), $i))
                ->map(fn ($p) => ['value' => $p, 'label' => Jalali::periodLabel($p)])
                ->values(),
            'currency' => $complex->currencyLabel(),
            'total' => (float) $discounts->sum('amount'),
            'data' => $discounts->map(fn (Discount $d) => [
                'id' => $d->id,
                'unitId' => $d->unit_id,
                'unitLabel' => $d->unit ? 'واحد '.$d->unit->unit_number : '—',
                'amount' => (float) $d->amount,
                'reason' => $d->reason,
            ])->values(),
            'units' => Unit::orderBy('unit_number')->get(['id', 'unit_number'])
                ->map(fn (Unit $u) => ['value' => $u->id, 'label' => 'واحد '.$u->unit_number])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireComplex();

        $data = $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'period' => ['required', 'string', 'max:7'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:150'],
        ], [], ['unit_id' => 'واحد', 'amount' => 'مبلغ تخفیف', 'period' => 'دوره']);

        // هر واحد در هر دوره فقط یک تخفیف دارد؛ ثبت دوباره جایگزینش می‌کند.
        Discount::updateOrCreate(
            ['unit_id' => $data['unit_id'], 'period' => $data['period']],
            [
                'amount' => $data['amount'],
                'reason' => $data['reason'] ?? null,
                'created_by' => Auth::id(),
            ],
        );

        return response()->json([
            'message' => 'تخفیف ثبت شد. برای اعمال، قبوض این دوره را دوباره صادر کنید.',
        ], 201);
    }

    public function destroy(Discount $discount): JsonResponse
    {
        $discount->delete();

        return response()->json(['message' => 'تخفیف حذف شد.']);
    }
}
