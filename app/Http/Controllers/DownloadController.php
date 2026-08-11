<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Exports\BillsExport;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\GeneratedDocument;
use App\Models\Payment;
use App\Models\Unit;
use App\Services\ReportService;
use App\Services\Subscription\PlanGate;
use App\Services\Units\TenureService;
use App\Support\Jalali;
use App\Support\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
        $this->authorize('viewStatement', $unit);

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

    /**
     * رسیدِ پرداخت (R28).
     *
     * ─── چرا جدا از فاکتور ─────────────────────────────────────────────────
     * فاکتور می‌گوید «چقدر باید بدهی» و رسید می‌گوید «چقدر دادی و کِی».
     * ساکنی که واحد را تخلیه می‌کند به دومی نیاز دارد، و تا امروز هیچ سندی
     * برایش وجود نداشت.
     */
    public function paymentReceipt(Payment $payment)
    {
        $this->authorize('view', $payment);

        $payment->load(['unit', 'complex', 'user', 'bill']);

        return $this->pdf(
            Pdf::fromView('pdf.receipt', [
                'payment' => $payment,
                'bill' => $payment->bill,
                'currency' => $payment->complex->currencyLabel(),
            ]),
            'receipt-'.$payment->id.'.pdf',
        );
    }

    /** گزارشِ مالیِ یک دوره (R28). */
    public function financialReport(Request $request)
    {
        $complex = $this->requireComplex();
        $this->authorize('export', Unit::class);

        $period = $request->query('period', Jalali::currentPeriod());

        // `ReportService` مجتمع را در سازنده می‌گیرد، پس تزریقِ خودکار ندارد
        $reports = new ReportService($complex);

        return $this->pdf(
            Pdf::fromView('pdf.financial-report', [
                'complex' => $complex,
                'period' => $period,
                /*
                 * اعداد از همان سرویسی می‌آیند که داشبورد می‌خواند. اگر
                 * گزارشِ چاپی محاسبه‌ی دوم داشت، دو عددِ متفاوت روی یک
                 * دوره پیدا می‌شد و هیچ‌کدام قابلِ استناد نبود.
                 */
                'income' => $reports->monthlyIncome($period),
                'expense' => $reports->monthlyExpense($period),
                'fund' => $reports->fundBalance(),
                'totalDebt' => $reports->totalDebt(),
                'debtors' => $reports->debtors(100),
                'expenses' => Expense::where('period', $period)->orderBy('spend_date')->get(),
            ]),
            'financial-report-'.$period.'.pdf',
        );
    }

    /** پرونده‌ی واحد با تاریخچه‌ی مالکیت و سکونت (R28 روی R26). */
    public function unitDossier(Unit $unit, TenureService $tenures)
    {
        $this->authorize('view', $unit);

        return $this->pdf(
            Pdf::fromView('pdf.unit-dossier', [
                'unit' => $unit,
                'complex' => $unit->complex,
                'currency' => $unit->complex->currencyLabel(),
                'tenures' => $tenures->history($unit),
                'bills' => $unit->bills()->orderBy('period')->get(),
            ]),
            'unit-'.$unit->unit_number.'-dossier.pdf',
        );
    }

    /** خروجی Excel قبوض یک دوره — از امکانات پلن پرو. */
    public function billsExport(Request $request, PlanGate $plans)
    {
        $this->authorize('export', Unit::class);
        $complex = $this->requireComplex();

        // خروجی PDF در پلن رایگان آزاد است؛ فقط Excel محدود شده.
        $plans->assertCanExportExcel($complex);

        $period = $request->query('period', Jalali::currentPeriod());

        $bills = Bill::where('period', $period)
            ->with('unit')
            ->join('units', 'bills.unit_id', '=', 'units.id')
            ->orderBy('units.unit_number')
            ->select('bills.*')
            ->get();

        return Excel::download(new BillsExport($bills, $period), 'bills-'.$period.'.xlsx');
    }

    /** دانلودِ سندِ ساخته‌شده در صف (R28). */
    public function billsBundle(GeneratedDocument $document)
    {
        $this->authorize('export', Unit::class);

        // ۴۰۴ و نه ۴۰۳: سندِ ناتمام هنوز فایلی ندارد که سرو شود
        abort_unless($document->isReady(), 404);

        return $this->pdf(
            Storage::disk('local')->get($document->path),
            'bills-'.($document->params['period'] ?? 'bundle').'.pdf',
        );
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

        // «مدیر یا صاحبِ واحد» — دقیقاً همان قاعده‌ی `BillPolicy::view`
        $this->authorize('view', $bill);
    }
}
