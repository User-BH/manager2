import { StrictMode, Suspense, lazy } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import { MotionConfig } from 'framer-motion'

import { onMaintenance, onReauthRequired, onSessionEnded } from '@/shared/lib/api'
import { http } from '@/shared/lib/http'
import { watchServerContext } from '@/shared/lib/serverContext'
import { contextMismatch, enterNewContext } from '@/shared/lib/accountContext'
import { listenForAuthChanges } from '@/shared/lib/authChannel'
import { leaveToAuth } from '@/shared/lib/leaveToAuth'
import { initObservability, reportError } from '@/shared/lib/observability'
import { createQueryClient } from '@/shared/lib/queryClient'
import { useAuthStore } from '@/shared/stores/authStore'
import { requestReauth } from '@/shared/stores/reauthStore'
import { ErrorBoundary, RootErrorFallback } from '@/shared/ui/ErrorBoundary'
import { ConnectionBanner } from '@/shared/pwa/ConnectionBanner'
import { ReauthHost } from '@/shared/ui/ReauthHost'
import { PwaPrompts } from '@/shared/pwa/PwaPrompts'
import { AppRouter } from './AppRouter'

/*
 * نقطه‌ی ورودِ داشبوردِ SPA.
 *
 * تم و احراز هویت store‌های zustand‌اند و به Provider نیاز ندارند؛ خودِ
 * import‌شدنِ store، وضعیتِ نشست را از سرور می‌گیرد.
 *
 * پیش از این یک `App.tsx` واسط وجود داشت که فقط `<AppRouter />` را برمی‌گرداند؛
 * آن لایه‌ی بی‌اثر حذف شد (YAGNI) و روتر مستقیم mount می‌شود.
 *
 * `QueryClientProvider` عمداً **فقط اینجاست** و در جزیره‌های MPA
 * (`entries/home`, `demo`, `support`, `auth`) نیست. آن صفحه‌ها کشِ سمتِ سرور
 * ندارند و افزودنش فقط باندلِ صفحه‌ی اصلی را سنگین می‌کرد — همان صفحه‌ای که
 * سرعتش برای SEO مهم است.
 *
 * کلاینت در سطحِ ماژول ساخته می‌شود، نه داخلِ کامپوننت: این نقطه یک بار در
 * عمرِ صفحه اجرا می‌شود، پس `useState` لازم نیست.
 */
/*
 * پایش پیش از رندر راه می‌افتد تا خطای خودِ رندرِ اول هم گرفته شود.
 * اگر هیچ شناسه‌ای تنظیم نشده باشد این فراخوانی عملاً بی‌اثر است.
 */
initObservability()

/*
 * حالتِ تعمیر: پوسته‌ی داشبورد را سرور در این حالت نمی‌دهد، پس بارگذاریِ
 * دوباره صفحه‌ی ۵۰۳ِ فارسی را می‌آورد و حلقه‌ای نمی‌سازد.
 */
onMaintenance(() => window.location.reload())

/*
 * چرخه‌ی نشست (مرحلهٔ ۵ · فاز ۱۰) — مثلِ حالتِ تعمیر فقط در داشبورد.
 *
 * - ۴۰۱ یعنی سرور نشست را بسته (سقفِ عمر، «خروج از همه‌جا» از دستگاهِ دیگر،
 *   تغییرِ رمز): کش پاک و کاربر به ورود برده می‌شود.
 * - `auth.reauth_required` پنجره‌ی رمز را باز می‌کند و درخواست پس از تأیید
 *   دوباره می‌رود.
 * - خروج در یک تب، تب‌های دیگر را هم بیرون می‌برد؛ ورود با حسابِ دیگر در
 *   تبِ دیگر، این تب را از نو بار می‌کند تا داده‌ی نفرِ قبلی نماند.
 */
onSessionEnded((reason) => void useAuthStore.getState().expire(reason))
// زمینه‌ی نشست را سرور جابه‌جا کرد (پایان یا تعلیقِ عضویت، تأییدِ درخواست): ورودِ کامل، نه دادهٔ قاطی (۱۲/۱)
watchServerContext(http, (context) => {
  if (contextMismatch(context, useAuthStore.getState().user)) void enterNewContext()
})
onReauthRequired(requestReauth)
listenForAuthChanges((message) => {
  if (message.type === 'logout') {
    leaveToAuth()
    return
  }

  // مجتمعِ فعالِ حساب در تبِ دیگری عوض شد: هیچ دادهٔ مجتمعِ قبلی در این تب نماند
  if (message.type === 'context') {
    window.location.reload()
    return
  }

  const current = useAuthStore.getState().user
  if (current && current.id !== message.userId) window.location.reload()
})

const queryClient = createQueryClient()

/*
 * ابزارِ توسعه‌ی TanStack Query — فقط در حالتِ توسعه.
 *
 * `import.meta.env.DEV` را Vite هنگام بیلد به `false` تبدیل می‌کند و کلِ این
 * شاخه به‌همراه خودِ پکیج از باندلِ محصول حذف می‌شود (tree-shaking). پس هزینه‌ی
 * تولیدش صفر است — راستی‌آزمایی شد.
 */
const DevTools = import.meta.env.DEV
  ? lazy(() =>
      import('@tanstack/react-query-devtools').then((m) => ({ default: m.ReactQueryDevtools })),
    )
  : null

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {/*
      دیوارِ آتشِ ریشه — تورِ ایمنیِ آخر.
      اگر خطا حتی پیش از بالا آمدنِ پوسته رخ دهد (مثلاً در خودِ روتر)، کاربر
      به‌جای صفحه‌ی سفید یک پیامِ قابل‌فهم و دکمه‌ی بارگذاری دوباره می‌بیند.
      دیوارِ درونِ داشبورد (در AppRouter) پیش از این یکی عمل می‌کند و پوسته را
      زنده نگه می‌دارد؛ این یکی فقط وقتی به کار می‌آید که آن هم از دست رفته باشد.
    */}
    <ErrorBoundary onError={reportError} fallback={(error) => <RootErrorFallback error={error} />}>
      {/*
        ⚠️ `reducedMotion="user"` تنها راهی است که انیمیشن‌های framer-motion
        به تنظیمِ سیستمیِ «کاهش حرکت» احترام بگذارند.
      */}
      <MotionConfig reducedMotion="user">
        <QueryClientProvider client={queryClient}>
          <BrowserRouter>
            <AppRouter />
          </BrowserRouter>
          {/*
          ثبتِ service worker و دو پیامِ کوچکش.

          بیرونِ `BrowserRouter` است چون به مسیر کاری ندارد، و **درونِ**
          دیوارِ آتش چون اگر خودش بترکد نباید داشبورد را ببرد.
        */}
          {/*
          نشانگرِ وضعیتِ اتصال (R62).

          ⚠️ بیرونِ `BrowserRouter` و کنارِ `PwaPrompts`: به مسیر کاری
          ندارد و باید در هر صفحه‌ای دیده شود — از جمله صفحه‌ای که خودش
          به‌خاطرِ قطعی خالی مانده.
        */}
          <ConnectionBanner />
          <PwaPrompts />
          <ReauthHost />
          {DevTools && (
            <Suspense fallback={null}>
              <DevTools initialIsOpen={false} buttonPosition="bottom-left" />
            </Suspense>
          )}
        </QueryClientProvider>
      </MotionConfig>
    </ErrorBoundary>
  </StrictMode>,
)
