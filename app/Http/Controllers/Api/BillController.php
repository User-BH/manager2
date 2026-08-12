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
    /**
     * سقفِ ردیف در یک پاسخ.
     *
     * بزرگ‌ترین مجتمعی که سراغ داریم چند صد واحد است؛ این عدد جا برای رشد
     * می‌گذارد و در عین حال جلوی پاسخِ چند مگابایتی را می‌گیرد.
     *
     * از پیکربندی خوانده می‌شود و نه ثابتِ کلاس، تا آزمون بتواند حالتِ
     * بریده‌شدن را با چند ردیف بسازد. بدونِ این، تنها راهِ سنجیدنِ
     * محافظِ «جمع‌ها از SQL می‌آید» ساختنِ هزار قبض بود — و محافظی که
     * آزمودنش گران باشد، عملاً آزموده نمی‌شود.
     */
    private function maxRows(): int
    {
        return (int) config('app.bills_max_rows', 1000);
    }

    public function index(Request $request): JsonResponse
    {
        $complex = $this->requireComplex();
        $period = $request->query('period', Jalali::currentPeriod());

        /*
         * فهرستِ قبض‌ها **صفحه‌بندی نمی‌شود** — و این عمدی است (R30).
         *
         * مدیر این جدول را برای اسکن‌کردن باز می‌کند («کدام واحدها هنوز
         * نداده‌اند؟»)، نه برای خواندنِ یک ردیف. با صفحه‌بندی باید بین
         * صفحه‌ها بپرد و جمع‌ها را در ذهنش نگه دارد.
         *
         * در عوض، جمع‌ها همین‌جا در SQL حساب می‌شوند و رابط ردیف‌ها را
         * **مجازی** رندر می‌کند (`useVirtualRows`)، پس مجتمعِ ۵۰۰ واحدی هم
         * فقط ~۲۰ ردیفِ DOM دارد. سقفِ صریح هم هست تا اگر روزی مجتمعی از
         * این بزرگ‌تر شد، پاسخ بی‌سروصدا غول نشود.
         */
        $bills = Bill::where('period', $period)
            ->with('unit')
            ->join('units', 'bills.unit_id', '=', 'units.id')
            ->orderBy('units.unit_number')
            ->select('bills.*')
            ->limit($this->maxRows())
            ->get();

        // جمع‌ها از کلِ دوره‌اند و نه از ردیف‌های برگشته، وگرنه با رسیدن به
        // سقف، مبلغِ کل بی‌سروصدا کمتر از واقعیت نشان داده می‌شد.
        $totals = Bill::where('period', $period)
            ->selectRaw('coalesce(sum(total_amount), 0) as total, coalesce(sum(paid_amount), 0) as collected')
            // آرایه و نه مدل: این ردیف یک قبضِ واقعی نیست و ستون‌هایش هم
            // ستون‌های `Bill` نیستند، پس تظاهر به مدل‌بودن فقط تحلیل را گمراه می‌کند
            ->toBase()
            ->first();

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
            'total' => (float) ($totals->total ?? 0),
            'collected' => (float) ($totals->collected ?? 0),
            'count' => $bills->count(),
            'truncated' => $bills->count() >= $this->maxRows(),
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
