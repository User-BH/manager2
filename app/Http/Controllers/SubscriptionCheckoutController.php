<?php

namespace App\Http\Controllers;

use App\Enums\ComplexType;
use App\Exceptions\DomainException;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Requests\StartCheckoutRequest;
use App\Models\AuditLog;
use App\Models\CheckoutIntent;
use App\Models\Subscription;
use App\Services\Payment\GatewayUnreachable;
use App\Services\Payment\TrackingCodes;
use App\Services\Subscription\ComplexPurchase;
use App\Services\Subscription\SubscriptionGatewayManager;
use App\Services\Subscription\SubscriptionRenewal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * رفت‌وبرگشت بانکی خرید اشتراک.
 *
 * مثل GatewayController عمداً JSON نیست: شروع پرداخت باید مرورگر را واقعاً
 * از سایت ما بیرون ببرد، و بازگشت از دامنه‌ی بانک می‌آید.
 */
class SubscriptionCheckoutController extends Controller
{
    public function __construct(
        protected SubscriptionGatewayManager $gateways,
        protected ComplexPurchase $purchase,
        protected SubscriptionRenewal $renewal,
    ) {}

    public function start(StartCheckoutRequest $request)
    {
        $user = Auth::user();

        /*
         * مجتمعِ تازه (مرحلهٔ ۹ · فاز ۴؛ از ۱۰/۵ برای عضوِ مجتمع‌های دیگر هم) — با قصدِ خرید، نه
         * اشتراکِ بی‌مجتمع. مجوزش جداست: هر حسابِ فعالِ غیرِ سکو.
         */
        if ($request->wantsNewComplex()) {
            $this->authorize('purchaseComplex', Subscription::class);

            return $this->startNewComplex($request);
        }

        // تمدیدِ اشتراکِ مجتمعِ فعال، پس فقط مدیرِ همان مجتمع؛
        // ساکن نباید بتواند پرداختی انجام دهد که هیچ اثری برایش ندارد.
        $this->authorize('purchase', Subscription::class);

        $request->validated();

        $complex = $this->requireComplex();

        // درگاهِ خاموش پیش از ساختنِ ردیف گفته می‌شود — راهِ دیگری (رسید) دیگر نیست (۱۲/۲)
        if (! $this->gateways->isEnabled()) {
            return redirect('/account?checkout=error&message='.urlencode('پرداختِ آنلاینِ اشتراک فعلاً در دسترس نیست.'));
        }

        // مبلغ و مدت سمتِ سرور، از پلن (`SubscriptionRenewal::resolvePlan`)
        try {
            $subscription = $this->renewal->begin($complex, $user, $request->string('plan')->value());
        } catch (DomainException $e) {
            return redirect('/account?checkout=error&message='.urlencode($e->getMessage()));
        }

        try {
            $redirect = $this->gateways->driver()->request($subscription);
        } catch (\Throwable $e) {
            $this->renewal->fail($subscription, 'درخواستِ درگاه ناموفق بود');

            return redirect('/account?checkout=error&message='.urlencode($e->getMessage()));
        }

        return $this->toGateway($redirect);
    }

    public function callback(Request $request, Subscription $subscription)
    {
        /*
         * مثل بازگشت پرداخت قبض، این درخواست ممکن است بدون نشست برسد (انقضای
         * نشست تا لحظه‌ی بازگشت از بانک). اعتبارش را تاییدیه‌ی درگاه تعیین
         * می‌کند نه کوکی؛ ولی اگر نشستی هست باید خودِ خریدار باشد.
         */
        /*
         * عمداً Policy نشد: `authorize()` همیشه کاربرِ واردشده می‌خواهد، ولی
         * این مسیر باید **بدونِ نشست هم** کار کند (بازگشت از بانک پس از انقضای
         * نشست). قاعده اینجا شرطی است: «اگر نشستی هست، باید خودِ خریدار باشد».
         */
        $this->ensureBuyerOrBank($subscription->user_id);

        // تراکنشی که تکلیفش روشن شده دوباره تایید نمی‌شود: بازگشت دوباره نباید
        // دوره را تمدید کند و نباید اشتراکِ فعال را «ناموفق» علامت بزند.
        if ($subscription->status !== 'pending') {
            return $subscription->status === 'active'
                ? redirect('/account?checkout=success&tracking='.urlencode((string) $subscription->tracking_code))
                : redirect('/account?checkout=failed');
        }

        /*
         * 🔴 همان شکافِ مسیرِ قبض، اینجا هم بود (R59).
         *
         * شکستِ شبکه‌ای `null` می‌شد و اشتراک برای همیشه `failed` — برای
         * خریدی که وضعیتش را اصلاً نپرسیده بودیم. `pending` ماندن تنها
         * پاسخِ صادق است؛ `payments:stale` همین ردیف را نشان می‌دهد.
         */
        try {
            $tracking = $this->gateways->driver()->verify($subscription, $request->all());
        } catch (GatewayUnreachable $e) {
            AuditLog::create([
                'complex_id' => $subscription->complex_id,
                'user_id' => Auth::id(),
                'action' => 'subscription.indeterminate',
                'subject_type' => Subscription::class,
                'subject_id' => $subscription->id,
                'description' => 'نتیجه‌ی پرداختِ اشتراک از درگاه دریافت نشد',
                'ip_address' => $request->ip(),
                'properties' => [
                    'gateway' => $e->gateway,
                    'stage' => $e->stage,
                    'ref_id' => $subscription->ref_id,
                    'reason' => $e->getMessage(),
                ],
                'created_at' => now(),
            ]);

            return redirect('/account?checkout=unknown');
        }

        if (! $tracking) {
            $this->renewal->fail($subscription, 'درگاه پرداخت را تأیید نکرد');

            return redirect('/account?checkout=failed');
        }

        /*
         * 🔴 فعال‌سازی فقط در `SubscriptionRenewal::complete`: زیرِ قفلِ کدِ رهگیری و ردیف، فقط `pending` ← `active`،
         * و «یک کدِ رهگیری، یک پرداخت» (مرحلهٔ ۵ · فاز ۹). همان که بازبینیِ دوره‌ای هم صدا می‌زند (۱۲/۲).
         */
        $result = $this->renewal->complete($subscription, $tracking);

        if ($result->status !== 'active') {
            Log::warning('[subscription] tracking code already used elsewhere', [
                'subscription' => $subscription->id,
                'tracking' => $tracking,
            ]);

            return redirect('/account?checkout=failed');
        }

        return redirect('/account?checkout=success&tracking='.urlencode($tracking));
    }

    /* ─────────────── خریدِ مجتمع توسطِ حسابِ اولیه (مرحلهٔ ۹ · فاز ۴) ─────────────── */

    /**
     * پیش از پرداخت فقط `CheckoutIntent`؛ مجتمع، اشتراک و نقشِ مدیر پس از
     * تأییدِ درگاه و در یک تراکنش (`ComplexPurchase::complete`).
     */
    private function startNewComplex(StartCheckoutRequest $request)
    {
        $data = $request->validated();
        $type = ComplexType::from($data['complex_type']);

        // مسیرِ وب است: خطای قاعده به صفحه‌ی حساب با پیامِ روشن برمی‌گردد، نه صفحه‌ی خطا
        try {
            $plan = $this->purchase->plan($data['plan'], $type);
        } catch (DomainException $e) {
            return redirect('/account?checkout=error&message='.urlencode($e->getMessage()));
        }

        // درگاهِ خاموش پیش از ساختنِ قصد گفته می‌شود، نه وسطِ راه
        if (! $this->gateways->isEnabled()) {
            return redirect('/account?checkout=error&message='.urlencode('پرداختِ آنلاینِ اشتراک هنوز فعال نشده است.'));
        }

        $intent = $this->purchase->begin(
            Auth::user(),
            $plan,
            $data['complex_name'],
            $type,
            $data['complex_address'] ?? null,
        );

        try {
            $redirect = $this->gateways->driver()->request($intent);
        } catch (\Throwable $e) {
            $this->purchase->fail($intent, 'درخواستِ درگاه ناموفق بود');

            return redirect('/account?checkout=error&message='.urlencode($e->getMessage()));
        }

        return $this->toGateway($redirect);
    }

    /**
     * فرستادنِ مرورگر به درگاه — مشترکِ تمدید و خریدِ مجتمع.
     *
     * @param  array<string, mixed>  $redirect
     */
    private function toGateway(array $redirect)
    {
        if (($redirect['method'] ?? 'GET') === 'POST') {
            /*
             * ⚠️ بدونِ این، CSPِ همین صفحه فرمِ رفتن به بانک را بلاک می‌کند.
             * `request()` و نه `$request`: FormRequest کپیِ جداگانه‌ای با
             * کیسه‌ی attributesِ خودش است و میدل‌ور آن را نمی‌بیند.
             */
            SecurityHeaders::allowFormTarget(request(), $redirect['redirect_url']);

            return view('payments.redirect', [
                'action' => $redirect['redirect_url'],
                'fields' => $redirect['fields'] ?? [],
            ]);
        }

        return redirect()->away($redirect['redirect_url']);
    }

    /**
     * بازگشت از درگاه برای قصدِ خرید.
     *
     * مثلِ بازگشتِ قبض و اشتراک: بی CSRF و بی `auth` (نشست شاید تا بازگشت منقضی
     * شده باشد)؛ اعتبار را تأییدِ خودِ درگاه می‌دهد، و اگر نشستی هست باید خودِ
     * خریدار باشد. هیچ پارامترِ «موفق» ِ کلاینت خوانده نمی‌شود.
     */
    public function intentCallback(Request $request, CheckoutIntent $intent)
    {
        $this->ensureBuyerOrBank($intent->user_id);

        // تکلیفِ روشن‌شده دوباره بررسی نمی‌شود: refresh و بازگشتِ تکراری بی‌اثرند
        if (! $intent->isPending()) {
            return $this->afterCheckout($intent);
        }

        try {
            $tracking = $this->gateways->driver()->verify($intent, $request->all());
        } catch (GatewayUnreachable $e) {
            // نامعلوم، نه ناموفق (R59): pending می‌ماند و audit می‌شود
            AuditLog::create([
                'complex_id' => null,
                'user_id' => $intent->user_id,
                'action' => 'checkout.indeterminate',
                'subject_type' => CheckoutIntent::class,
                'subject_id' => $intent->id,
                'description' => 'نتیجه‌ی پرداختِ خریدِ مجتمع از درگاه قطعی نشد',
                'ip_address' => $request->ip(),
                'properties' => [
                    'gateway' => $e->gateway,
                    'stage' => $e->stage,
                    'authority' => $intent->authority,
                    'reason' => $e->getMessage(),
                ],
                'created_at' => now(),
            ]);

            return redirect('/account?checkout=unknown');
        }

        if (! $tracking) {
            return $this->afterCheckout($this->purchase->fail($intent, 'درگاه پرداخت را تأیید نکرد'));
        }

        $result = TrackingCodes::exclusively(
            $tracking,
            fn (): CheckoutIntent => $this->purchase->complete($intent, $tracking),
        );

        if ($result->status === CheckoutIntent::FAILED) {
            Log::warning('[checkout] tracking code already used elsewhere', ['intent' => $intent->id]);
        }

        return $this->afterCheckout($result);
    }

    /**
     * «اگر نشستی هست، باید خودِ خریدار باشد» — مشترکِ دو بازگشت.
     *
     * عمداً Policy نیست: `authorize()` همیشه کاربرِ واردشده می‌خواهد، ولی بازگشت
     * از بانک باید بی نشست هم کار کند. یک جا، تا قاعده دو نسخه نشود.
     */
    private function ensureBuyerOrBank(?int $buyerId): void
    {
        abort_unless(! Auth::check() || $buyerId === Auth::id(), 403);
    }

    /** مقصد پس از بازگشت — از وضعیتِ سرور، نه از پارامترِ درگاه */
    private function afterCheckout(CheckoutIntent $intent)
    {
        return match ($intent->status) {
            /*
             * مدیرِ مجتمعِ تازه است. حسابِ اولیه همین حالا در زمینه‌ی آن است؛ عضوِ مجتمعِ دیگر نه — `complex`
             * به کلاینت می‌گوید به کجا برود و خودِ تعویض (`POST account/context`) را سرور با عضویت می‌سنجد.
             */
            CheckoutIntent::COMPLETED => redirect('/dashboard?checkout=success&tracking='.urlencode((string) $intent->tracking_code).'&complex='.(int) $intent->complex_id),
            CheckoutIntent::PAID => redirect('/account?checkout=review'),
            CheckoutIntent::PENDING => redirect('/account?checkout=unknown'),
            default => redirect('/account?checkout=failed'),
        };
    }
}
