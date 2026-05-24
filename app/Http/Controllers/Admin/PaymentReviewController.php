<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentReviewController extends Controller
{
    public function __construct(protected PaymentService $payments) {}

    public function index()
    {
        $pending = Payment::where('status', PaymentStatus::Pending)
            ->with(['unit', 'user', 'bill'])
            ->latest()
            ->get();

        $recent = Payment::whereIn('status', [PaymentStatus::Success, PaymentStatus::Rejected])
            ->with(['unit', 'user'])
            ->latest()
            ->limit(20)
            ->get();

        return view('admin.payments.index', compact('pending', 'recent'));
    }

    public function receipt(Payment $payment)
    {
        abort_if(! $payment->receipt_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($payment->receipt_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($payment->receipt_path);
    }

    public function approve(Payment $payment)
    {
        abort_unless($payment->status === PaymentStatus::Pending, 422);

        $this->payments->settle($payment, Auth::user(), 'تایید رسید توسط مدیر');

        return back()->with('success', 'پرداخت تایید و بدهی واحد تسویه شد.');
    }

    public function reject(Request $request, Payment $payment)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $this->payments->reject($payment, Auth::user(), $data['note'] ?? 'رسید نامعتبر');

        return back()->with('success', 'رسید پرداخت رد شد.');
    }
}
