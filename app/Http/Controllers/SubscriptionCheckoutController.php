<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
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

    public function start(Request $request)
    {
        $request->validate([
            'plan' => ['required', 'string', 'in:pro,pro_yearly'],
        ], [], ['plan' => 'پلن']);

        $plan = SubscriptionPlan::from($request->string('plan')->value());
        $user = Auth::user();

        // مبلغ از enum خوانده می‌شود، نه از درخواست؛ وگرنه کلاینت می‌توانست
        // پلن پرو را به قیمت دلخواه بخرد.
        $subscription = Subscription::create([
            'complex_id' => $this->currentComplex()?->id,
            'user_id' => $user->id,
            'plan' => $plan,
            'status' => 'pending',
            'amount' => $plan->price(),
            'months' => $plan->months(),
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
        abort_unless($subscription->user_id === Auth::id(), 403);

        // بازگشت دوباره‌ی یک اشتراکِ قبلاً فعال‌شده نباید دوره را تمدید کند
        if ($subscription->status === 'active') {
            return redirect('/account?checkout=success&tracking='.urlencode((string) $subscription->tracking_code));
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
