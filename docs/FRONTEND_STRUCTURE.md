# ساختار فرانت‌اند (پس از R1)

> از کامیت R1 به بعد، ساختار **Feature-Based** است. هر کد تازه باید در همین چیدمان بنشیند.

## چیدمان

```
resources/
  images/                     ← دارایی‌های عبوری از Vite (هش‌دار)
  js/
    app/                      ← لایه‌ی راه‌انداز (bootstrap)
      main.tsx                ← ورودیِ داشبوردِ SPA
      AppRouter.tsx
      ProtectedRoute.tsx
      entries/                ← ورودیِ هر صفحه‌ی MPA
        home.tsx  demo.tsx  support.tsx  auth.tsx
    features/                 ← کد بر اساس «قابلیت»، نه بر اساس «نوع فایل»
      auth/                   components/ schemas/ + صفحات
      landing/                home/ demo/ support/ content/
      dashboard/
      units/
      residents/
      managers/
      billing/                bills/ my-bills/ charge-rules/ discounts/
      payments/               pay/ review/
      finance/                + good-payers/
      messaging/              messenger/ announcements/
      account/
      profile/
      settings/
      system/                 ← پنل ادمین کل
      tools/                  calculator/ search/
      error/
    shared/                   ← هر چیزی که بیش از یک فیچر استفاده می‌کند
      ui/                     ← کامپوننت‌های پایه (Card, Field, PageState, …)
      layout/                 ← DashboardLayout, Header, Sidebar, …
      common/                 ← Logo, SocialIcons, …
      hooks/                  ← هوک‌های مشترک
      lib/                    ← api, alert, scroll, format, …
      stores/                 ← store‌های zustand
      types/
      constants/              ← images.ts و ثابت‌های مشترک
      config/                 ← brand.ts, navigation.ts
  tests/js/                   ← تست فرانت (بیرون از resources)
```

## قواعد

1. **کد مشترک در `shared/` است، نه در `features/`.** اگر چیزی را دو فیچر لازم دارند، به `shared/` می‌رود.
2. **فیچرها به هم import نمی‌کنند** — استثنای عمدی: `landing/demo` و `landing/support` از `landing/home/components` استفاده می‌کنند (هدر و دکمه‌های شناور مشترک‌اند و همه زیر یک فیچرند).
3. **منطق تکراری → کاستوم‌هوک در `shared/hooks/`.**
4. **ثابت‌ها و تایپ‌ها جدا** (`shared/constants`, `shared/types`) و با import مصرف می‌شوند.
5. `@/` به `resources/js` اشاره می‌کند.

## قاعده‌ی مدیا (پاسخ به «فنی-۳»)

| مقصد                          | چه چیزی                                                                                    | چرا                                                                                                       |
| ----------------------------- | ------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------- |
| `resources/images/` (از Vite) | `hero-building`, `feature-*` (۴), `auth-background`                                        | جزء ظاهر برنامه‌اند و کاربر عوضشان نمی‌کند → Vite نامشان را هش می‌کند، پس کشِ همیشگی ممکن می‌شود          |
| `public/images/` (مسیر ثابت)  | `gallery-*`, `avatar-*`                                                                    | محتوایی که کارفرما با جایگزینیِ فایل عوض می‌کند؛ نباید تغییرِ کد لازم داشته باشد                          |
| `public/images/` (مسیر ثابت)  | `ad-*`                                                                                     | ادمین از پنل آپلود می‌کند و مسیرش در دیتابیس است (کش‌شکنی با `?v=filemtime`)                              |
| `public/` (مسیر ثابت)         | `logo.webp`, `favicon*`, `icons/*`, `og-cover.png`, `hero-building-night.webp`, `videos/*` | آدرسشان از بیرونِ ری‌اکت خوانده می‌شود (manifest، متاتگ‌های og، و `demo.blade.php`) پس نام ثابت می‌خواهند |

## نگاشت قدیم → جدید

| قدیم                                                         | جدید                                                           |
| ------------------------------------------------------------ | -------------------------------------------------------------- |
| `components/ui`                                              | `shared/ui`                                                    |
| `components/layout`                                          | `shared/layout`                                                |
| `components/common`                                          | `shared/common`                                                |
| `hooks`                                                      | `shared/hooks`                                                 |
| `lib`                                                        | `shared/lib`                                                   |
| `types`                                                      | `shared/types`                                                 |
| `config`                                                     | `shared/config`                                                |
| `context/*Context.tsx`                                       | `shared/stores/*Store.ts`                                      |
| `data/images.ts`                                             | `shared/constants/images.ts`                                   |
| `data/landingContent.ts`                                     | `features/landing/content/landingContent.ts`                   |
| `entries/`                                                   | `resources/js/app/entries/`                                    |
| `main.tsx`                                                   | `resources/js/app/main.tsx`                                    |
| `App.tsx`                                                    | **حذف شد** (فقط `<AppRouter/>` را برمی‌گرداند — لایه‌ی بی‌اثر) |
| `pages/auth`                                                 | `features/auth`                                                |
| `pages/home` \| `demo` \| `support`                          | `features/landing/{home,demo,support}`                         |
| `pages/bills` \| `my-bills` \| `charge-rules` \| `discounts` | `features/billing/*`                                           |
| `pages/pay` \| `payments`                                    | `features/payments/{pay,review}`                               |
| `pages/finance` \| `good-payers`                             | `features/finance` \| `features/finance/good-payers`           |
| `pages/messenger` \| `announcements`                         | `features/messaging/*`                                         |
| `pages/calculator` \| `search`                               | `features/tools/*`                                             |
| بقیه‌ی `pages/x`                                             | `features/x`                                                   |

## نام‌گذاری store‌ها

`AuthContext.tsx → authStore.ts` · `ThemeContext.tsx → themeStore.ts` · `SidebarContext.tsx → sidebarStore.ts` · `SearchContext.tsx → searchStore.ts` · `NotificationContext.tsx → notificationStore.ts`

> پسوند `.tsx` به `.ts` تغییر کرد چون این فایل‌ها پس از مهاجرت به zustand دیگر JSX ندارند.
> نامِ هوک‌های عمومی (`useAuth`, `useTheme`, …) عوض **نشده** تا مصرف‌کننده‌ها دست‌نخورده بمانند.

## وضعیت کاستوم‌هوکِ `useMutation`

`shared/hooks/useMutation.ts` ساخته شد و `features/system/MembersPage.tsx` روی آن منتقل شد.
**۷ صفحه‌ی دیگر** که همان الگو را دارند هنوز منتقل نشده‌اند و در **R39** (بهینه‌سازی React) یا هنگام نخستین دست‌زدن به هر صفحه منتقل می‌شوند:
`AccountPage`, `PaymentReviewPage`, `PlansPage`, `SubscriptionsPage`, `SystemBackupPage`, `ComplexBackupPage`, `ForgotPasswordPage`

## تست‌ها (پس از R3)

```bash
npm test              # Vitest (واحد + کامپوننت + یکپارچگی)
npm run test:watch    # حالت تماشا
npm run test:coverage # گزارش پوشش
npm run test:e2e      # Playwright روی سرور واقعی
npm run check         # format + typecheck + lint + test
```

| فایل                               | نوع      | تعداد | چه چیزی                                                                 |
| ---------------------------------- | -------- | ----- | ----------------------------------------------------------------------- |
| `inputFilters.test.ts`             | واحد     | ۱۳    | پالایه‌های ورودی فرم                                                    |
| `authSchemas.test.ts`              | واحد     | ۱۳    | طرح‌های zod (آینه‌ی قواعد سرور)                                         |
| `rememberMe.test.ts`               | واحد     | ۷     | مرزِ دقیقِ انقضای ۱۰ روزه                                               |
| `apiError.test.ts`                 | واحد     | ۵     | نگاشت خطای لاراول به فیلد                                               |
| `ProtectedRoute.test.tsx`          | کامپوننت | ۵     | کنترل دسترسی مسیرها                                                     |
| `LoginForm.test.tsx`               | کامپوننت | ۶     | دروازه‌ی پازل، بوردر قرمز، بازنشانی پازل، منعِ کپیِ رمز                 |
| `RegisterForm.test.tsx`            | یکپارچگی | ۵     | ثبت‌نام دومرحله‌ای: گام اول حساب نمی‌سازد، فقط تاییدِ کد می‌سازد        |
| `tests/e2e/critical-flows.spec.ts` | E2E      | ۱۱    | مهمان/۴۰۱، تصاویر، هشِ Vite، CSRF (۴۱۹)، ورود نادرست، MPA سرورساید، ۴۰۳ |

**تستِ E2E یک باگ واقعی گرفت:** روی صفحه‌ی اصلی خطای کنسولِ
`<ellipse> attribute rx: Expected length, "undefined"` رخ می‌داد، چون
framer-motion مقدار `rx` را مثل ویژگیِ style مدیریت می‌کند ولی روی `<ellipse>`
یک attribute است و بدون `initial` مقدارِ شروع `undefined` می‌شد. با افزودن
`initial={{ rx: 44 }}` در `CtaMascot` رفع شد.

## لایه‌ی API (پس از R5)

چهار فایل با مرزهای صریح. ترتیبِ لایه‌ها از بیرون به درون:

```
api()                    ← نمای عمومی؛ ۴۲ فایل فقط همین را می‌شناسند
  └─ dedupe.ts           فقط GET — درخواست‌های هم‌زمانِ یکسان یکی می‌شوند
      └─ retry.ts        backoff نمایی + jitter + احترام به Retry-After
          └─ (csrf)      ۴۱۹ ⇒ یک‌بار توکن نو + یک تلاشِ دوباره
              └─ http.ts نمونه‌ی axios: آدرس پایه، کوکی، هدر، timeout
```

| فایل          | مسئولیت                                      | چه چیزی **نباید** اینجا باشد |
| ------------- | -------------------------------------------- | ---------------------------- |
| `http.ts`     | نمونه‌ی axios، توکن CSRF، timeout            | هیچ منطقِ اپ یا تفسیرِ خطا   |
| `apiError.ts` | `ApiError`، `isRetryable`، `parseRetryAfter` | وابستگی به axios یا React    |
| `retry.ts`    | `backoffDelay`، `sleep`ِ لغوشدنی             | دانش از HTTP                 |
| `dedupe.ts`   | اشتراکِ درخواستِ در جریان با مرجع‌شماری      | دانش از HTTP                 |
| `api.ts`      | چیدنِ لایه‌ها + نگاشتِ پاسخ/خطا              | جزئیاتِ حمل‌ونقل             |

**قاعده‌های تصمیم (چرا این‌طور و نه جور دیگر):**

- **تلاشِ دوباره فقط روی متدِ idempotent.** یک `POST /payments` که timeout شده
  ممکن است روی سرور **انجام شده باشد**؛ تکرارش پرداختِ دوم می‌سازد. پس
  POST/PATCH هرگز خودکار تکرار نمی‌شوند.
- **۴۲۲/۴۰۱/۴۰۳/۴۰۴ هرگز تکرار نمی‌شوند.** جوابشان عوض نمی‌شود و فقط سرور را
  سه برابر می‌زنند. (این همان جایی است که پیش‌فرضِ TanStack Query غلط است و در
  R6 با همین `isRetryable` اصلاح می‌شود.)
- **۴۱۹ از فهرست retry بیرون است**، چون مکانیزمِ جبرانش تازه‌کردنِ توکن است.
  اگر هر دو فعال بودند روی هم می‌افتادند.
- **jitter اجباری است، نه تزئینی.** بدونش، ۵۰ کلاینتی که هم‌زمان خطا گرفته‌اند
  هم‌زمان برمی‌گردند و همان موجِ کوبنده تکرار می‌شود.
- **dedupe فقط GET.** دو `POST` یکسان ممکن است واقعاً دو قصدِ جدا باشند.
- **dedupe مرجع‌شماری دارد.** با اشتراکِ سرراستِ promise، unmountِ یک کامپوننت
  درخواستِ مشترک را می‌کشت و کامپوننتِ دیگری که هنوز روی صفحه بود خطای لغو
  می‌گرفت. الان درخواستِ زیرین فقط با رفتنِ **آخرین** مصرف‌کننده لغو می‌شود.
- **`isRetryable` صادراتِ عمومی است** تا R6 سیاستِ retryِ TanStack Query را از
  همین یک منبع بگیرد و دو سیاستِ واگرا نداشته باشیم.

**تست:** ۵۸ تستِ تازه (`retry.test.ts` ۳۶، `dedupe.test.ts` ۸، `api.test.ts` ۱۴)
— مجموع تست‌های فرانت ۵۴ → ۱۱۲.

## لایه‌ی کش — TanStack Query (R6a)

```
shared/lib/queryClient.ts   پیکربندی + errorMessage()
shared/lib/queryKeys.ts     کارخانه‌ی کلید (قرارداد تیمی)
shared/hooks/useAction.ts   لایه‌ی رویی: تایید + توست + ابطالِ کش
tests/js/helpers/renderWithQuery.tsx
```

### مرزِ مسئولیت‌ها (مهم‌ترین بخشِ این مرحله)

| لایه                | مالکِ چیست                                        |
| ------------------- | ------------------------------------------------- |
| `shared/lib/*` (R5) | شبکه: تلاشِ دوباره، backoff، abort، CSRF، timeout |
| TanStack Query      | کش، ابطال، همگام‌سازی                             |
| zustand             | حالتِ کلاینت: تم، سایدبار، تاریخچه‌ی جست‌وجو      |

**`retry` در TanStack Query خاموش است — و این مهم‌ترین تصمیمِ R6 است.**
پیش‌فرضش سه تلاش روی هر خطاست و اگر می‌ماند، روی سه تلاشِ لایه‌ی حمل‌ونقل سوار
می‌شد و **۳ × ۳ = ۹ درخواست** برای یک خطای ساده می‌رفت. سیاستِ retry همچنان یک
منبع دارد (`isRetryable`)؛ اینجا صرفاً به آن واگذار می‌شود.

### تصمیم‌های دیگر

- **`refetchOnWindowFocus: false`** + `staleTime: 30s`. مدیرِ ساختمان چند تب باز
  دارد؛ پیش‌فرضِ روشن یعنی رگبارِ درخواست با هر بار برگشتن به تب.
- **`QueryClientProvider` فقط در `resources/js/app/main.tsx`.** راستی‌آزمایی شد: کدِ TanStack
  فقط در چانکِ `main-*.js` است و در `home/demo/support/auth` نیست.
- **هوکِ ما `useAction` نام گرفت** (پیش‌تر `useMutation`) چون `useMutation` نامِ
  هوکِ خودِ TanStack است و دو چیزِ متفاوت با یک نام برای تازه‌واردها تله است.
- **`onDone: reload` جای خود را به `invalidate: [key]` داد.** فرقش این است که
  ابطال، **هر** مصرف‌کننده‌ی آن داده را در هر گوشه‌ی برنامه تازه می‌کند، نه فقط
  صفحه‌ای که دکمه در آن بود.

### هزینه

**+۱۱٫۰ کیلوبایت gzip** (۵۰۶٫۹ → ۵۱۷٫۹)، فقط روی باندلِ داشبورد.

### وضعیت مهاجرت

| صفحه            | وضعیت                              |
| --------------- | ---------------------------------- |
| `DashboardPage` | ✅                                 |
| `MembersPage`   | ✅ (خواندن + سه mutation با ابطال) |
| ۲۴ صفحه‌ی دیگر  | ❌ — R6b                           |

`shared/hooks/useApi.ts` تا پایانِ R6b سرِ جایش می‌ماند و بعد حذف می‌شود.

## حالت‌های رابط و مدیریتِ کرش (R7)

```
shared/ui/ErrorBoundary.tsx   دیوارِ آتش + نمای ریشه + تشخیصِ چانکِ کهنه
shared/ui/Skeleton.tsx        چهار اسکلتونِ هم‌شکل با محتوا
shared/ui/PageState.tsx       ErrorState / EmptyState / InlineSpinner
```

### جای دیوارهای آتش (چرا دو تا)

| جا                                 | نقش                                                   |
| ---------------------------------- | ----------------------------------------------------- |
| `resources/js/app/main.tsx` (ریشه) | تورِ آخر؛ اگر حتی پوسته بالا نیامد، صفحه‌ی سفید ندهیم |
| `DashboardLayout` (دورِ `Outlet`)  | کرشِ یک صفحه، سایدبار و هدر را نکشد                   |

**`resetKey={pathname}` اجباری است، نه تزئینی.** بدونش boundary در حالتِ خطا
گیر می‌کند و کاربر هر صفحه‌ای برود همان خطا را می‌بیند. در مرورگرِ واقعی آزموده
شد: با کرشِ عمدی در `/units`، رفتن به `/residents` خطا را پاک کرد.

### Suspense داخلِ پوسته، نه فقط در ریشه

پیش از این تنها یک `Suspense` در ریشه بود؛ یعنی رفتن به هر صفحه‌ی تازه، تا
دانلودِ چانکش، **کلِ پوسته را** با اسپینرِ تمام‌صفحه جایگزین می‌کرد. حالا فقط
ناحیه‌ی محتوا عوض می‌شود.

### چانکِ کهنه پس از انتشار (یافته‌ی راستی‌آزمایی)

هنگام تستِ همین مرحله در مرورگر، پس از یک بیلدِ تازه، تبِ باز خطای
`Failed to fetch dynamically imported module` داد — چون نامِ فایل‌ها هش دارد و
فایلِ قبلی دیگر روی سرور نیست. این دقیقاً همان چیزی است که کاربرِ واقعی پس از
هر انتشار می‌بیند.

اینجا «تلاش دوباره» بی‌فایده است (همان آدرسِ ناموجود دوباره خواسته می‌شود)، پس
boundary این حالت را تشخیص می‌دهد و به‌جایش پیامِ «نسخه‌ی تازه‌ای منتشر شده» با
دکمه‌ی **بارگذاری دوباره** نشان می‌دهد.

### اسکلتون‌ها

| کامپوننت            | برای             |
| ------------------- | ---------------- |
| `TableSkeleton`     | ۱۳ صفحه‌ی فهرستی |
| `CardListSkeleton`  | ۶ صفحه‌ی کارتی   |
| `FormSkeleton`      | ۵ صفحه‌ی فرمی    |
| `DashboardSkeleton` | داشبورد و مالی   |

قاعده: ابعادِ اسکلتون باید نزدیکِ محتوای واقعی باشد تا با رسیدنِ داده صفحه
**نپرد**. یک بلوکِ عمومی برای همه‌ی صفحه‌ها همان مشکلِ پرشِ چیدمان را دارد.

**دسترس‌پذیری:** وضعیت _یک بار_ با `role="status"` اعلام می‌شود و همه‌ی بلوک‌های
خاکستری `aria-hidden`اند؛ وگرنه صفحه‌خوان ده‌ها عنصرِ بی‌معنا می‌خواند.
