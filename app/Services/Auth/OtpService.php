<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Services\Sms\SmsManager;
use App\Support\Phone;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

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
            /*
             * و روی Preview (مرحلهٔ ۹ · فاز ۸): پیامکِ واقعی آن‌جا ممنوع است، پس کدِ همان
             * سازوکارِ واقعی (هش، انقضا، یک‌بارمصرف، سقفِ تلاش) به آزمونگر نشان داده می‌شود.
             * Preview فقط دادهٔ نمونه دارد و پشتِ کلیدِ دسترسی است.
             */
            'dev_code' => $this->sms->isLogDriver() && app()->environment('local', 'testing', 'preview')
                ? $code
                : null,
            'error' => $sent ? null : 'ارسال پیامک ناموفق بود. بعدا تلاش کنید.',
        ];
    }

    /**
     * کدِ بازیابی — **بی‌آنکه پاسخ بگوید این شماره حساب دارد یا نه**.
     *
     * ─── 🔴 چه چیزی لو می‌رفت (پس از مرحلهٔ ۶) ─────────────────────────────
     * `POST /password/forgot` برای شماره‌ی دارای حساب ۲۰۰ و برای بقیه ۴۲۲
     * می‌داد؛ و حتی با پاسخِ یکسان، سه نشانه‌ی دیگر می‌ماند:
     *
     * • **فاصله‌ی ارسالِ مجدد**: ردیفِ کد فقط برای حسابِ موجود ساخته می‌شد،
     *   پس درخواستِ دوم (از همین مسیر یا از ثبت‌نام) فقط برای آن «کمی صبر
     *   کنید» می‌گرفت.
     * • **زمان**: فقط مسیرِ حسابِ موجود bcrypt و تماسِ شبکه‌ایِ پیامک داشت.
     * • **خطای پیامک**: فقط مسیرِ حسابِ موجود می‌توانست «ارسال ناموفق» بگیرد.
     *
     * پس اینجا برای **هر** شماره همان کار انجام می‌شود — ردیفِ کد با هشِ
     * bcrypt — و تنها تفاوت، فرستادنِ پیامک است که **پس از ارسالِ پاسخ**
     * (`terminating`) انجام می‌شود؛ زمانِ پاسخ به شبکه‌ی پیامک وابسته نیست و
     * شکستِ پیامک در پاسخ دیده نمی‌شود. برای شماره‌ی بی‌حساب کد تصادفی است و
     * هرگز فرستاده نمی‌شود.
     *
     * ⚠️ بهایش: اگر سرویسِ پیامک قطع باشد، کاربر خطایی نمی‌بیند و تا پایانِ
     * فاصله‌ی ارسالِ مجدد صبر می‌کند (ردیف عمداً پاک نمی‌شود، وگرنه همان
     * «صبر کنید» در قطعی نشانه می‌شد). شکست در لاگ ثبت می‌شود.
     *
     * @return array{ok:bool, dev_code:?string, error:?string}
     */
    public function issue(string $phone, bool $deliver): array
    {
        $phone = Phone::normalize($phone);

        $recent = OtpCode::where('phone', $phone)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_COOLDOWN))
            ->exists();
        if ($recent) {
            return ['ok' => false, 'dev_code' => null, 'error' => 'برای ارسال مجدد کد کمی صبر کنید.'];
        }

        $code = (string) random_int(100000, 999999);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        if ($deliver) {
            /*
             * ⚠️ فقط **یک بار**: فهرستِ `terminating` مالِ نمونه‌ی برنامه است و در
             * فرایندِ ماندگار (تست، و Octane اگر روزی بیاید) با هر درخواست دوباره
             * اجرا می‌شود — تستِ شمارش همین را گرفت: یک کد سه بار پیامک شد.
             */
            $pending = true;
            app()->terminating(function () use (&$pending, $phone, $code): void {
                if (! $pending) {
                    return;
                }
                $pending = false;

                if (! $this->sms->sendOtp($phone, $code)) {
                    Log::warning('[otp] recovery code could not be delivered', ['phone_tail' => substr($phone, -4)]);
                }
            });
        }

        return [
            'ok' => true,
            // همان قیدِ R20 در `request()`: فقط محیطِ توسعه و فقط وقتی واقعاً فرستاده می‌شود
            'dev_code' => $deliver && $this->sms->isLogDriver() && app()->environment('local', 'testing', 'preview') ? $code : null,
            'error' => null,
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
            /*
             * ⚠️ همان هزینه‌ی bcrypt در شکستِ زودهنگام هم پرداخت می‌شود، تا زمانِ
             * پاسخ نگوید «برای این شماره اصلاً کدی هست یا نه».
             */
            Hash::check($code, self::dummyHash());

            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update(['used_at' => now()]);

        return true;
    }

    /**
     * هشِ bcryptِ ثابت برای برابر کردنِ زمانِ مسیرهای «چیزی پیدا نشد» با
     * مسیرهای واقعی (بازیابی، ورود). یک بار در هر فرایند ساخته می‌شود و با
     * همان تنظیمِ هزینه‌ی هشِ برنامه.
     */
    public static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(bin2hex(random_bytes(16)));
    }
}
