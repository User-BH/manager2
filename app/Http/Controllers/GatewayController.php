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

/**
 * پل بین اپلیکیشن و درگاه بانکی.
 *
 * این دو مسیر عمداً JSON نیستند و بخشی از SPA نمی‌شوند: شروع پرداخت باید
 * مرورگر را واقعاً به سایت بانک ببرد، و بازگشت از بانک یک درخواست از دامنه‌ی
 * دیگر است که سمت سرور باید پاسخ داده شود.
 */
class GatewayController extends Controller
{
    public function __construct(
        protected GatewayManager $gateways,
        protected PaymentService $payments,
    ) {}

    public function start(Bill $bill)
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

            return redirect('/pay/'.$bill->id.'?error='.urlencode($e->getMessage()));
        }

        // ملت و سامان به فرم POST خودکار نیاز دارند، نه ریدایرکت ساده.
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

            // بازگشت به صفحه‌ی SPA؛ نتیجه از روی پارامترها نمایش داده می‌شود.
            return redirect('/my-bills?payment=success&tracking='.urlencode($tracking));
        }

        $payment->update(['status' => PaymentStatus::Failed]);

        return redirect('/my-bills?payment=failed');
    }

    private function authorizeBill(Bill $bill): void
    {
        $unitIds = Auth::user()->currentUnits()->pluck('units.id');

        abort_unless($unitIds->contains($bill->unit_id), 403);
    }

    private function authorizePayment(Payment $payment): void
    {
        abort_unless($payment->user_id === Auth::id() || Auth::user()->isAdmin(), 403);
    }
}
