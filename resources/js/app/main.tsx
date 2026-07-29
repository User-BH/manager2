import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { AppRouter } from './AppRouter'

/*
 * نقطه‌ی ورودِ داشبوردِ SPA.
 *
 * تم و احراز هویت store‌های zustand‌اند و به Provider نیاز ندارند؛ خودِ
 * import‌شدنِ store، وضعیتِ نشست را از سرور می‌گیرد.
 *
 * پیش از این یک `App.tsx` واسط وجود داشت که فقط `<AppRouter />` را برمی‌گرداند؛
 * آن لایه‌ی بی‌اثر حذف شد (YAGNI) و روتر مستقیم mount می‌شود.
 */
createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <AppRouter />
    </BrowserRouter>
  </StrictMode>,
)
