<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * دادهٔ تکمیلیِ آزمون‌های سرتاسری، پس از `DemoSeeder` (مرحلهٔ ۵ · فاز ۸ · بندِ ۶۴).
 *
 * سفرِ «پرداخت از کیفِ پول» در مرورگرِ واقعی موجودیِ کیف لازم دارد، و کیف در
 * این سامانه فقط از اضافه‌پرداخت یا اصلاحِ مدیر پر می‌شود — نه از فرمی که
 * آزمون بتواند بزند. پس اینجا، مثلِ `otp_disabled` در CI، پیش‌شرط ساخته
 * می‌شود.
 *
 * ⚠️ `DemoSeeder` عمداً دست نخورد: دادهٔ نمایشی است و کیفِ پُر در آن معنای
 * نمایشیِ غلطی می‌ساخت.
 *
 * 🔴 از **خودِ `WalletService`** می‌گذرد، نه درجِ مستقیم در جدول: همان مسیری
 * که دفترِ کیف و موجودی را هم‌خوان نگه می‌دارد.
 */
class E2eSeeder extends Seeder
{
    /** همان مالک‌های سه موتور در `tests/e2e/financial-journey.spec.ts`. */
    public const OWNERS = ['09121111102', '09121111103', '09121111104'];

    /** بیش از هر قبضِ نمایشی، تا کیف قبض را کامل بپردازد نه بخشی از آن. */
    public const CREDIT = 5_000_000;

    public function run(WalletService $wallet): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('E2eSeeder روی محصول اجرا نمی‌شود.');
        }

        foreach (self::OWNERS as $phone) {
            $owner = User::withoutGlobalScopes()->where('phone', $phone)->first();

            if (! $owner) {
                throw new RuntimeException("مالکِ {$phone} نیست؛ اول DemoSeeder را اجرا کنید.");
            }

            $unit = $owner->units()->withoutGlobalScopes()->first();

            // دوباره‌اجرا اعتبار را دوبرابر نمی‌کند
            if ($unit === null || $wallet->balance($unit) >= self::CREDIT) {
                continue;
            }

            $wallet->credit($unit, self::CREDIT, WalletTransaction::SOURCE_ADJUSTMENT, null, 'اعتبارِ آزمونِ سرتاسری');
        }
    }
}
