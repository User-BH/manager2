<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use Illuminate\Support\Str;

/**
 * درگاه تستی اشتراک.
 *
 * بانک واقعی صدا زده نمی‌شود؛ کاربر مستقیم به آدرس بازگشت هدایت می‌شود تا
 * کل مسیر «انتخاب پلن ← درگاه ← بازگشت ← فعال‌سازی» بدون حساب PSP هم
 * قابل آزمودن باشد. در محیط واقعی درایور بانک جای این می‌نشیند.
 */
class SandboxSubscriptionGateway implements SubscriptionGateway
{
    public function request(Subscription $subscription): array
    {
        $refId = 'SUB-'.Str::upper(Str::random(12));
        $subscription->update(['gateway' => 'sandbox', 'ref_id' => $refId]);

        return [
            'redirect_url' => route('subscription.callback', [
                'subscription' => $subscription->id,
                'ref' => $refId,
            ]),
            'ref_id' => $refId,
        ];
    }

    public function verify(Subscription $subscription, array $callback): ?string
    {
        if (($callback['ref'] ?? null) !== $subscription->ref_id) {
            return null;
        }

        return 'SUBTRK-'.Str::upper(Str::random(10));
    }
}
