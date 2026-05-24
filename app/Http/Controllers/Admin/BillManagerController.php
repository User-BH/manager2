<?php

namespace App\Http\Controllers\Admin;

use App\Exports\BillsExport;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Services\Charge\BillGenerator;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class BillManagerController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->query('period', Jalali::currentPeriod());

        $bills = Bill::where('period', $period)
            ->with('unit')
            ->join('units', 'bills.unit_id', '=', 'units.id')
            ->orderBy('units.unit_number')
            ->select('bills.*')
            ->get();

        // Build a small period picker spanning recent months.
        $periods = collect(range(-6, 1))
            ->map(fn ($i) => Jalali::shiftPeriod(Jalali::currentPeriod(), $i))
            ->mapWithKeys(fn ($p) => [$p => Jalali::periodLabel($p)]);

        return view('admin.bills.index', [
            'period' => $period,
            'bills' => $bills,
            'periods' => $periods,
            'total' => $bills->sum('total_amount'),
            'collected' => $bills->sum('paid_amount'),
        ]);
    }

    public function export(Request $request)
    {
        $period = $request->query('period', Jalali::currentPeriod());

        $bills = Bill::where('period', $period)
            ->with('unit')
            ->join('units', 'bills.unit_id', '=', 'units.id')
            ->orderBy('units.unit_number')
            ->select('bills.*')
            ->get();

        return Excel::download(new BillsExport($bills, $period), 'bills-'.$period.'.xlsx');
    }

    public function generate(Request $request, BillGenerator $generator)
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'max:7'],
        ]);

        $result = $generator->generate($this->requireComplex(), $data['period']);

        return redirect()
            ->route('admin.bills.index', ['period' => $data['period']])
            ->with('success', 'تعداد '.Jalali::digits($result['bills']).' قبض برای دوره '.Jalali::periodLabel($data['period']).' صادر/به‌روزرسانی شد.');
    }
}
