<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
use App\Exceptions\DomainException;
use App\Http\Requests\StartCheckoutRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscription\SubscriptionGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * رفت‌وبرگشت بانکی خرید اشتراک.
 *
 * مثل GatewayController عمداً JSON نیست: شروع پرداخت باید مرورگر را واقعاً
 * از سایت ما بیرون ببرد، و بازگشت از دامنه‌ی بانک می‌آید.
 */
class SubscriptionCheckoutController extends Controller
{
    public function __construct(protected SubscriptionGatewayManager $gateways) {}

    public function start(StartCheckoutRequest $request)
    {
        $user = Auth::user();

        // اشتراک برای مجتمع خریداری می‌شود، پس فقط مدیر می‌تواند بخرد؛
        // ساکن نباید بتواند پرداختی انجام دهد که هیچ اثری برایش ندارد.
        $this->authorize('purchase', Subscription::class);

        $request->validated();

        $complex = $this->requireComplex();

        // پکیجِ دیتابیسیِ فعال مقدم است، وگرنه enumِ قابلِ‌خریدِ قدیمی. مبلغ و
        // مدت سمت سرور تعیین می‌شود، نه از درخواست.
        $slug = $request->string('plan')->value();
        $dbPlan = Plan::where('slug', $slug)->where('is_active', true)->first();

        if ($dbPlan) {
            $shadow = SubscriptionPlan::Pro->value;
            $planId = $dbPlan->id;
            $amount = $dbPlan->price;
            $months = $dbPlan->months;
        } else {
            $legacy = SubscriptionPlan::tryFrom($slug);

            // قاعده‌ی کسب‌وکار است، نه نبودِ دسترسی
            if (! $legacy || ! in_array($legacy, SubscriptionPlan::purchasable(), true)) {
                throw DomainException::invalid(
                    'پکیجِ انتخاب‌شده معتبر نیست.',
                    'subscription.invalid_plan',
                );
            }
            $shadow = $legacy->value;
            $planId = null;
            $amount = $legacy->price();
            $months = $legacy->months();
        }

        $subscription = Subscription::create([
            'complex_id' => $complex->id,
            'user_id' => $user->id,
            'plan' => $shadow,
            'plan_id' => $planId,
            'status' => 'pending',
            'method' => 'online',
            'amount' => $amount,
            'months' => $months,
        ]);

        try {
            $redirect = $this->gateways->driver()->request($subscription);
        } catch (\Throwable $e) {
            $subscription->update(['status' => 'failed']);

            return redirect('/account?checkout=error&message='.urlencode($e->getMessage()));
        }

        if (($redirect['method'] ?? 'GET') === 'POST') {
            return view('payments.redirect', [
                'action' => $redirect['redirect_url'],
                'fields' => $redirect['fields'] ?? [],
            ]);
        }

        return redirect()->away($redirect['redirect_url']);
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
        abort_unless(! Auth::check() || $subscription->user_id === Auth::id(), 403);

        // تراکنشی که تکلیفش روشن شده دوباره تایید نمی‌شود: بازگشت دوباره نباید
        // دوره را تمدید کند و نباید اشتراکِ فعال را «ناموفق» علامت بزند.
        if ($subscription->status !== 'pending') {
            return $subscription->status === 'active'
                ? redirect('/account?checkout=success&tracking='.urlencode((string) $subscription->tracking_code))
                : redirect('/account?checkout=failed');
        }

        $tracking = $this->gateways->driver()->verify($subscription, $request->all());

        if (! $tracking) {
            $subscription->update(['status' => 'failed']);

            return redirect('/account?checkout=failed');
        }

        $subscription->update([
            'status' => 'active',
            'tracking_code' => $tracking,
            'paid_at' => now(),
            'starts_at' => now(),
            'ends_at' => now()->addMonths($subscription->months),
        ]);

        return redirect('/account?checkout=success&tracking='.urlencode($tracking));
    }
}
