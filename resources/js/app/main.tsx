import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'

import { createQueryClient } from '@/shared/lib/queryClient'
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

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AppRouter />
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)
