<?php

namespace App\Services\Subscription;

use App\Models\Subscription;

/**
 * قرارداد درگاه پرداخت اشتراک.
 *
 * شکلش عمداً شبیه App\Services\Payment\PaymentGateway است ولی جدا نگه داشته
 * شده چون آن رابط به مدل Payment (که unit_id و bill_id لازم دارد) گره خورده
 * و اشتراک هیچ‌کدام را ندارد.
 */
interface SubscriptionGateway
{
    /**
     * @return array{redirect_url:string,ref_id:string,method?:string,fields?:array<string,string>}
     */
    public function request(Subscription $subscription): array;

    /** کد رهگیری در صورت موفقیت، یا null. */
    public function verify(Subscription $subscription, array $callback): ?string;
}
