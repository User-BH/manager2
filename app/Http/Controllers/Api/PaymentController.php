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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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

        // یک قبض هم‌زمان بیش از یک رسیدِ در انتظار بررسی نداشته باشد، وگرنه
        // صف بررسی مدیر با ارسال‌های تکراری پر می‌شود.
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

        // دیسک local خصوصی است؛ فایل فقط از مسیر کنترل‌شده‌ی بررسی پرداخت‌ها
        // سرو می‌شود، نه مستقیم از public.
        $path = $request->file('receipt')->store('receipts/'.$bill->complex_id, 'local');

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
            'receipt_original_name' => $request->file('receipt')->getClientOriginalName(),
            'receipt_paid_on' => $data['paid_on'] ?? now(),
            'description' => $data['description'] ?? null,
        ]);

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
