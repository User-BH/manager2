import { StrictMode } from 'react'
import { MotionConfig } from 'framer-motion'
import { createRoot } from 'react-dom/client'
import { initObservability, trackPageView } from '@/shared/lib/observability'
import { DemoPage } from '@/features/landing/demo/DemoPage'

/** Entryِ صفحه‌ی دمو (island). تم store‌ی zustand است و Provider نمی‌خواهد. */
/*
 * این صفحه یک سندِ مستقل است، پس بازدیدش همین‌جا یک بار ثبت می‌شود
 * (برخلافِ SPA که ناوبریِ داخلی دارد و در روتر ثبت می‌کند).
 */
initObservability()
trackPageView(window.location.pathname)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {/*
        ⚠️ `reducedMotion="user"` تنها راهی است که انیمیشن‌های framer-motion
        به تنظیمِ سیستمیِ «کاهش حرکت» احترام بگذارند.

        سه بلوکِ `prefers-reduced-motion` در CSS از قبل بود ولی هرکدام فقط
        یک انیمیشنِ خاص را می‌گرفت؛ `motion.div`ها که در کلِ پروژه‌اند از
        همه‌شان رد می‌شدند. این یک خط، همه را با هم می‌گیرد.
      */}
    <MotionConfig reducedMotion="user">
      <DemoPage />
    </MotionConfig>
  </StrictMode>,
)
