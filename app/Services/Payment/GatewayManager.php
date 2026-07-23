<?php

namespace App\Services\Payment;

use App\Models\Complex;
use App\Services\Subscription\PlanGate;
use RuntimeException;

/**
 * Resolves the active gateway driver for a complex. Real PSP drivers
 * (MellatGateway, SamanGateway) plug in here once credentials exist in
 * the complex's gateway_config; until then only the sandbox driver runs.
 */
class GatewayManager
{
    public function __construct(protected PlanGate $plans) {}

    public function for(Complex $complex): PaymentGateway
    {
        // درگاه بانکی واقعی از امکانات پروست. بررسی اینجاست و نه فقط هنگام
        // ذخیره‌ی تنظیمات، وگرنه مجتمعی که یک‌بار ملت را تنظیم کرده بود پس از
        // انقضای اشتراکش تا ابد از درگاه واقعی استفاده می‌کرد.
        if ($this->isRealGateway($complex) && ! $this->plans->isPro($complex)) {
            throw new RuntimeException(
                'اشتراک پرو این مجتمع منقضی شده و درگاه بانکی غیرفعال است. '
                .'برای فعال‌سازی دوباره، اشتراک را تمدید کنید یا از «واریز و آپلود رسید» استفاده کنید.'
            );
        }

        return match ($complex->payment_gateway) {
            // سندباکس فقط بیرون از production؛ وگرنه تسویه‌ی قبض بدون پول
            'fake' => Sandbox::gateway(),
            'mellat' => new MellatGateway($complex->gateway_config ?? [], $complex->currency),
            'saman' => new SamanGateway($complex->gateway_config ?? [], $complex->currency),
            default => throw new RuntimeException('درگاه پرداخت برای این مجتمع فعال نیست. از آپلود رسید استفاده کنید.'),
        };
    }

    /**
     * آیا دکمه‌ی «پرداخت آنلاین» باید به کاربر نشان داده شود؟
     *
     * مجتمعی که پیش از این روی سندباکس تنظیم شده و حالا روی سرور واقعی
     * بالا آمده، اینجا false می‌گیرد و فقط مسیر رسید را می‌بیند — بهتر از
     * اینکه دکمه بیاید و وسط راه به خطا بخورد.
     */
    public function isOnlineEnabled(Complex $complex): bool
    {
        if ($complex->payment_gateway === 'fake') {
            return Sandbox::isAllowed();
        }

        // اشتراکِ منقضی‌شده یعنی دکمه‌ی پرداخت آنلاین نیاید؛ بهتر از این است
        // که ساکن دکمه را بزند و وسط راه به خطا بخورد.
        return $this->isRealGateway($complex) && $this->plans->isPro($complex);
    }

    /** آیا این مجتمع روی درگاه بانکی واقعی تنظیم شده؟ */
    private function isRealGateway(Complex $complex): bool
    {
        return in_array($complex->payment_gateway, ['mellat', 'saman'], true);
    }
}
