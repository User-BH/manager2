<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
use App\Exceptions\DomainException;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Requests\StartCheckoutRequest;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Payment\GatewayUnreachable;
use App\Services\Payment\TrackingCodes;
use App\Services\Subscription\SubscriptionGatewayManager;
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

        /*
         * 🔴 `forComplexType` — همان محافظی که در مسیرِ رسیدی هم هست (R67).
         *
         * تعرفه‌ی پکیج‌های تجاری و مسکونی متفاوت است. بدونِ این فیلتر،
         * فرستادنِ slugِ پکیجِ نوعِ دیگر مستقیم به این مسیر، تعرفه‌ی آن را
         * می‌گرفت — و چون مبلغ سمت سرور از همین ردیف خوانده می‌شود، هیچ
         * لایه‌ی دیگری جلویش را نمی‌گرفت.
         */
        $dbPlan = Plan::where('slug', $slug)
            ->where('is_active', true)
            ->forComplexType($complex->type)
            ->first();

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
            $subscription->update(['status' => 'failed']);

            return redirect('/account?checkout=failed');
        }

        /*
         * 🔴 یک کدِ رهگیری، یک پرداخت (مرحلهٔ ۵ · فاز ۹) — همان قاعده‌ی
         * `GatewayController`. بدونِ آن، رهگیریِ یک خریدِ ثبت‌شده اشتراکِ
         * دیگری را هم فعال می‌کرد.
         */
        $activated = TrackingCodes::exclusively($tracking, function () use ($subscription, $tracking): bool {
            if (TrackingCodes::claimedElsewhere($tracking, $subscription)) {
                $subscription->update(['status' => 'failed']);

                return false;
            }

            $subscription->update([
                'status' => 'active',
                'tracking_code' => $tracking,
                'paid_at' => now(),
                'starts_at' => now(),
                'ends_at' => now()->addMonths($subscription->months),
            ]);

            return true;
        });

        if (! $activated) {
            Log::warning('[subscription] tracking code already used elsewhere', [
                'subscription' => $subscription->id,
                'tracking' => $tracking,
            ]);

            return redirect('/account?checkout=failed');
        }

        return redirect('/account?checkout=success&tracking='.urlencode($tracking));
    }
}
