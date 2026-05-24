<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Unit;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DiscountController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->query('period', Jalali::currentPeriod());

        return view('admin.discounts.index', [
            'period' => $period,
            'discounts' => Discount::where('period', $period)->with('unit')->get(),
            'units' => Unit::orderBy('unit_number')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'period' => ['required', 'string', 'max:7'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:150'],
        ], [], ['unit_id' => 'واحد', 'amount' => 'مبلغ تخفیف']);

        Discount::updateOrCreate(
            ['unit_id' => $data['unit_id'], 'period' => $data['period']],
            ['amount' => $data['amount'], 'reason' => $data['reason'] ?? null, 'created_by' => Auth::id()]
        );

        return back()->with('success', 'تخفیف ثبت شد. برای اعمال، قبوض این دوره را دوباره صادر کنید.');
    }

    public function destroy(Discount $discount)
    {
        $discount->delete();

        return back()->with('success', 'تخفیف حذف شد.');
    }
}
