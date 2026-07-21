<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
        $this->guard($payment);

        abort_if(
            ! $payment->receipt_path || ! Storage::disk('local')->exists($payment->receipt_path),
            404,
        );

        return Storage::disk('local')->response($payment->receipt_path);
    }

    public function approve(Payment $payment): JsonResponse
    {
        $this->guard($payment);
        abort_unless($payment->status === PaymentStatus::Pending, 422);

        $this->payments->settle($payment, Auth::user(), 'تایید رسید توسط مدیر');

        return response()->json(['message' => 'پرداخت تایید و بدهی واحد تسویه شد.']);
    }

    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $this->guard($payment);
        abort_unless($payment->status === PaymentStatus::Pending, 422);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['note' => 'توضیح']);

        $this->payments->reject($payment, Auth::user(), $data['note'] ?? 'رسید نامعتبر');

        return response()->json(['message' => 'رسید پرداخت رد شد.']);
    }

    private function present(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (float) $payment->amount,
            'method' => $payment->method?->value,
            'methodLabel' => $payment->method?->label(),
            'status' => $payment->status->value,
            'statusLabel' => $payment->status->label(),
            'unitLabel' => $payment->unit ? 'واحد '.$payment->unit->unit_number : '—',
            'payerName' => $payment->user?->name ?? '—',
            'billPeriod' => $payment->bill ? Jalali::periodLabel($payment->bill->period) : null,
            'description' => $payment->description,
            'hasReceipt' => filled($payment->receipt_path),
            'receiptUrl' => filled($payment->receipt_path)
                ? route('api.payments.receipt', $payment)
                : null,
            'createdAt' => Jalali::dateTime($payment->created_at),
            'paidAt' => $payment->paid_at ? Jalali::dateTime($payment->paid_at) : null,
        ];
    }

    /** پرداخت باید متعلق به مجتمع جاری باشد. */
    private function guard(Payment $payment): void
    {
        abort_if($payment->complex_id !== $this->requireComplex()->id, 403);
    }
}
