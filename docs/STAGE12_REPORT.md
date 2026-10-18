# مرحلهٔ ۱۲ — گزارشِ نهایی (فاز ۱۲/۹)

> سندِ کارفرما: «سند جامع بازبینی و تکمیل هسته Sakena» (`مرحله بعد.txt`، ۵۸ بند و قراردادهای کل). نقشه و جزئیاتِ هر فاز:
> [STAGE12_ROADMAP.md](STAGE12_ROADMAP.md). هر وضعیت این‌جا مدرک دارد — نامِ آزمون، سفرِ مرورگر یا اجرای CI؛ هیچ موردی با
> «باید کار کند» یا «UI هست» VERIFIED نشده است.

**اجرای نهایی روی `a79d096 (کد) و کامیتِ همین گزارش`:** PHPUnit ِ کامل ۲٬۷۳۵ آزمون — ۲٬۷۳۰ موفق، ۳ رد (skipped)؛ دو شکستِ محلیِ امنیتِ آپلود فقط به دلیلِ قرنطینه‌ی Windows Defender (در CI سبز)؛ Vitest ۹۰۴ موفق در ۹۹ پرونده؛ tsc و ESLint بی خطا؛ PHPStan بی خطا؛ سرتاسری (Chromium و WebKit،
پایگاه‌داده‌ی تازه) ۱۸۸ موفق، ۸ رد (Firefox روی این ماشین اجرا نمی‌شود؛ در CI هر سه موتور)؛ خرابکاریِ کلِ مرحله روی کدِ نهایی ۶۷ از ۶۷ جهشِ قابلِ اجرا کشته شد (۶۳ PHP/Vitest در یازده اسکریپتِ ۱۲/۱ تا ۱۲/۸ و ۴ مرورگری)؛ چهار جهشِ قدیمی که الگویشان با اصلاح‌های بعدی از کد رفته بود یا نسخه‌ی تازه‌ی کشته‌شده داشتند یا دوباره نوشته و کشته شدند؛ CI سبز در پنج کار: امنیت، فرانت، بک‌اند، قیدهای MySQL، سرتاسری در سه موتور (شماره‌ی اجرا در CANONICAL_INVENTORY).

## Definition of Done

### Architecture

| معیار                        | مدرک                                                                                                                                    | وضعیت    |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| چرخه‌ی عمرِ کاربر            | حالت از سرور (`AccountState`، `me.dashboard`)؛ `SessionContextTest::test_the_same_account_and_profile_survive_both_transitions`         | VERIFIED |
| اولیه ← مدیر                 | `checkout-journey` (پرداختِ آزمایشی، تأیید، همان حساب)؛ `CheckoutIntentTest`، `SubscriptionRenewalTest`، `ReconcilePaymentsTest`        | VERIFIED |
| اولیه ← واحد                 | `public-join` (درخواست ← تأییدِ مدیر ← داشبوردِ واحد، همان هویت)؛ `registrant-journey` (دعوت ← پذیرش با کدِ پیامکی)                     | VERIFIED |
| درخواستِ کاربر ← تأییدِ مدیر | `JoinRequestTest`، `MembershipPathsTest` (رد یک‌باره، مدیرِ چند مجتمع، مجتمعِ تعلیق‌شده)، `joinByManagerPhone.test.tsx`                 | VERIFIED |
| دعوتِ مدیر ← پذیرشِ کاربر    | `SecureInvitationTest` (توکن، هش، انقضا، ابطال، یک‌بارمصرف، ضدِ replay، شماره، OTP حتی با `otp_disabled`)، `InvitationConcurrencyTest`  | VERIFIED |
| چندمجتمعی                    | `SessionContextTest::test_one_account_in_ten_communities_with_different_roles`، `context-switch`، `session-context`                     | VERIFIED |
| چندنقشی                      | نقش از عضویتِ زمینه‌ی مرورگر (`test_the_role_comes_from_the_membership_of_the_sessions_community`)؛ مهاجمانِ چندمجتمعیِ `BolaSweepTest` | VERIFIED |
| زمینه‌ی داشبورد              | سنجاقِ مرورگر (`SessionContext`)، سربرگِ `X-Sakena-Context`، `CommunityIsolationTest` (اعلانِ هر مجتمع در همان زمینه)                   | VERIFIED |

### Security

| معیار              | مدرک                                                                                                                                       | وضعیت    |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ | -------- |
| جداییِ بینِ مجتمعی | `BolaSweepTest` روی **همه‌ی** مسیرها با پنج مهاجم — از جمله عضوِ واقعیِ هر دو مجتمع در زمینه‌ی دیگر (۱۲/۴)                                 | VERIFIED |
| جداییِ بینِ واحدی  | مجموعه‌ی «الف۲» ِ `BolaSweepTest`؛ `MembershipLifecycleTest` (مستأجرِ تازه: قبض، گفت‌وگو، درخواستِ خدمات)                                  | VERIFIED |
| IDOR/BOLA          | همان جارو با شناسه‌ی حدسی و `community_id`/`unit_id`/`account_id` ِ جعلی در بدنه                                                           | VERIFIED |
| مجوزِ عضویت        | عضوِ معلق یا رفته (`MembershipSuspensionTest`، `test_a_suspended_member_neither_reads_nor_writes_that_communitys_messenger`)               | VERIFIED |
| جداییِ داشبورد     | `CommunityIsolationTest`، `initial-dashboard` (مسیر و APIِ مدیر بسته)                                                                      | VERIFIED |
| مجوزِ پیام         | `MessengerConversationTest`، `GroupModerationTest`، `MessengerFeatureMatrixTest`                                                           | VERIFIED |
| مجوزِ فایل         | `MessengerFilesTest::test_downloads_follow_who_may_see_the_message`، `test_a_hidden_messages_attachment_is_not_served_to_residents` (۱۲/۶) | VERIFIED |

### Data

| معیار                                      | مدرک                                                                                                                 | وضعیت    |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------- | -------- |
| حساب با تغییرِ داشبورد می‌ماند             | `checkout-journey`، `public-join`، `registrant-journey` (شناسه، نام، شماره پیش و پس)                                 | VERIFIED |
| پروفایل می‌ماند                            | `test_the_same_account_and_profile_survive_both_transitions`                                                         | VERIFIED |
| داده‌ی تاریخی می‌ماند                      | `DeleteSemanticsTest`، `DataPreservationTest` (پکیجِ فروخته‌شده)، سیاهه‌ی حذف‌های سخت در ROADMAP ۱۲/۴                | VERIFIED |
| پایانِ عضویت حساب را حذف نمی‌کند           | `MembershipLifecycleTest::test_removing_a_resident_keeps_the_account_and_every_record`                               | VERIFIED |
| مستأجرِ تازه به داده‌ی خصوصیِ قبلی نمی‌رسد | `MembershipLifecycleTest` (قبض، گفت‌وگو، درخواستِ خدمات — ۱۲/۴)، `WalletOwnershipTest`، `MessengerHistoryPolicyTest` | VERIFIED |

### Financial

| معیار                                             | مدرک                                                                                                                       | وضعیت                                                    |
| ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| پلن و مبلغ سمتِ سرور                              | `SubscriptionRenewalTest`، `CheckoutIntentTest` (پارامترِ کلاینت نادیده)                                                   | VERIFIED                                                 |
| CheckoutIntent                                    | `CheckoutIntentTest`، `ReconcilePaymentsTest` (قصدِ رسیده به درگاه لغو نمی‌شود)                                            | VERIFIED                                                 |
| تأییدِ پرداخت                                     | تأییدِ مستقلِ درگاه با همان مبلغ؛ ادمینِ کل اشتراکِ پرداخت‌نشده را با هیچ راهی فعال نمی‌کند (`SubscriptionActivationTest`) | VERIFIED                                                 |
| idempotent و ضدِ replay                           | `CheckoutIntentTest` (بازگشتِ تکراری)، `TrackingCodes::exclusively`                                                        | VERIFIED                                                 |
| ایمن در هم‌زمانی                                  | `CheckoutConcurrencyTest` (پردازه‌ی واقعی، MySQL ِ CI)، قفلِ ردیف                                                          | VERIFIED                                                 |
| مجتمع + اشتراک + عضویتِ مدیر اتمیک                | `ComplexPurchase::complete` در یک تراکنش؛ `checkout-journey`                                                               | VERIFIED                                                 |
| رفتارِ واقعیِ بازپرسیِ زرین‌پال با ترمینالِ واقعی | سندباکس و آزمون؛ ترمینالِ واقعی در دسترس نیست                                                                              | IMPLEMENTATION READY — ENVIRONMENT VERIFICATION REQUIRED |

### Initial Dashboard

| معیار                                       | مدرک                                                                                  | وضعیت    |
| ------------------------------------------- | ------------------------------------------------------------------------------------- | -------- |
| واقعاً هست، ورودِ واقعی، credential ِ معتبر | `initial-dashboard.spec.ts` با `09120000002` / `password` در مرورگر                   | VERIFIED |
| کمینه‌ی قابلیت‌ها کار می‌کنند               | همان سفر: پنج آیتمِ منو بی هیچ درخواستِ API ِ ناموفق؛ Web Vitals ِ حسابِ اولیه (۱۲/۵) | VERIFIED |
| مسیرِ مدیر و واحد از آن آغاز می‌شود         | `checkout-journey`، `public-join`، `registrant-journey`                               | VERIFIED |

### Messenger

| معیار                                 | مدرک                                                                                                         | وضعیت    |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------ | -------- |
| قابلیت‌های قبلی حفظ شده‌اند           | `MessengerFeatureMatrixTest` — ۲۳ قابلیتِ سند به آزمون‌هایشان                                                | VERIFIED |
| UI/UX ارتقا یافته، پس‌زمینه‌ی اختصاصی | نمونه‌ی تأییدشده‌ی کارفرما؛ `messengerLayout.test.tsx`، سنجشِ بارشدنِ `messenger.css` در `messenger-journey` | VERIFIED |
| موبایل و دسکتاپ                       | سنجشِ مرورگر در هر دو اندازه و هر دو تم؛ سفرهای پیام‌رسان در Chromium و WebKit                               | VERIFIED |
| مجوزِ کامل                            | بخشِ Security بالا                                                                                           | VERIFIED |
| کارایی                                | بودجه‌ی نشستِ باندل بی بالا بردنِ سقف (`bundleBudget.test.ts`)؛ CSS فقط همراهِ صفحه‌ی پیام‌رسان              | VERIFIED |

### Search

| معیار                                                     | مدرک                                                                                                                                      | وضعیت    |
| --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| از سایدبار برداشته، در نوارِ بالا، نمایش در ناحیه‌ی محتوا | `recentSearches.test.tsx`، سنجشِ مرورگر                                                                                                   | VERIFIED |
| حذفِ تکی و «پاک کردن همه»                                 | `recentSearches.test.tsx`                                                                                                                 | VERIFIED |
| حریم و مجوز                                               | پاک‌سازیِ پایانِ نشست (`recentSearches.test.tsx`)، `ManagerSearchTest::test_one_account_searches_only_from_the_community_of_this_browser` | VERIFIED |

## پانزده خروجیِ خواسته‌شده

**۱. از قبل وجود داشت (با آزمون سنجیده شد، نه فرض):** حالتِ اولیه بی نقشِ جدا؛ خریدِ آنلاینِ اتمیک؛ دو مسیرِ عضویت و دعوتِ امن (توکن،
هش، انقضا، ابطال، OTP)؛ جاروی BOLA؛ کیف و دفترِ تغییرناپذیر؛ چهار داشبوردِ جدا؛ همه‌ی قابلیت‌های پیام‌رسان؛ نتیجه‌ی جست‌وجو در
ناحیه‌ی محتوا؛ حذفِ تکی و «پاک کردن همه» ِ تاریخچه.

**۲. ناقص یا متعارض بود:** زمینه و نقش روی ردیفِ حساب (سراسری بینِ دستگاه‌ها)؛ تأییدِ دستیِ اشتراکِ آنلاینِ پرداخت‌نشده به دستِ ادمینِ
کل؛ بازیابیِ پرداختِ «نامعلوم» و «پرداخت‌شده ولی ساخته‌نشده»؛ ردِ درخواست بی قفل؛ یافتنِ مدیر با شماره از ردیفِ حساب؛ دعوتِ بی
انقضا؛ پذیرشِ دعوت بی OTP با `otp_disabled`؛ اعلانِ مجتمعِ دیگر در داشبورد؛ درخواستِ خدماتِ مستأجرِ قبلی؛ حذفِ پکیجِ فروخته‌شده؛
Web Vitals ِ حسابِ اولیه؛ پیوستِ پیامِ پنهان؛ ظاهرِ پیام‌رسان (بندهای ۴۰–۴۷)؛ «جست‌وجوهای اخیر» در سایدبار و ماندنِ تاریخچه پس از
خروج؛ نبودِ آزمونِ مرورگری با credential ِ اولیه.

**۳. اصلاح شد:** همه‌ی مواردِ بندِ ۲ — جزئیات و آزمونِ قرمزِ پیش از اصلاحِ هر کدام در ROADMAP (۱۲/۱ تا ۱۲/۸).

**۴. آزمون‌های افزوده:** PHPUnit: `SessionContextTest`، `SubscriptionRenewalTest`، `SubscriptionActivationTest`،
`ReconcilePaymentsTest`، `MembershipPathsTest`، `CommunityIsolationTest`، `DataPreservationTest`، `MessengerFeatureMatrixTest` و
آزمون‌های تازه در `MembershipLifecycleTest`، `MessengerFilesTest`، `MembershipMessagingTest`، `InitialAccountTest`، `ManagerSearchTest`،
`SecureInvitationTest`؛ دو مهاجمِ چندمجتمعی در `BolaSweepTest`. Vitest: `sessionContext`، `joinByManagerPhone`، `messengerLayout`،
`messengerContrast`، `recentSearches`. مرورگر: `session-context`، `initial-dashboard`، و سنجش‌های تازه در `public-join`،
`messenger-journey`، `registrant-journey`، `management-journey`.

**۵. آزمون‌های خصمانه:** جاروی BOLA با شناسه‌ی حدسی و بدنه‌ی جعلی؛ عضوِ دو مجتمع در زمینه‌ی دیگر؛ سنجاق و `complex_id` ِ جعلی؛ ادمینِ کل در
برابرِ اشتراکِ پرداخت‌نشده؛ بازگشتِ دست‌کاری‌شده و تکراریِ درگاه؛ دعوتِ بی انقضا، باطل، مصرف‌شده و برای شماره‌ی دیگر؛ ردِ درخواستِ
پاسخ‌داده؛ مستأجرِ تازه و ساکنِ جابه‌جاشده؛ پیوستِ پیامِ پنهان با نشانیِ مستقیم؛ تاریخچه‌ی جست‌وجو روی دستگاهِ مشترک. و خرابکاری
(mutation) در هر فاز — هر نگهبان یک بار عمداً شکسته شد تا معلوم شود آزمونش واقعاً می‌گیرد.

**۶. رگرسیون:** پس از هر فاز PHPUnit و Vitest ِ کامل و سفرهای مرورگرِ مرتبط؛ CI در سه موتور؛ نقشه‌ی رگرسیونِ فازها
(`PhaseRegressionMapTest`، کف ۲۰۵ ردیف)؛ ماتریسِ پیام‌رسان. یافته‌های CI در طولِ مرحله (سنجاقِ زمینه، پاسخِ همگانیِ کش‌شده، فرمانِ
اسکرولِ WebKit، فرمِ جست‌وجوی موبایل) هر کدام با ریشه از ردِ Playwright یا لاگِ CI ثبت و رفع شد.

**۷. نیازمندِ محیطِ واقعی:** رفتارِ بازپرسیِ زرین‌پال با ترمینالِ واقعی؛ بازپرداختِ واقعی در بانک (کارِ انسان، این‌جا فقط ثبت)؛ تحویلِ
پیامکِ واقعی (درایورِ آزمون `log`)؛ Firefox ِ Playwright روی این ماشین اجرا نمی‌شود (در CI سبز)؛ دو آزمونِ امنیتِ آپلود را
Windows Defender ِ این ماشین قرنطینه می‌کند (در CI سبز).

**۸. هنوز OPEN:** همه Low و ثبت‌شده در CANONICAL_INVENTORY: گامِ Contract ِ Expand→Contract (برداشتنِ مسیرهای ۴۱۰ ِ رسید و `bankInfo` ِ خالی پس از یک چرخه‌ی استقرار؛ برداشتنِ ستون‌های قدیمِ پیام‌رسان)؛ پرش به پیامی که هنوز بار نشده، پیشرفت و لغوِ پیوستِ پیام‌رسان، هم‌رسانیِ بیش از ۱۰۰ پیام پس از قطعیِ طولانی؛ و تصمیمِ عملیاتیِ `otp_disabled` برای ادمینِ کل (نقصِ مخزن نیست).

**۹. عمداً ساخته نشد:** پلن یا قیمتِ تازه (سند نخواست؛ زیرساختِ قابلیت‌ها تصمیمِ معماریِ ۱۲/۲)؛ ستونِ انقضای `CheckoutIntent` (تصمیمِ
۱۲/۲: بستن بی پرسیدن از درگاه شاید پرداختِ واقعی را می‌بست)؛ ذخیره‌ی سمتِ سرورِ تاریخچه‌ی جست‌وجو (پاک‌سازیِ پایانِ نشست بندِ ۵۷ را
بس است)؛ مرحلهٔ ۱۱ ِ پیشین (SEO و ویدیوی دمو) به خواستِ کارفرما آخرِ کار.

**۱۰. Database/Migration:** `2026_10_20_000001_add_refund_to_checkout_intents` (بازپرداختِ ثبت‌شده و وضعیتِ `refunded`)؛
`2026_10_21_000001_scope_notifications_to_their_community` (افزودنِ `complexId` به دادهٔ اعلان‌های مجتمعیِ موجود؛ هیچ ردیفی حذف
نشد، `down` همان کلید را برمی‌دارد). هر دو در `MigrationSafetyTest` رفت‌وبرگشت‌پذیرند.

**۱۱. API:** `POST subscription/receipt` و `POST system/subscriptions/{id}/approve|reject` ← ۴۱۰ `subscription.online_only`
(یک چرخه‌ی استقرار، سپس حذف)؛ `POST system/checkouts/{intent}/refund` (تازه، `reauth`)؛ `payments:reconcile` هر ۱۰ دقیقه؛ سربرگِ
`X-Sakena-Context` روی پاسخِ شخصیِ عضو؛ `me.dashboard`؛ `join-requests/lookup` با `complexes` و `join-requests` با `complex_id` ِ
اختیاری؛ ردِ درخواستِ پاسخ‌داده ۴۲۲؛ حذفِ پکیجِ فروخته‌شده ۴۰۹ `plan.in_use`؛ پیوستِ پیامِ پنهان برای ساکن ۴۰۴ و `attachment: null`.
همه افزودنی یا با Expand→Contract (`API_COMPATIBILITY.md`).

**۱۲. Frontend:** ناظرِ زمینه‌ی سرور (`serverContext.ts`) و ورود به زمینه‌ی تازه؛ صفحه‌ی خرید و حسابِ بی رسید؛ ادمینِ کل: اشتراک‌ها
فقط‌خواندنی و بازپرداخت؛ انتخابِ مجتمع برای مدیرِ چند مجتمع؛ پیام‌رسانِ بازطراحی‌شده (`messageLayout.ts`، `messenger.css`، کادرِ
نوشتنِ یک‌ردیفه، موبایلِ تمام‌صفحه)؛ «جست‌وجوهای اخیر» در نوارِ بالا و جعبه‌ی جست‌وجوی موبایل. داشبوردهای مدیر، واحد و ادمینِ کل
جز همین موارد دست نخوردند.

**۱۳. Authorization/Security:** زمینه از سنجاقِ مرورگر و عضویتِ فعال، نقش از همان عضویت؛ نگهبانِ مدلِ فعال‌سازیِ اشتراک؛ رد و تأییدِ
درخواست زیرِ قفل؛ یافتنِ مدیر از عضویتِ فعال؛ OTP ِ همیشگیِ پذیرشِ دعوت؛ اعلان در زمینه‌ی خودش؛ مرزِ دوره‌ی سکونت برای درخواستِ
خدمات؛ پیوستِ پیامِ پنهان؛ پاک‌سازیِ تاریخچه‌ی جست‌وجو با پایانِ نشست.

**۱۴. Messenger:** پیوستِ پیامِ پنهان؛ ماتریسِ قابلیت‌ها؛ آزمونِ زمانِ پیام و عضوِ معلق؛ ظاهرِ تازه (حباب، پس‌زمینه، هویت، موبایل،
کنتراست، جهتِ منوی پیام) با نمونه‌ی تأییدشده.

**۱۵. Search:** «جست‌وجوهای اخیر» از سایدبار به نوارِ بالا؛ نمایش در ناحیه‌ی محتوا؛ جعبه‌ی موبایل؛ پاک‌سازیِ تاریخچه با پایانِ نشست؛
آزمونِ جست‌وجوگرِ چندمجتمعی.
