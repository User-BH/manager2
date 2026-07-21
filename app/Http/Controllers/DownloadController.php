<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Exports\BillsExport;
use App\Models\Bill;
use App\Models\Unit;
use App\Support\Jalali;
use App\Support\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

/**
 * فایل‌های خروجی: PDF و Excel.
 *
 * این‌ها بخشی از API جی‌سون نیستند چون مرورگر باید مستقیم بازشان کند؛ لینک
 * ساده در SPA به همین مسیرها اشاره می‌کند و نشست وب احراز هویت را انجام
 * می‌دهد.
 */
class DownloadController extends Controller
{
    /** فاکتور شارژ یک قبض. */
    public function billInvoice(Bill $bill)
    {
        $this->authorizeBill($bill);
        $bill->load('unit', 'complex');

        return $this->pdf(
            Pdf::fromView('pdf.invoice', ['bill' => $bill]),
            'invoice-'.$bill->unit->unit_number.'-'.$bill->period.'.pdf',
        );
    }

    /** تسویه‌حساب یک واحد، برای زمان تخلیه یا فروش. */
    public function unitStatement(Unit $unit)
    {
        abort_unless(Auth::user()->isAdmin(), 403);
        abort_if($unit->complex_id !== $this->requireComplex()->id, 403);

        $unit->load(['bills' => fn ($q) => $q->orderBy('period'), 'owners', 'tenants']);

        $data = [
            'unit' => $unit,
            'complex' => $unit->complex,
            'bills' => $unit->bills,
            'payments' => $unit->payments()->where('status', PaymentStatus::Success)->latest()->get(),
            'totalDebt' => (float) $unit->bills->sum(fn (Bill $b) => $b->remaining()),
        ];

        return $this->pdf(
            Pdf::fromView('pdf.statement', $data),
            'statement-'.$unit->unit_number.'.pdf',
        );
    }

    /** خروجی Excel قبوض یک دوره. */
    public function billsExport(Request $request)
    {
        abort_unless(Auth::user()->isAdmin(), 403);
        $this->requireComplex();

        $period = $request->query('period', Jalali::currentPeriod());

        $bills = Bill::where('period', $period)
            ->with('unit')
            ->join('units', 'bills.unit_id', '=', 'units.id')
            ->orderBy('units.unit_number')
            ->select('bills.*')
            ->get();

        return Excel::download(new BillsExport($bills, $period), 'bills-'.$period.'.xlsx');
    }

    private function pdf(string $content, string $filename)
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** قبض باید متعلق به کاربر باشد، مگر اینکه مدیر باشد. */
    private function authorizeBill(Bill $bill): void
    {
        $user = Auth::user();

        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            $user->currentUnits()->pluck('units.id')->contains($bill->unit_id),
            403,
        );
    }
}
