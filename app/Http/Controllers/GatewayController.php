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
use Illuminate\Support\Facades\DB;

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

        /*
         * تراکنشی که تکلیفش روشن شده، دوباره تایید نمی‌شود.
         *
         * این مسیر GET است، پس رفرش صفحه یا دکمه‌ی back دوباره اجرایش می‌کند
         * (بعضی درگاه‌ها هم بازگشت را دوبار می‌فرستند). پیش از این، تلاش دوم با
         * درگاه واقعی چون تراکنش نزد بانک قبلاً verify شده بود کد خطا می‌گرفت و
         * کد ما پرداختِ موفق را «ناموفق» علامت می‌زد: پول کم شده، قبض تسویه، و
         * کاربر پیام شکست می‌دید.
         */
        if ($payment->status !== PaymentStatus::Pending) {
            return $this->resultRedirect($payment);
        }

        $tracking = $this->gateways->for($payment->complex)->verify($payment, $request->all());

        if (! $tracking) {
            // فقط تراکنشِ هنوز-در-انتظار را ناموفق کن؛ اگر درخواست موازی
            // زودتر تسویه‌اش کرده، دست نزن.
            Payment::withoutGlobalScopes()
                ->whereKey($payment->id)
                ->where('status', PaymentStatus::Pending)
                ->update(['status' => PaymentStatus::Failed]);

            return redirect('/my-bills?payment=failed');
        }

        /*
         * تسویه پشت قفل ردیف، تا دو بازگشتِ هم‌زمان (مثلاً فراخوانی سرور-به-سرور
         * بانک و بازگشت مرورگر) مبلغ را دوبار روی قبوض واحد ننشانند. تایید خودِ
         * درگاه عمداً بیرون از قفل انجام می‌شود تا تراکنش دیتابیس پشت یک تماس
         * شبکه‌ای باز نماند.
         */
        $settled = DB::transaction(function () use ($payment, $tracking): bool {
            $fresh = Payment::withoutGlobalScopes()->lockForUpdate()->find($payment->id);

            if (! $fresh || $fresh->status !== PaymentStatus::Pending) {
                return false;
            }

            $fresh->update(['tracking_code' => $tracking]);
            $this->payments->settle($fresh);

            return true;
        });

        // بازگشت به صفحه‌ی SPA؛ نتیجه از روی پارامترها نمایش داده می‌شود.
        return redirect('/my-bills?payment=success&tracking='.urlencode(
            $settled ? $tracking : (string) $payment->fresh()?->tracking_code,
        ));
    }

    /** نتیجه‌ی از پیش ثبت‌شده‌ی یک تراکنش، بدون تماس دوباره با درگاه. */
    private function resultRedirect(Payment $payment)
    {
        return $payment->status === PaymentStatus::Success
            ? redirect('/my-bills?payment=success&tracking='.urlencode((string) $payment->tracking_code))
            : redirect('/my-bills?payment=failed');
    }

    private function authorizeBill(Bill $bill): void
    {
        $unitIds = Auth::user()->currentUnits()->pluck('units.id');

        abort_unless($unitIds->contains($bill->unit_id), 403);
    }

    /**
     * بازگشت از بانک، برخلاف بقیه‌ی مسیرها، ممکن است نشست نداشته باشد.
     *
     * نشست کاربر ممکن است تا لحظه‌ی بازگشت منقضی شده باشد، یا بانک خودش
     * سرور-به-سرور صدا بزند. پیش از این میدل‌ور `auth` چنین درخواستی را به
     * صفحه‌ی ورود می‌فرستاد و تراکنش هرگز تایید نمی‌شد — یعنی پول از حساب
     * کاربر کم شده بود و قبض پرداخت‌نشده می‌ماند.
     *
     * اعتبار این درخواست را تاییدیه‌ی خود درگاه تعیین می‌کند (ملت با
     * `bpVerifyRequest` و اعتبارنامه‌ی ترمینال، سامان با توکن)، نه کوکی
     * مرورگر؛ پس نبودِ نشست مانع نمی‌شود. ولی اگر نشستی هست، باید متعلق به
     * همان کاربر یا یک مدیر باشد.
     */
    private function authorizePayment(Payment $payment): void
    {
        if (! Auth::check()) {
            return;
        }

        abort_unless($payment->user_id === Auth::id() || Auth::user()->isAdmin(), 403);
    }
}
