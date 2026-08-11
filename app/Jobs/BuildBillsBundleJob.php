<?php

namespace App\Jobs;

use App\Models\Bill;
use App\Models\GeneratedDocument;
use App\Support\Pdf;
use Illuminate\Support\Facades\Storage;
use Mpdf\Output\Destination;
use Throwable;

/**
 * ساختِ «همه‌ی قبض‌های یک دوره در یک PDF»، بیرون از چرخه‌ی درخواست (R28).
 *
 * ─── چرا این یکی واقعاً باید در صف باشد ────────────────────────────────────
 * PDFِ تکیِ یک قبض میلی‌ثانیه‌ای ساخته می‌شود. ولی مجتمعِ ۲۰۰ واحدی یعنی
 * ۲۰۰ رندرِ Blade و یک سندِ ۲۰۰ صفحه‌ای؛ روی داده‌ی واقعی این ده‌ها ثانیه
 * است و در چرخه‌ی درخواست یا `max_execution_time` می‌خورد یا سقفِ حافظه.
 *
 * ─── چرا تک‌تک `WriteHTML` و نه یک رشته‌ی بزرگ ──────────────────────────────
 * چسباندنِ ۲۰۰ سندِ HTML به هم و یک‌بار دادن به mPDF، همه‌ی آن رشته را
 * هم‌زمان در حافظه نگه می‌دارد. با نوشتنِ صفحه‌به‌صفحه، حافظه به اندازه‌ی
 * یک قبض می‌ماند و نه کلِ دوره.
 */
class BuildBillsBundleJob extends BaseJob
{
    /** سندِ چندصد صفحه‌ای وقت می‌خواهد؛ سقفِ پیش‌فرضِ ۳۰۰ ثانیه کم است. */
    public int $timeout = 900;

    public function __construct(public readonly int $documentId) {}

    public function handle(): void
    {
        $document = GeneratedDocument::withoutGlobalScopes()->find($this->documentId);

        // رکورد بین صف‌شدن و اجرا حذف شده — کارِ بی‌صاحب انجام نمی‌دهیم
        if (! $document || $document->status === GeneratedDocument::READY) {
            return;
        }

        $period = (string) ($document->params['period'] ?? '');

        $bills = Bill::withoutGlobalScopes()
            // ستون‌ها پس از join باید نام‌دار باشند، وگرنه «ambiguous column»
            ->where('bills.complex_id', $document->complex_id)
            ->where('bills.period', $period)
            ->with(['unit', 'complex'])
            ->join('units', 'bills.unit_id', '=', 'units.id')
            ->orderBy('units.unit_number')
            ->select('bills.*')
            ->get();

        $pdf = Pdf::make();

        foreach ($bills as $index => $bill) {
            // صفحه‌ی تازه برای هر قبض، جز اولی
            if ($index > 0) {
                $pdf->AddPage();
            }

            $pdf->WriteHTML(view('pdf.invoice', ['bill' => $bill])->render());
        }

        $path = 'documents/'.$document->complex_id.'/bills-'.$period.'-'.$document->id.'.pdf';

        Storage::disk('local')->put($path, $pdf->Output('', Destination::STRING_RETURN));

        $document->update([
            'status' => GeneratedDocument::READY,
            'path' => $path,
            'size_bytes' => Storage::disk('local')->size($path),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        parent::failed($exception);

        /*
         * وضعیت باید `failed` شود، وگرنه ردیف تا ابد `pending` می‌ماند و
         * کاربر منتظرِ سندی می‌نشیند که هرگز نمی‌آید.
         */
        GeneratedDocument::withoutGlobalScopes()
            ->where('id', $this->documentId)
            ->update([
                'status' => GeneratedDocument::FAILED,
                'error' => $exception?->getMessage(),
            ]);
    }
}
