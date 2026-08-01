<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPaymentReceiptRequest;
use App\Models\Bill;
use App\Models\Payment;
use App\Services\Payment\GatewayManager;
use App\Support\Jalali;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * صفحه‌ی پرداخت یک قبض.
 *
 * شروع پرداخت آنلاین اینجا نیست: آن باید مرورگر را به سایت بانک ببرد، پس
 * یک فرم معمولی به روت وب می‌فرستد (routes/web.php). اینجا فقط اطلاعات
 * صفحه و آپلود رسید است.
 */
class PaymentController extends Controller
{
    public function __construct(protected GatewayManager $gateways) {}

    public function show(Bill $bill): JsonResponse
    {
        $this->authorizeBill($bill);
        $bill->load('unit');

        return response()->json([
            'bill' => [
                'id' => $bill->id,
                'unitLabel' => $bill->unit?->label() ?? '—',
                'periodLabel' => Jalali::periodLabel($bill->period),
                'totalAmount' => (float) $bill->total_amount,
                'paidAmount' => (float) $bill->paid_amount,
                'remaining' => (float) $bill->remaining(),
                'statusLabel' => $bill->status->label(),
                'dueDate' => $bill->due_date ? Jalali::date($bill->due_date) : null,
            ],
            'currency' => $bill->complex?->currencyLabel() ?? 'تومان',
            'onlineEnabled' => $this->gateways->isOnlineEnabled($bill->complex),
            // فرم پرداخت آنلاین باید به این مسیر POST شود تا مرورگر به درگاه برود
            'onlineAction' => route('payments.online', $bill),
        ]);
    }

    public function uploadReceipt(UploadPaymentReceiptRequest $request, Bill $bill): JsonResponse
    {
        $this->authorizeBill($bill);

        /*
         * سقف مبلغ: کمی بیشتر از مانده‌ی قبض اجازه داده می‌شود (برای سرراست
         * کردن مبلغ یا کارمزد)، ولی نه هر عددی. پیش از این هیچ سقفی نبود و
         * ساکن می‌توانست رسیدی با مبلغ نجومی ثبت کند که تاییدِ سهویِ آن،
         * مانده‌ی واحد را منفی و اعتبار ساختگی ایجاد می‌کرد.
         */
        $maxAmount = max(1000, (int) ceil($bill->remaining() * 1.2));

        $data = $request->validated();

        /*
         * دیسک local خصوصی است؛ فایل فقط از مسیر کنترل‌شده‌ی بررسی پرداخت‌ها
         * سرو می‌شود، نه مستقیم از public.
         *
         * `keepIf` فایل را فقط وقتی نگه می‌دارد که تراکنش موفق شود (R19).
         * پیش از این، ارسالِ دوباره برای یک قبض ۴۲۲ می‌گرفت ولی فایلِ ۴
         * مگابایتی‌اش برای همیشه روی دیسک می‌ماند بی‌آنکه هیچ ردیفی به آن
         * اشاره کند — و با سقفِ ۲۰ آپلود در ساعت، ساعتی ۸۰ مگابایت زباله.
         */
        Uploads::keepIf(
            $request->file('receipt'),
            'receipts/'.$bill->complex_id,
            function (string $path) use ($bill, $data, $request): void {
                /*
                 * بررسیِ «رسیدِ در انتظار» و ساختِ رسید باید **اتمیک** باشند.
                 *
                 * پیش از این، `exists()` بیرونِ تراکنش صدا زده می‌شد: دو ارسالِ
                 * هم‌زمان (دوبار زدنِ دکمه، یا یک درخواستِ تکرارشده) هر دو
                 * «رسیدی در انتظار نیست» می‌دیدند و هر دو رسید می‌ساختند —
                 * الگوی کلاسیکِ «بررسی کن، بعد عمل کن».
                 *
                 * با قفلِ ردیفِ قبض، دو درخواست پشتِ سرِ هم می‌شوند و دومی
                 * رسیدِ اولی را می‌بیند. قفل روی خودِ قبض است و نه پرداخت‌ها،
                 * چون قبض ردیفی است که قطعاً وجود دارد؛ روی چیزی که هنوز ساخته
                 * نشده نمی‌شود قفل گرفت.
                 */
                DB::transaction(function () use ($bill, $data, $request, $path): void {
                    Bill::withoutGlobalScopes()->lockForUpdate()->find($bill->id);

                    $alreadyPending = Payment::where('bill_id', $bill->id)
                        ->where('status', PaymentStatus::Pending)
                        ->where('method', PaymentMethod::Receipt)
                        ->exists();

                    if ($alreadyPending) {
                        throw DomainException::invalid(
                            'برای این قبض یک رسید در انتظار بررسی دارید.',
                            'payment.pending_exists',
                        );
                    }

                    Payment::create([
                        'complex_id' => $bill->complex_id,
                        'unit_id' => $bill->unit_id,
                        'bill_id' => $bill->id,
                        'user_id' => Auth::id(),
                        'amount' => $data['amount'],
                        'method' => PaymentMethod::Receipt,
                        'status' => PaymentStatus::Pending,
                        'period' => $bill->period,
                        'receipt_path' => $path,
                        'receipt_original_name' => Uploads::safeOriginalName($request->file('receipt')),
                        'receipt_paid_on' => $data['paid_on'] ?? now(),
                        'description' => $data['description'] ?? null,
                    ]);
                }, attempts: 3);
            },
        );

        return response()->json([
            'message' => 'رسید پرداخت ثبت شد و در انتظار تایید مدیر است.',
        ], 201);
    }

    private function authorizeBill(Bill $bill): void
    {
        $unitIds = Auth::user()->currentUnits()->pluck('units.id');

        $this->authorize('pay', $bill);
    }
}
