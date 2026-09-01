<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Services\Sms\SmsManager;
use App\Support\Phone;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public const TTL_SECONDS = 120;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN = 60;

    public function __construct(protected SmsManager $sms) {}

    /**
     * Generate and send a one-time code. Returns the plain code only when the
     * log (test) driver is active, so the tester can read it on screen.
     *
     * @return array{ok:bool, dev_code:?string, error:?string}
     */
    public function request(string $phone): array
    {
        $phone = Phone::normalize($phone);

        // Cooldown: block rapid re-requests.
        $recent = OtpCode::where('phone', $phone)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_COOLDOWN))
            ->exists();
        if ($recent) {
            return ['ok' => false, 'dev_code' => null, 'error' => 'برای ارسال مجدد کد کمی صبر کنید.'];
        }

        $code = (string) random_int(100000, 999999);

        $record = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        // تنها پیامکِ سامانه همین است: کدِ ورود. با درایورِ دارای پترن (ایپ‌پنل)
        // به‌صورت پترن می‌رود، وگرنه پیامکِ متنیِ سازگار با WebOTP.
        $sent = $this->sms->sendOtp($phone, $code);

        /*
         * 🔴 اگر پیامک نرفت، ردیف هم نمی‌ماند (R59).
         *
         * ─── سناریوی «سرویسِ پیامک قطع است» ────────────────────────────────
         * ردیف **پیش از** ارسال ساخته می‌شود و باید هم بشود؛ کد باید پیش از
         * رفتن ذخیره شده باشد. ولی وقتی ارسال شکست می‌خورد، همان ردیف
         * «فاصله‌ی ارسالِ مجدد» را مسلح می‌کرد:
         *
         *   کاربر دکمه را می‌زند  ← پیامک نمی‌رود، خطا می‌بیند
         *   دوباره می‌زند         ← «برای ارسال مجدد کد کمی صبر کنید»
         *
         * یعنی کاربر شصت ثانیه بابتِ خرابیِ **سرویسِ پیامک** جریمه می‌شد،
         * برای کدی که هرگز به دستش نرسیده بود. در قطعیِ گذرا، این تفاوتِ
         * «یک بار دیگر بزن» و «ده دقیقه پشتِ در بمان» است.
         *
         * ⚠️ راهِ سوءاستفاده باز نمی‌شود: مسیرهای `otp-request` محدودکننده‌ی
         * نرخِ خودشان را دارند (`AppServiceProvider::registerRateLimiters`)،
         * پس سقفِ واقعی آنجاست نه در این ردیف.
         */
        if (! $sent) {
            $record->delete();
        }

        return [
            'ok' => $sent,
            /*
             * ⚠️ این مقدار **هرگز** نباید بیرون از محیطِ توسعه برگردد (R20).
             *
             * پیش از این فقط به درایورِ پیامک نگاه می‌شد، و درایورِ پیش‌فرض
             * `log` است. یعنی روی یک نصبِ تازه — پیش از آنکه ادمین سامانه‌ی
             * پیامک را تنظیم کند — این زنجیره کار می‌کرد:
             *
             *   POST /password/forgot   → پاسخ حاوی کد بود
             *   POST /password/forgot/verify
             *   POST /password/reset
             *
             * یعنی **تصاحبِ کاملِ حساب فقط با دانستنِ شماره‌ی موبایل**، بدونِ
             * نیاز به رمز. با یک حسابِ مدیرِ مجتمع آزموده و اثبات شد.
             *
             * حالا شرطِ محیط قطعی است: حتی اگر کسی در محصول درایور را روی
             * `log` بگذارد، کد از API بیرون نمی‌رود.
             */
            'dev_code' => $this->sms->isLogDriver() && app()->environment('local', 'testing')
                ? $code
                : null,
            'error' => $sent ? null : 'ارسال پیامک ناموفق بود. بعدا تلاش کنید.',
        ];
    }

    /**
     * پاک‌کردن کدهای یک شماره.
     *
     * پس از ورودِ کاملِ موفق صدا زده می‌شود تا «فاصله‌ی ارسال مجدد» که از روی
     * ردیفِ همین ورود حساب می‌شد، مانعِ ورودِ بعدی نشود. بدون این، اگر کاربر
     * وارد شود، بیرون بیاید و بلافاصله دوباره وارد شود، ارسال کد با پیام
     * «کمی صبر کنید» رد می‌شد.
     */
    public function clear(string $phone): void
    {
        OtpCode::where('phone', Phone::normalize($phone))->delete();
    }

    public function verify(string $phone, string $code): bool
    {
        $phone = Phone::normalize($phone);

        $otp = OtpCode::where('phone', $phone)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update(['used_at' => now()]);

        return true;
    }
}
