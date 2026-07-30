import { StrictMode, Suspense, lazy } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'

import { createQueryClient } from '@/shared/lib/queryClient'
import { ErrorBoundary, RootErrorFallback } from '@/shared/ui/ErrorBoundary'
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
    <ErrorBoundary fallback={(error) => <RootErrorFallback error={error} />}>
      <QueryClientProvider client={queryClient}>
        <BrowserRouter>
          <AppRouter />
        </BrowserRouter>
        {DevTools && (
          <Suspense fallback={null}>
            <DevTools initialIsOpen={false} buttonPosition="bottom-left" />
          </Suspense>
        )}
      </QueryClientProvider>
    </ErrorBoundary>
  </StrictMode>,
)
