<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildBillsBundleJob;
use App\Models\GeneratedDocument;
use App\Models\Unit;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * سندهای تولیدشده در صف (R28).
 *
 * فقط یک نوع دارد («همه‌ی قبض‌های دوره») و عمداً عمومی نوشته شده: انواعِ
 * بعدی فقط یک `type` تازه و یک Job می‌خواهند، نه جدول و مسیرِ تازه.
 */
class DocumentController extends Controller
{
    public function index(): JsonResponse
    {
        $this->requireComplex();
        $this->authorize('export', Unit::class);

        return response()->json([
            'documents' => GeneratedDocument::latest('id')
                ->limit(20)
                ->get()
                ->map(fn (GeneratedDocument $doc) => $this->present($doc))
                ->all(),
        ]);
    }

    /**
     * درخواستِ ساختِ دسته‌ی قبض‌ها.
     *
     * پاسخ بی‌درنگ برمی‌گردد و ردیف با وضعیتِ `pending` ساخته می‌شود؛
     * کاربر همان لحظه می‌بیند که کار شروع شده. همان الگوی بکاپ (R13).
     */
    public function billsBundle(Request $request): JsonResponse
    {
        $complex = $this->requireComplex();
        $this->authorize('export', Unit::class);

        $period = (string) $request->input('period', Jalali::currentPeriod());

        $document = GeneratedDocument::create([
            'complex_id' => $complex->id,
            'user_id' => Auth::id(),
            'type' => 'bills_bundle',
            'title' => 'قبض‌های '.Jalali::periodLabel($period),
            'params' => ['period' => $period],
            'status' => GeneratedDocument::PENDING,
        ]);

        BuildBillsBundleJob::dispatch($document->id);

        return response()->json(['document' => $this->present($document)], 202);
    }

    /** @return array<string, mixed> */
    private function present(GeneratedDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'title' => $doc->title,
            'status' => $doc->status,
            'isReady' => $doc->isReady(),
            'sizeKb' => $doc->size_bytes ? (int) round($doc->size_bytes / 1024) : null,
            'error' => $doc->error,
            'createdAt' => Jalali::dateTime($doc->created_at),
            // لینکِ دانلود فقط وقتی می‌آید که فایل واقعاً روی دیسک باشد
            'url' => $doc->isReady() ? route('reports.bills-bundle', $doc) : null,
        ];
    }
}
