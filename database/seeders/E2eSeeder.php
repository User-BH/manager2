<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ComplexInvitation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Blog\BlogImporter;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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

    /**
     * ثبت‌نام‌کننده‌ی اولیه‌ی هر موتور در `tests/e2e/registrant-journey.spec.ts`
     * (مرحلهٔ ۸ · فاز ۱).
     *
     * ⚠️ با API ِ ثبت‌نام ساخته نمی‌شوند: سقفِ `register` پنج در ساعت برای هر
     * IP است و `management-journey` خودش سه تا از آن را می‌خورد — در CI ششمی
     * ۴۲۹ گرفت. فرمِ ثبت‌نام همان‌جا پوشش دارد؛ این‌جا فقط حسابِ حاصلش لازم است.
     *
     * سفر آن‌ها را عضو می‌کند؛ اجرای دوباره‌ی همین seeder دوباره بی‌مجتمعشان
     * می‌کند تا سفر روی همان پایگاه‌داده تکرارپذیر باشد.
     */
    public const REGISTRANTS = ['09120000021', '09120000022', '09120000023'];

    /** حسابِ اولیه برای خریدِ آنلاینِ اشتراک و ساختِ مجتمع (مرحلهٔ ۹ · فاز ۴) — یکی برای هر موتور */
    public const BUYERS = ['09120000041', '09120000042', '09120000043'];

    /** بیش از هر قبضِ نمایشی، تا کیف قبض را کامل بپردازد نه بخشی از آن. */
    public const CREDIT = 5_000_000;

    public function run(WalletService $wallet, BlogImporter $importer): void
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

        foreach (self::REGISTRANTS as $phone) {
            $this->freshRegistrant($phone);
        }

        foreach (self::BUYERS as $phone) {
            $this->freshRegistrant($phone);
            // خریدار با نام و نام خانوادگیِ جدا، مثلِ هر ثبت‌نامِ تازه
            User::withoutGlobalScopes()->where('phone', $phone)->first()?->forceFill([
                'first_name' => 'خریدار',
                'last_name' => 'سرتاسری',
            ])->save();
        }

        /*
         * مقاله‌های واقعیِ بلاگ (مرحلهٔ ۷ · فاز ۷) — همان که استقرار وارد می‌کند؛
         * سفرهای صفحه‌بندی، جست‌وجو و بازکردنِ مقاله بی‌آن‌ها چیزی برای سنجیدن
         * نداشتند.
         */
        $importer->import();
    }

    /** همان حسابی که `AuthController::register` می‌سازد: مالکِ بی‌مجتمع، بی‌واحد، بی‌دعوت */
    private function freshRegistrant(string $phone): void
    {
        $user = User::withoutGlobalScopes()->firstOrNew(['phone' => $phone]);
        $user->fill([
            'complex_id' => null,
            'name' => 'ثبت‌نام‌کننده‌ی سرتاسری',
            // حسابِ پیش از مرحلهٔ ۹: نامِ تک‌رشته‌ای، تا مسیرِ «تکمیلِ نام» هم سنجیده شود
            'first_name' => null,
            'last_name' => null,
            'password' => Hash::make('password'),
            'role' => UserRole::Owner,
            'is_active' => true,
            'terms_accepted_at' => now(),
        ])->save();

        $user->units()->withoutGlobalScopes()->detach();
        ComplexInvitation::withoutGlobalScopes()->where('user_id', $user->id)->delete();
    }
}
