<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use App\Support\PrivateFiles;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReviewController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    public function index(): JsonResponse
    {
        $complex = $this->requireComplex();

        $pending = Payment::where('status', PaymentStatus::Pending)
            ->with(['unit', 'user', 'bill'])
            ->latest()
            ->get();

        $recent = Payment::whereIn('status', [PaymentStatus::Success, PaymentStatus::Rejected])
            ->with(['unit', 'user'])
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'currency' => $complex->currencyLabel(),
            'pending' => $pending->map(fn (Payment $p) => $this->present($p))->values(),
            'recent' => $recent->map(fn (Payment $p) => $this->present($p))->values(),
            'pendingTotal' => (float) $pending->sum('amount'),
        ]);
    }

    /** فایل رسید روی دیسک خصوصی است و فقط از این مسیر سرو می‌شود. */
    public function receipt(Payment $payment): StreamedResponse
    {
        $this->authorize('viewReceipt', $payment);

        abort_if(
            ! $payment->receipt_path || ! PrivateFiles::disk()->exists($payment->receipt_path),
            404,
        );

        // نوعِ محتوا صریح فرستاده می‌شود، نه حدسِ لحظه‌ی سرو (R19)
        return Uploads::serve($payment->receipt_path);
    }

    public function approve(Payment $payment): JsonResponse
    {
        $this->authorize('review', $payment);
        $this->requirePending($payment);

        $this->payments->settle($payment, Auth::user(), 'تایید رسید توسط مدیر');

        return response()->json(['message' => 'پرداخت تایید و بدهی واحد تسویه شد.']);
    }

    public function reject(RejectPaymentRequest $request, Payment $payment): JsonResponse
    {
        $this->authorize('review', $payment);
        $this->requirePending($payment);

        $data = $request->validated();

        $this->payments->reject($payment, Auth::user(), $data['note'] ?? 'رسید نامعتبر');

        return response()->json(['message' => 'رسید پرداخت رد شد.']);
    }

    /**
     * شکلِ خروجی حالا در `PaymentResource` است.
     *
     * این متد یک پلِ کوتاه است تا فراخوانی‌های موجود دست‌نخورده بمانند؛
     * نقطه‌ی حقیقتِ ساختار یکی شد.
     */
    private function present(Payment $payment): array
    {
        return (new PaymentResource($payment))->toArray(request());
    }

    /**
     * تنها قاعده‌ی وضعیتی که چند اکشن مشترکاً دارند.
     *
     * مجوزدهی دیگر اینجا نیست (رفت به `PaymentPolicy`)؛ این فقط قاعده‌ی
     * کسب‌وکار است: رسیدی که یک بار تعیین‌تکلیف شده دوباره بررسی نمی‌شود.
     */
    private function requirePending(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            // ۴۲۲ مثل قبل از بازآرایی
            throw DomainException::invalid(
                'این رسید قبلاً بررسی شده است.',
                'payment.already_reviewed',
            );
        }
    }
}
