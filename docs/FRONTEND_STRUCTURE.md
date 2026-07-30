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
| `entries/`                                                   | `app/entries/`                                                 |
| `main.tsx`                                                   | `app/main.tsx`                                                 |
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
npm test              # ۵۴ تست Vitest (واحد + کامپوننت + یکپارچگی)
npm run test:watch    # حالت تماشا
npm run test:coverage # گزارش پوشش
npm run test:e2e      # ۱۱ تست Playwright روی سرور واقعی
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
