# عاملِ دومِ سوپر ادمین — Authenticator (TOTP) و کدهای بازیابی

تصمیمِ کارفرما (۲۰۲۶-۱۰-۳۰، بندِ ۴؛ جایگزینِ «شماره + رمز + TOTP» ِ ۲۰۲۶-۱۰-۲۹):

- پروژه **دو مدیر** دارد و هر کدام **حسابِ سوپر ادمینِ شخصیِ خودش** را: وحید مرادی (`vahidDeep`) و علی بافنده (`aliBH`).
- درِ ورودِ مدیران **جداست**: `https://sakena.ir/auth/admin` = **نام کاربری + رمزِ شخصی + کدِ شش‌رقمیِ برنامه‌ی
  Authenticator**، با **کدهای بازیابیِ یک‌بارمصرف** برای وقتی گوشی در دسترس نیست. هیچ پیامکی به مدیر نمی‌رود. این نشانی
  هیچ‌جای سایت پیوند ندارد؛ فقط همین دو مدیر آن را می‌دانند. نام کاربری به حروفِ بزرگ و کوچک حساس نیست.
- درِ کاربران (`https://sakena.ir/auth`، دکمه‌ی «ورود» ِ سایت) **بی‌رمز** است: شماره + کدِ پیامکی. شماره‌ی مدیر از این در
  کدی نمی‌گیرد و وارد نمی‌شود — همان پاسخِ یکسانِ هر شماره‌ی دیگر. «فراموشیِ رمز» و «تغییرِ رمز» برای کاربران برداشته شد.
- Preview یک در دارد (`https://preview.sakena.ir/auth`، نام کاربری + رمزِ حساب‌های نمونه، بی پیامک و بی TOTP)؛
  `/auth/admin` آن‌جا به همان `/auth` برمی‌گردد و درِ مدیران آن‌جا بسته است.
- هر مدیر رمزِ خودش را از «اطلاعاتِ کاربری» عوض می‌کند، و هر کارش با **نامِ خودش** در «لاگ فعالیت ← فعالیت مدیران» ِ
  پنلِ سوپر ادمین دیده می‌شود (فقط همان‌جا). هرچه مدیر به کاربران می‌فرستد با نامِ «تیم ساکنا» نشان داده می‌شود.

## قاعده‌ها و جای اجرا

| قاعده                                                                                                | کد                                                                    | آزمون (`tests/Feature/AdminTwoFactorTest.php` مگر جز این گفته شود)                                     |
| ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| TOTP ِ استاندارد (RFC 6238، SHA1، ۶ رقم، ۳۰ ثانیه، ±۱ گام)، بی بسته‌ی تازه                           | `App\Services\Auth\Totp`                                              | `tests/Unit/TotpTest.php` (بردارهای پیوستِ B ِ خودِ RFC)                                               |
| فقط درِ مدیران (`/auth/admin`): نام کاربری + رمز، سپس همیشه کدِ برنامه؛ هیچ پیامکی؛ درِ کاربران نه   | `AuthController::adminLogin` ← `startTwoFactor`                       | `test_the_admin_door_always_asks_for_the_app_code_and_sends_no_sms`                                    |
| دو مدیر، هر کدام با نام کاربری و رمزِ خودش؛ رمزِ یکی با نامِ دیگری وارد نمی‌کند                      | `admin:create`، `admin:username`                                      | `CreateAdminCommandTest`                                                                               |
| کدِ پذیرفته‌شده دوباره پذیرفته نمی‌شود (replay)                                                      | `two_factor_last_step`، `AdminTwoFactor::verifyCode` (زیرِ قفلِ ردیف) | `test_a_used_code_cannot_be_replayed`، `TotpTest::test_a_code_is_never_accepted_twice`                 |
| هر کدِ بازیابی یک بار؛ فقط HMAC (با `APP_KEY`) ذخیره می‌شود                                          | `AdminTwoFactor::useRecoveryCode` (زیرِ قفلِ ردیف)                    | `test_each_recovery_code_works_exactly_once`                                                           |
| پنج کدِ نادرست ← بازگشت به گامِ رمز؛ ده کدِ نادرست ← قفلِ ۱۵ دقیقه‌ایِ **حساب** (مستقل از نشست و IP) | `AuthController::loginTwoFactor`                                      | `test_five_wrong_codes_end_the_challenge`، `test_the_account_lock_survives_fresh_sessions`             |
| گامِ پیامکی چالشِ TOTP را تمام نمی‌کند                                                               | `loginVerify`/`loginResend` (نوعِ گامِ در انتظار)                     | `test_the_sms_steps_cannot_complete_…`، `test_a_started_totp_challenge_never_completes_…`              |
| «فراموشیِ رمز» نیست؛ رمزِ مدیر فقط از شل (`admin:password`) و همه‌ی نشست‌هایش را می‌بندد             | `ResetUserPassword`                                                   | `test_there_is_no_sms_password_reset_for_the_admin`، `SessionLifecycleTest::test_resetting_…_server_…` |
| کلید و کدها رمزنگاری‌شده، پنهان از سریال‌شدن، نه در پاسخ (جز گامِ راه‌اندازی)، نه در audit log       | cast ِ `encrypted`، `$hidden` در `User`                               | `test_the_secret_and_recovery_codes_are_encrypted_hidden_and_never_serialised`                         |
| تعویض و کدهای تازه: رمز (پنجره‌ی `reauth`) + کدِ فعلیِ برنامه؛ کلیدِ قبلی تا تأییدِ تازه معتبر       | `TwoFactorController`                                                 | `test_rotating_needs_the_current_code_…`، `test_regenerating_recovery_codes_…`                         |
| غیرفعال‌سازی مسیرِ وب ندارد؛ بازنشانی فقط با فرمانِ سرور (با شماره)                                  | `admin:two-factor <شماره> --reset`                                    | `test_the_server_command_enrols_once_and_resets_with_an_audit_trail`                                   |
| Production: سوپر ادمینِ بی TOTP **وارد نمی‌شود** — نه با پیامک، نه فقط با رمز؛ راهنمای فرمانِ سرور   | `AuthController::adminLogin`                                          | `test_on_production_an_unenrolled_admin_never_signs_in_with_the_password_alone`                        |
| Production: نشستِ بی TOTP (مثلاً پس از `--reset`) جز راه‌اندازی به جایی نمی‌رسد                      | `EnsureAdminTwoFactor` (پیش‌فرضِ بستن)                                | `test_on_production_an_unenrolled_admin_session_reaches_only_the_setup`                                |
| فقط سوپر ادمین؛ کاربرِ عادی چالشِ TOTP نمی‌گیرد                                                      | `TwoFactorController::admin`، `AdminTwoFactor::enabled`               | `test_only_a_super_admin_has_a_second_factor`                                                          |
| هر رویداد با نامِ همان مدیر در audit log: ورود، تغییرِ رمز، فعال‌سازی، تعویض، کدِ بازیابی، بازنشانی  | `AdminTwoFactor`، `AuthController`، `ProfileController`               | `AdminActivityLogTest::test_sign_ins_and_password_changes_are_recorded_by_name_and_never_the_password` |

دستگاهِ مورداعتماد («مرا به خاطر بسپار») برای سوپر ادمین صادر نمی‌شود (`TrustedDeviceService::remember`).
حسابِ سوپر ادمینِ آزمایشیِ Preview (`demo.admin`، بی شماره) با نام کاربری + رمز از همان `/auth` ِ Preview وارد می‌شود و
روی Production اصلاً وارد نمی‌شود (`DemoData`).

## راه‌اندازیِ دو مدیر روی Production (یک بار، روی سرور)

هر دو حساب از پیش ساخته شده و رمز و TOTP دارند؛ از ۲۰۲۶-۱۰-۳۰ فقط **نام کاربری** لازم است. در پوشه‌ی محصول
(`/var/www/sakena`)، برای هر مدیر با شماره‌ی خودش:

```bash
php artisan admin:username 09xxxxxxxxx vahidDeep
php artisan admin:username 09yyyyyyyyy aliBH
```

فرمان نامِ تکراری، پیشوندِ `demo.` و حسابِ غیرِ سوپر ادمین را رد می‌کند و در audit log ثبت می‌شود. سپس ورود:
`https://sakena.ir/auth/admin` ← نام کاربری و رمز ← کدِ شش‌رقمیِ برنامه.

برای مدیرِ **تازه** (اگر روزی لازم شد): `admin:create --phone=…` (رمز را پنهان می‌پرسد؛ `--password` ندهید تا در تاریخچه‌ی
شل نماند)، سپس `admin:username` و `admin:two-factor 09xxxxxxxxx`. کلید، نشانیِ `otpauth://` و ده کدِ بازیابی **فقط** در
خروجیِ همان فرمان چاپ می‌شوند. همان لحظه:

1. کلید را در برنامه‌ی Authenticator ِ گوشیِ **همان مدیر** وارد کنید (Google Authenticator، Microsoft Authenticator، …).
2. ده کدِ بازیابی را در جای امنِ شخصیِ همان مدیر (password manager) بگذارید.
3. ترمینال را پاک کنید (`clear; history -c`).

> شماره، رمز یا کلیدِ هیچ مدیری را در چت، پیام‌رسان، ایمیل یا مخزن نفرستید؛ هر مدیر فرمان‌ها را خودش روی سرور اجرا کند
> یا کنارِ کسی که اجرا می‌کند باشد.

## گوشی گم شد / تعویضِ گوشی

- **گوشیِ تازه، گوشیِ قبلی هنوز هست:** «اطلاعاتِ کاربری» ← «تعویضِ برنامه یا گوشی» ← کدِ فعلی ← اسکنِ QR ِ تازه ← تأیید.
- **گوشی گم شد، کدِ بازیابی هست:** در گامِ دومِ ورود «ورود با کد بازیابی»؛ سپس بی‌درنگ تعویضِ برنامه و «کدهای بازیابیِ
  تازه». هر کدِ بازیابی یک بار.
- **گوشی و همه‌ی کدها از دست رفت:** فقط با دسترسیِ شل به سرور (یا مدیرِ دیگر کنارِ سرور):

```bash
php artisan admin:two-factor 09xxxxxxxxx --reset
php artisan admin:two-factor 09xxxxxxxxx
```

بازنشانی در audit log ثبت می‌شود. تا راه‌اندازیِ دوباره ورودِ این حساب روی Production بسته است.

## رمز فراموش شد

«فراموشیِ رمز» در رابط نیست (۲۰۲۶-۱۰-۳۰). روی سرور: `php artisan admin:password --phone=09xxxxxxxxx` (رمز پنهان پرسیده
می‌شود). همه‌ی نشست‌ها و دستگاه‌های قبلیِ همان حساب بسته می‌شوند؛ ورودِ بعدی همچنان کدِ برنامه می‌خواهد.

## چرخشِ `APP_KEY`

کلید با `APP_KEY` رمزنگاری و کدهای بازیابی با آن HMAC می‌شوند؛ پس از چرخش هر دو نامعتبرند. گامِ مربوط در
[SECRETS.md](SECRETS.md) (بخش ۲): بازنشانی و راه‌اندازیِ دوباره — برای **هر دو** مدیر.

## وضعیت

- **CODE READY:** همه‌ی ردیف‌های جدولِ بالا با آزمونِ رفتاری سنجیده شده‌اند.
- **ENVIRONMENT VERIFIED — نه:** ساختِ دو حساب روی سرورِ واقعی، اسکن با گوشیِ واقعیِ هر مدیر و نخستین ورود فقط روی
  محیطِ واقعی قابلِ تأییدند. وضعیت: IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED.
