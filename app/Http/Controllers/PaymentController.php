<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Payment;
use App\Services\Payment\GatewayManager;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    public function __construct(
        protected GatewayManager $gateways,
        protected PaymentService $payments,
    ) {}

    public function show(Bill $bill)
    {
        $this->authorizeBill($bill);
        $bill->load('unit');
        $onlineEnabled = $this->gateways->isOnlineEnabled($bill->complex);

        return view('payments.show', compact('bill', 'onlineEnabled'));
    }

    public function startOnline(Bill $bill)
    {
        $this->authorizeBill($bill);

        $payment = Payment::create([
            'complex_id' => $bill->complex_id,
            'unit_id' => $bill->unit_id,
            'bill_id' => $bill->id,
            'user_id' => Auth::id(),
            'amount' => $bill->remaining(),
            'method' => PaymentMethod::Online,
            'status' => PaymentStatus::Pending,
            'period' => $bill->period,
        ]);

        try {
            $redirect = $this->gateways->for($bill->complex)->request($payment);
        } catch (\Throwable $e) {
            $payment->update(['status' => PaymentStatus::Failed]);

            return redirect()->route('payments.show', $bill)->with('error', $e->getMessage());
        }

        // Some gateways (Mellat, Saman) require an auto-submitted POST form.
        if (($redirect['method'] ?? 'GET') === 'POST') {
            return view('payments.redirect', [
                'action' => $redirect['redirect_url'],
                'fields' => $redirect['fields'] ?? [],
            ]);
        }

        return redirect()->away($redirect['redirect_url']);
    }

    public function callback(Request $request, Payment $payment)
    {
        $this->authorizePayment($payment);

        $tracking = $this->gateways->for($payment->complex)->verify($payment, $request->all());

        if ($tracking) {
            $payment->update(['tracking_code' => $tracking]);
            $this->payments->settle($payment);

            return redirect()->route('bills.show', $payment->bill_id)
                ->with('success', 'پرداخت با موفقیت انجام شد. کد رهگیری: '.$tracking);
        }

        $payment->update(['status' => PaymentStatus::Failed]);

        return redirect()->route('bills.show', $payment->bill_id)
            ->with('error', 'پرداخت ناموفق بود. لطفا دوباره تلاش کنید.');
    }

    public function uploadReceipt(Request $request, Bill $bill)
    {
        $this->authorizeBill($bill);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000'],
            'paid_on' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ], [], [
            'amount' => 'مبلغ',
            'receipt' => 'فایل رسید',
        ]);

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

        return redirect()->route('bills.show', $bill)
            ->with('success', 'رسید پرداخت ثبت شد و در انتظار تایید مدیر است.');
    }

    protected function authorizeBill(Bill $bill): void
    {
        $unitIds = Auth::user()->currentUnits()->pluck('units.id');
        abort_unless($unitIds->contains($bill->unit_id), 403);
    }

    protected function authorizePayment(Payment $payment): void
    {
        abort_unless($payment->user_id === Auth::id() || Auth::user()->isAdmin(), 403);
    }
}
