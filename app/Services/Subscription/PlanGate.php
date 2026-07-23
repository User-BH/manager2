<?php

namespace App\Services\Subscription;

use App\Enums\SubscriptionPlan;
use App\Models\Complex;
use App\Models\Subscription;
use App\Models\Unit;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * تنها مرجعِ «این مجتمع روی کدام پلن است و چه اجازه‌ای دارد».
 *
 * پیش از این، پلن‌ها فقط روی صفحه‌ی قیمت‌ها نوشته می‌شدند و هیچ‌جای کد
 * خوانده نمی‌شدند؛ یعنی کاربر رایگان دقیقاً همان امکانات کاربر پرو را
 * داشت. حالا نقاط اعمال محدودیت همگی از همین‌جا می‌پرسند.
 *
 * اشتراک به «مجتمع» تعلق دارد نه به کاربر: اگر مدیر الف بخرد، مدیر ب هم
 * باید همان امکانات را ببیند، چون هر دو یک مجتمع را می‌گردانند.
 */
class PlanGate
{
    /** اشتراک فعالِ مجتمع (پرداخت‌شده و منقضی‌نشده)، یا null. */
    public function activeSubscription(?Complex $complex): ?Subscription
    {
        if (! $complex) {
            return null;
        }

        return Subscription::where('complex_id', $complex->id)
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();
    }

    /** پلن مؤثر مجتمع؛ نبودِ اشتراک فعال یعنی رایگان. */
    public function planFor(?Complex $complex): SubscriptionPlan
    {
        return $this->activeSubscription($complex)?->plan ?? SubscriptionPlan::Free;
    }

    public function isPro(?Complex $complex): bool
    {
        return $this->planFor($complex) !== SubscriptionPlan::Free;
    }

    /** تعداد واحدهای فعلی مجتمع. */
    public function unitCount(Complex $complex): int
    {
        return Unit::withoutGlobalScopes()->where('complex_id', $complex->id)->count();
    }

    /**
     * پیش از ساخت واحد تازه صدا زده می‌شود.
     *
     * کد وضعیت ۴۰۲ (Payment Required) عمداً انتخاب شده تا کلاینت بتواند این
     * حالت را از خطای اعتبارسنجی معمولی تشخیص بدهد و به‌جای پیام خطا،
     * پیشنهاد ارتقا نشان دهد.
     */
    public function assertCanAddUnit(Complex $complex): void
    {
        $limit = $this->planFor($complex)->unitLimit();
        if ($limit === null) {
            return;
        }

        if ($this->unitCount($complex) >= $limit) {
            $this->deny(
                'در پلن رایگان تا '.\App\Support\Jalali::digits($limit).' واحد می‌توانید ثبت کنید. '
                .'برای افزودن واحد بیشتر، اشتراک پرو را فعال کنید.'
            );
        }
    }

    public function assertCanUseRealGateway(Complex $complex): void
    {
        if (! $this->planFor($complex)->allowsRealGateway()) {
            $this->deny('اتصال درگاه بانکی نیازمند اشتراک پرو است.');
        }
    }

    public function assertCanExportExcel(Complex $complex): void
    {
        if (! $this->planFor($complex)->allowsExcelExport()) {
            $this->deny('خروجی Excel نیازمند اشتراک پرو است. خروجی PDF در پلن رایگان در دسترس است.');
        }
    }

    /** پاسخ یکدست برای همه‌ی محدودیت‌های پلن. */
    private function deny(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'upgradeRequired' => true,
        ], 402));
    }
}
