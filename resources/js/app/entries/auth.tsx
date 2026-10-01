import { StrictMode } from 'react'
import { MotionConfig } from 'framer-motion'
import { createRoot } from 'react-dom/client'
import { initObservability, trackPageView } from '@/shared/lib/observability'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AuthPage } from '@/features/auth/AuthPage'
import { VerifyOtpPage } from '@/features/auth/VerifyOtpPage'
import { ForgotPasswordPage } from '@/features/auth/ForgotPasswordPage'

/**
 * Entryِ بخشِ ورود/ثبت‌نام (island).
 *
 * برخلاف خانه/دمو/پشتیبانی، این island یک روترِ کوچک دارد چون جریانِ
 * دومرحله‌ای سه گام است (/auth ، /auth/verify ، /auth/forgot) و باید بینشان
 * حالت (شماره و کد) رد شود؛ این ناوبریِ داخلی سمتِ کلاینت است. رفتن به
 * داشبورد یا خانه اما ناوبریِ واقعیِ مرورگر است (سندِ جدا).
 */
/*
 * این صفحه یک سندِ مستقل است، پس بازدیدش همین‌جا یک بار ثبت می‌شود
 * (برخلافِ SPA که ناوبریِ داخلی دارد و در روتر ثبت می‌کند).
 */
initObservability()
trackPageView(window.location.pathname)

/*
 * ⚠️ بازگشت از bfcache (مرحلهٔ ۷ · فاز ۱).
 *
 * سرور این صفحه را `no-store` می‌فرستد تا مرورگر نسخه‌ی کهنه‌اش را نگه
 * ندارد، ولی همه‌ی مرورگرها به آن پایبند نیستند. نسخه‌ای که از bfcache
 * برمی‌گردد هنوز فکر می‌کند کاربر مهمان است و فرمِ ورود را نشان می‌دهد؛
 * بارگذاریِ دوباره از سرور می‌پرسد، و سرور کاربرِ واردشده را به داشبورد
 * می‌فرستد.
 */
window.addEventListener('pageshow', (event) => {
  if (event.persisted) window.location.reload()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {/*
        ⚠️ `reducedMotion="user"` تنها راهی است که انیمیشن‌های framer-motion
        به تنظیمِ سیستمیِ «کاهش حرکت» احترام بگذارند. سه بلوکِ
        `prefers-reduced-motion` در CSS هرکدام فقط یک انیمیشنِ خاص را
        می‌گرفتند؛ `motion.div`ها از همه‌شان رد می‌شدند.
      */}
    <MotionConfig reducedMotion="user">
      <BrowserRouter>
        <Routes>
          <Route path="/auth" element={<AuthPage />} />
          <Route path="/auth/verify" element={<VerifyOtpPage />} />
          <Route path="/auth/forgot" element={<ForgotPasswordPage />} />
        </Routes>
      </BrowserRouter>
    </MotionConfig>
  </StrictMode>,
)
