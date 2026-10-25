# عاملِ دومِ سوپر ادمین — Authenticator (TOTP) و کدهای بازیابی

تصمیمِ نهاییِ کارفرما (دستورِ یکپارچه‌ی ۲۰۲۶-۱۰-۲۵، بخشِ ۵؛ بسته‌شدنِ د-۱): ورودِ سوپر ادمینِ اصلیِ Production =
**نام کاربری + رمز + کدِ برنامه‌ی Authenticator**، با **کدهای بازیابیِ یک‌بارمصرف** برای وقتی گوشی در دسترس نیست.
کاربرانِ عادی همان شماره + کدِ پیامکی را دارند. Production فقط **یک** حسابِ سوپر ادمینِ اصلی دارد؛ برای دو همکار
حسابِ دوم ساخته نمی‌شود.

## قاعده‌ها و جای اجرا

| قاعده                                                                                                      | کد                                                                    | آزمون (`tests/Feature/AdminTwoFactorTest.php` مگر جز این گفته شود)                         |
| ---------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| TOTP ِ استاندارد (RFC 6238، SHA1، ۶ رقم، ۳۰ ثانیه، ±۱ گام)، بی بسته‌ی تازه                                 | `App\Services\Auth\Totp`                                              | `tests/Unit/TotpTest.php` (بردارهای پیوستِ B ِ خودِ RFC)                                   |
| پس از رمز همیشه کدِ برنامه — با نام کاربری یا شماره، با `otp_disabled` یا بی آن؛ هیچ پیامکی نمی‌رود        | `AuthController::login` ← `startTwoFactor`                            | `test_login_by_username_or_phone_…`، `test_disabling_sms_codes_does_not_disable_…`         |
| کدِ پذیرفته‌شده دوباره پذیرفته نمی‌شود (replay)                                                            | `two_factor_last_step`، `AdminTwoFactor::verifyCode` (زیرِ قفلِ ردیف) | `test_a_used_code_cannot_be_replayed`، `TotpTest::test_a_code_is_never_accepted_twice`     |
| هر کدِ بازیابی یک بار؛ فقط HMAC (با `APP_KEY`) ذخیره می‌شود                                                | `AdminTwoFactor::useRecoveryCode` (زیرِ قفلِ ردیف)                    | `test_each_recovery_code_works_exactly_once`                                               |
| پنج کدِ نادرست ← بازگشت به گامِ رمز؛ ده کدِ نادرست ← قفلِ ۱۵ دقیقه‌ایِ **حساب** (مستقل از نشست و IP)       | `AuthController::loginTwoFactor`                                      | `test_five_wrong_codes_end_the_challenge`، `test_the_account_lock_survives_fresh_sessions` |
| گامِ پیامکی چالشِ TOTP را تمام نمی‌کند — حتی پس از بازنشانیِ میانِ دو گام                                  | `loginVerify`/`loginResend` (نوعِ گامِ در انتظار)                     | `test_the_sms_steps_cannot_complete_…`، `test_a_started_totp_challenge_never_completes_…`  |
| بازیابیِ رمز با پیامک سوپر ادمین را **وارد نمی‌کند**                                                       | `AuthController::resetPassword`                                       | `test_resetting_the_password_by_sms_does_not_sign_the_admin_in`                            |
| کلید و کدها رمزنگاری‌شده، پنهان از سریال‌شدن، نه در پاسخ (جز گامِ راه‌اندازی)، نه در audit log             | cast ِ `encrypted`، `$hidden` در `User`                               | `test_the_secret_and_recovery_codes_are_encrypted_hidden_and_never_serialised`             |
| تعویض و کدهای تازه: رمز (پنجره‌ی `reauth`) + کدِ فعلیِ برنامه؛ کلیدِ قبلی تا تأییدِ تازه معتبر             | `TwoFactorController`                                                 | `test_rotating_needs_the_current_code_…`، `test_regenerating_recovery_codes_…`             |
| غیرفعال‌سازی مسیرِ وب ندارد؛ بازنشانی فقط با فرمانِ سرور                                                   | `admin:two-factor --reset`                                            | `test_the_server_command_enrols_once_and_resets_with_an_audit_trail`                       |
| Production: سوپر ادمینِ بی TOTP فقط با کدِ پیامکی و فقط تا راه‌اندازی؛ همه‌ی مسیرها جز راه‌اندازی بسته‌اند | `EnsureAdminTwoFactor` (پیش‌فرضِ بستن)                                | `test_on_production_an_unenrolled_admin_session_reaches_only_the_setup`                    |
| Production: بی TOTP و بی پیامک ← ورود رد؛ هرگز فقط با رمز                                                  | `AuthController::login`                                               | `test_on_production_an_unenrolled_admin_never_signs_in_with_the_password_alone`            |
| فقط سوپر ادمین؛ کاربرِ عادی چالشِ TOTP نمی‌گیرد                                                            | `TwoFactorController::admin`، `AdminTwoFactor::enabled`               | `test_only_a_super_admin_has_a_second_factor`                                              |
| هر رویداد در audit log: فعال‌سازی، تعویض، کدهای تازه، مصرفِ کدِ بازیابی، بازنشانی، بازیابیِ رمز            | `AdminTwoFactor`، `AuthController`                                    | چند آزمونِ بالا `AuditLog` را می‌سنجند                                                     |

دستگاهِ مورداعتماد («مرا به خاطر بسپار») از پیش برای سوپر ادمین صادر نمی‌شود (`TrustedDeviceService::remember`).
حسابِ سوپر ادمینِ آزمایشیِ Preview (`demo.admin`، بی شماره) طبقِ تصمیمِ حساب‌های تست با نام کاربری + رمز وارد می‌شود
و روی Production اصلاً وارد نمی‌شود (`DemoData`).

## راه‌اندازیِ اول روی Production (یک بار)

**راهِ ترجیحی — از رابط:**

1. سوپر ادمین با نام کاربری + رمز وارد می‌شود؛ چون هنوز TOTP ندارد، کدِ پیامکی به شماره‌ی ثبت‌شده‌اش می‌رود.
2. پس از ورود فقط «اطلاعاتِ کاربری» باز است (بقیه‌ی مسیرها پاسخِ `two_factor.setup_required` می‌دهند).
3. کارتِ «ورود دومرحله‌ای (Authenticator)» ← «راه‌اندازی» ← QR را با برنامه اسکن کنید ← کدِ شش‌رقمی ← «تأیید و فعال‌سازی».
4. ده کدِ بازیابی **فقط همین یک بار** نشان داده می‌شوند؛ مستقیم در vault ِ مشترک بگذارید.

**وقتی پیامک در دسترس نیست** (`otp_disabled` روشن است یا حساب شماره ندارد) — روی سرور:

```bash
php artisan admin:two-factor <نام کاربریِ سوپر ادمین>
```

کلید، نشانیِ `otpauth://` و ده کدِ بازیابی فقط در خروجیِ همین فرمان چاپ می‌شوند (نه در لاگ). مستقیم در vault بگذارید و
ترمینال را پاک کنید. نخستین ورود با کدِ **بعدیِ** برنامه است (کدِ همان ۳۰ ثانیه برای تأیید مصرف شد).

## دسترسیِ دو همکار به یک حساب

تصمیمِ کارفرما: یک حسابِ اصلی، و دسترسیِ هر دو همکار به عاملِ دوم از راهی امن و کنترل‌شده — مثلاً **vault یا password
manager ِ مشترک** که هم رمز و هم کلیدِ TOTP را نگه می‌دارد (بیشترِ password managerها خودشان کدِ TOTP می‌سازند)، و
کدهای بازیابی در همان vault یا جای امنِ جدا.

- کلید هرگز در مخزن، پیام‌رسان، ایمیل یا لاگ نمی‌رود.
- ⚠️ **محدودیتِ attribution:** چون حساب مشترک است، audit log و تاریخچه‌ی ورود نمی‌گویند **کدام همکار** کاری را کرده؛
  فقط می‌گویند سوپر ادمین، از کدام IP و دستگاه، کِی. این پیامدِ آگاهانه‌ی تصمیمِ «یک حساب» است و معماری برای دورزدنش عوض
  نمی‌شود. اگر روزی attribution ِ فردی لازم شد، تصمیمِ جداگانه‌ی کارفرماست.

## گوشی گم شد / تعویضِ گوشی

- **گوشیِ تازه، گوشیِ قبلی هنوز هست:** «اطلاعاتِ کاربری» ← «تعویضِ برنامه یا گوشی» ← کدِ فعلی ← اسکنِ QR ِ تازه ← تأیید.
- **گوشی گم شد، کدِ بازیابی هست:** در گامِ دومِ ورود «ورود با کد بازیابی»؛ سپس بی‌درنگ تعویضِ برنامه و «کدهای بازیابیِ
  تازه». هر کدِ بازیابی یک بار.
- **گوشی و همه‌ی کدها از دست رفت:** فقط با دسترسیِ شل به سرور:

```bash
php artisan admin:two-factor <نام کاربری> --reset
php artisan admin:two-factor <نام کاربری>
```

بازنشانی در audit log ثبت می‌شود. تا راه‌اندازیِ دوباره ورودِ این حساب روی Production بسته است (یا فقط با پیامک تا
راه‌اندازی، اگر پیامک روشن باشد).

## چرخشِ `APP_KEY`

کلید با `APP_KEY` رمزنگاری و کدهای بازیابی با آن HMAC می‌شوند؛ پس از چرخش هر دو نامعتبرند. گامِ مربوط در
[SECRETS.md](SECRETS.md) (بخش ۲): بازنشانی و راه‌اندازیِ دوباره.

## وضعیت

- **CODE READY:** همه‌ی ردیف‌های جدولِ بالا با آزمونِ رفتاری و خرابکاریِ عمدی (۱۴ جهش، همه کشته) سنجیده شده‌اند.
- **ENVIRONMENT VERIFIED — نه:** راه‌اندازی روی سرورِ واقعی، اسکن با گوشیِ واقعی و vault ِ مشترک فقط روی محیطِ واقعی
  قابلِ تأییدند. وضعیت: IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED.
