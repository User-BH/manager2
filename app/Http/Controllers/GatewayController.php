<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Payment;
use App\Services\Payment\GatewayManager;
use App\Services\Payment\GatewayUnreachable;
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

        /*
         * ⚠️ پرداختِ در انتظارِ موجود **دوباره استفاده می‌شود** (R51).
         *
         * ─── نقصی که اندازه‌گیری شد ────────────────────────────────────────
         * پیش از این، هر فراخوانیِ این متد یک ردیفِ `Payment` تازه می‌ساخت.
         * کاربری که دوبار روی «پرداخت آنلاین» می‌زد — یا دکمه‌ی بازگشت
         * مرورگر را می‌زد و دوباره کلیک می‌کرد — دو تراکنشِ بانکیِ مستقل
         * می‌ساخت.
         *
         * قفلِ ردیفیِ R50 اینجا **هیچ محافظتی نمی‌کند**، چون آن دو پرداخت
         * دو ردیفِ متفاوت‌اند و هر callback قفلِ ردیفِ خودش را می‌گیرد.
         *
         * سنجشِ واقعی روی قبضِ ۱۰۰٬۰۰۰ تومانی:
         *
         *     پرداختِ موفق در بانک : ۲۰۰٬۰۰۰
         *     ثبت‌شده روی قبض      : ۱۰۰٬۰۰۰
         *     رفته به کیف پول      : ۰
         *
         * یعنی صد هزار تومان از حسابِ ساکن کم می‌شد و **در هیچ‌جای سامانه
         * ثبت نمی‌شد**.
         *
         * ─── چرا قفل روی قبض ───────────────────────────────────────────────
         * دو کلیکِ هم‌زمان هر دو «پرداختِ در انتظاری نیست» می‌دیدند. قفل روی
         * ردیفِ قبض — که قطعاً وجود دارد — آن‌ها را پشتِ سرِ هم می‌کند؛
         * روی چیزی که هنوز ساخته نشده نمی‌شود قفل گرفت.
         */
        $payment = DB::transaction(function () use ($bill): Payment {
            Bill::withoutGlobalScopes()->lockForUpdate()->find($bill->id);

            $pending = Payment::withoutGlobalScopes()
                ->where('bill_id', $bill->id)
                ->where('method', PaymentMethod::Online)
                ->where('status', PaymentStatus::Pending)
                ->latest('id')
                ->first();

            /*
             * ⚠️ مبلغ دوباره از قبض خوانده می‌شود، نه از پرداختِ قدیمی.
             *
             * بینِ کلیکِ اول و دوم ممکن است بخشی از بدهی از راهِ دیگری
             * (کیف پول، رسیدِ دستی) تسویه شده باشد. استفاده از مبلغِ کهنه
             * یعنی کاربر بیش از بدهیِ واقعی‌اش پرداخت کند.
             */
            if ($pending) {
                $pending->update(['amount' => $bill->remaining()]);

                return $pending;
            }

            return Payment::create([
                'complex_id' => $bill->complex_id,
                'unit_id' => $bill->unit_id,
                'bill_id' => $bill->id,
                'user_id' => Auth::id(),
                'amount' => $bill->remaining(),
                'method' => PaymentMethod::Online,
                'status' => PaymentStatus::Pending,
                'period' => $bill->period,
            ]);
        }, attempts: 3);

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

        /*
         * 🔴 «نمی‌دانم» حالتِ سومی است، نه مترادفِ «ناموفق» (R59).
         *
         * ─── چه چیزی بی‌صدا می‌شکست ────────────────────────────────────────
         * درایورها هر شکستِ شبکه‌ای را به `null` تبدیل می‌کردند و همین چند
         * خطِ پایین، `null` را برای همیشه `Failed` می‌نوشت. یعنی یک timeoutِ
         * سی‌ثانیه‌ایِ گذرا می‌توانست پرداختی را که پولش **واقعاً کسر شده**
         * ناموفق ثبت کند — و پیامِ «اگر مبلغی کسر شده طی ۷۲ ساعت برمی‌گردد»
         * تضمینی بود درباره‌ی چیزی که هرگز نپرسیده بودیم.
         *
         * ─── چرا `pending` می‌ماند و نه `Failed` ────────────────────────────
         * چون هر دو تصمیم ممکن است غلط باشند و **بی‌تصمیمی تنها حالتِ صادق
         * است**. ردیفِ `pending` را `payments:stale` به اپراتور نشان می‌دهد
         * تا در پنلِ بانک تعیینِ تکلیف شود؛ ردیفِ `Failed` را هیچ‌کس دیگر
         * نگاه نمی‌کند.
         */
        try {
            $tracking = $this->gateways->for($payment->complex)->verify($payment, $request->all());
        } catch (GatewayUnreachable $e) {
            $this->recordIndeterminate($payment, $e);

            return redirect('/my-bills?payment=unknown');
        }

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
            /*
             * ⚠️ `attempts: 3` اینجا اجباری است و نبودش یک شکافِ واقعی بود (R50).
             *
             * ─── چرا `attempts` خودِ `settle()` کافی نبود ──────────────────────
             * `PaymentService::settle()` خودش `attempts: 3` دارد، ولی وقتی از
             * اینجا صدا زده می‌شود **تودرتو** اجرا می‌شود — و در تراکنشِ تودرتو
             * آن عدد کاملاً بی‌اثر است.
             *
             * این از سورسِ خودِ لاراول خوانده شد
             * (`ManagesTransactions::handleTransactionException`): وقتی
             * `transactions > 1` باشد و خطای همزمانی رخ دهد، فریم‌ورک بی‌درنگ
             * `DeadlockException` پرتاب می‌کند و **حلقه‌ی تلاشِ دوباره را اجرا
             * نمی‌کند**. کامنتِ خودِ فریم‌ورک هم همین را می‌گوید: استثنا باید تا
             * بیرونی‌ترین لایه برود.
             *
             * ─── چرا این مسیر بدترین جای ممکن برای این شکاف بود ────────────────
             * این پرتراکم‌ترین مسیرِ مالیِ سامانه است: بانک هم‌زمان دو بازگشت
             * می‌فرستد (سرور‌به‌سرور و بازگشتِ مرورگر). با بن‌بستِ MySQL، بازگشتِ
             * بانک **۵۰۰** می‌گرفت و تراکنش برمی‌گشت — یعنی پولِ کاربر کم شده
             * ولی قبض تسویه‌نشده می‌ماند.
             *
             * زنجیره‌ی قفل اینجا `Payment → Bill` است (از راهِ `allocate`) و در
             * `WalletService::payBill` زنجیره `Unit → Bill`. هیچ مسیری
             * `Bill → Unit` یا `Bill → Payment` نمی‌گیرد، پس چرخه‌ی بن‌بست
             * وجود ندارد؛ ولی رقابت روی خودِ `Bill` همچنان ممکن است.
             */
        }, attempts: 3);

        // بازگشت به صفحه‌ی SPA؛ نتیجه از روی پارامترها نمایش داده می‌شود.
        return redirect('/my-bills?payment=success&tracking='.urlencode(
            $settled ? $tracking : (string) $payment->fresh()?->tracking_code,
        ));
    }

    /**
     * ثبتِ اینکه سرنوشتِ این تراکنش **دانسته نشد**.
     *
     * ⚠️ وضعیتِ پرداخت عمداً دست‌نخورده می‌ماند. تنها کاری که اینجا انجام
     * می‌شود گذاشتنِ ردی است که اپراتور بتواند از رویش در پنلِ بانک
     * جست‌وجو کند — شناسه‌ی مرجع، گامی که شکست، و متنِ خطا.
     *
     * بدونِ این رد، پرداختِ `pending` از پرداختِ رهاشده‌ای که کاربر وسطِ
     * راه منصرف شده قابلِ تشخیص نبود.
     */
    private function recordIndeterminate(Payment $payment, GatewayUnreachable $error): void
    {
        AuditLog::create([
            'complex_id' => $payment->complex_id,
            'user_id' => Auth::id(),
            'action' => 'payment.indeterminate',
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
            'description' => 'نتیجه‌ی پرداخت آنلاین از درگاه دریافت نشد',
            'ip_address' => request()->ip(),
            'properties' => [
                'gateway' => $error->gateway,
                'stage' => $error->stage,
                'ref_id' => $payment->ref_id,
                'amount' => $payment->amount,
                'reason' => $error->getMessage(),
            ],
            'created_at' => now(),
        ]);
    }

    /** نتیجه‌ی از پیش ثبت‌شده‌ی یک تراکنش، بدون تماس دوباره با درگاه. */
    private function resultRedirect(Payment $payment)
    {
        return $payment->status === PaymentStatus::Success
            ? redirect('/my-bills?payment=success&tracking='.urlencode((string) $payment->tracking_code))
            : redirect('/my-bills?payment=failed');
    }

    /**
     * قاعده در `BillPolicy::pay` است.
     *
     * عمداً `pay` و نه `view`: مدیر می‌تواند قبض را ببیند ولی نباید بتواند
     * برای واحدِ دیگری پرداخت کند، وگرنه ردِ حسابداری مخدوش می‌شود.
     */
    private function authorizeBill(Bill $bill): void
    {
        $this->authorize('pay', $bill);
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

        $this->authorize('viewReceipt', $payment);
    }
}
